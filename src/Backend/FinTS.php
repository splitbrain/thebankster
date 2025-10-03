<?php

namespace splitbrain\TheBankster\Backend;

use splitbrain\TheBankster\Container;
use splitbrain\TheBankster\Entity\FinTsState;
use splitbrain\TheBankster\Entity\Transaction;
use splitbrain\TheBankster\Exception\FinTsAuthenticationExpiredException;
use splitbrain\TheBankster\Exception\FinTsTanRequiredException;

/**
 * Class FinTS
 *
 * Uses the FinTS protocol to access bank transactions
 * Now using nemiah/php-fints library (mschindler83/fints-hbci-php is deprecated)
 *
 * @package splitbrain\TheBankster\Backend
 */
class FinTS extends AbstractBackend
{
    protected $fints;
    protected $fintsState;
    protected $autoRenewalAttempted = false;

    public function __construct($config, $accountid)
    {
        parent::__construct($config, $accountid);

        // Load FinTS state from database
        $this->fintsState = $this->getFinTsState();

        // Extract bank code from URL or use separate code field for backwards compatibility
        $bankCode = $this->config['code'] ?? null;
        if (!$bankCode && isset($this->config['url'])) {
            // Try to extract from URL if not provided separately
            $bankCode = $this->config['code'] ?? '';
        }

        // Initialize FinTS options
        $options = new \Fhp\Options\FinTsOptions();
        $options->url = $this->config['url'];
        $options->bankCode = $bankCode;
        $options->productName = 'FF5FB8B02F2BAAE9FE52FD96C'; // Registration ID
        $options->productVersion = '1.0';

        // Create credentials
        $credentials = \Fhp\Options\Credentials::create(
            $this->config['user'],
            $this->config['pass']
        );

        // Load persisted state if available
        $persistedState = null;
        if ($this->fintsState && $this->fintsState->persisted_state) {
            $persistedState = $this->fintsState->persisted_state;
        }

        // Create FinTS instance
        $this->fints = \Fhp\FinTs::new($options, $credentials, $persistedState);
        $this->fints->setLogger($this->logger);

        // Select TAN mode if configured
        if ($this->fintsState && $this->fintsState->tan_mode) {
            // Check if NoPsd2TanMode was selected (ID -1)
            if ($this->fintsState->tan_mode === '-1' || $this->fintsState->tan_mode === -1) {
                $this->fints->selectTanMode(new \Fhp\Model\NoPsd2TanMode());
            } else {
                $this->fints->selectTanMode(
                    $this->fintsState->tan_mode,
                    $this->fintsState->tan_medium
                );
            }
        }

        // Proactive renewal: Check if authentication has expired and auto-renew if possible
        if ($this->fintsState && $this->needsReAuthentication() && $this->canAutoRenew()) {
            $this->logger->info('Expired authentication detected on initialization, attempting auto-renewal for account {account}', [
                'account' => $this->accountid
            ]);

            if (!$this->attemptAutoRenewal()) {
                // Auto-renewal failed, throw exception
                $daysExpired = -$this->fintsState->getDaysUntilExpiry();
                throw new FinTsAuthenticationExpiredException($this->accountid, $daysExpired);
            }
        }
    }

    /** @inheritdoc */
    public static function configDescription()
    {
        return [
            'url' => [
                'help' => 'The HBCI / FinTS API URL for your bank. See https://www.hbci-zka.de/institute/institut_select.php',
            ],
            'code' => [
                'help' => 'Your bank code (aka Bankleitzahl)',
            ],
            'user' => [
                'help' => 'Your online banking username / alias',
            ],
            'pass' => [
                'help' => 'Your online banking PIN (NOT! the pin of your bank card!)',
                'type' => 'password',
            ],
            'ident' => [
                'optional' => true,
                'help' => 'Optional. Your account number if the credentials above give access to multiple accounts',
            ],
        ];
    }

    /** @inheritdoc */
    public function checkSetup()
    {
        // Check if TAN mode is configured
        if (!$this->fintsState || !$this->fintsState->isConfigured()) {
            throw new \Exception(
                'FinTS account not yet configured. Please complete the TAN mode setup via the web interface.'
            );
        }

        // Try to identify account (with auto-renewal)
        $account = $this->identifyAccount();

        // Persist state after successful operation
        $this->persistState();

        return 'Connected successfully to account ' . $account->getAccountNumber();
    }

    /**
     * Get the account to use
     *
     * @return \Fhp\Model\SEPAAccount
     * @throws FinTsTanRequiredException
     */
    protected function identifyAccount()
    {
        return $this->executeWithAutoRenewal(function() {
            // Create and execute GetSEPAAccounts action
            $getSepaAccounts = \Fhp\Action\GetSEPAAccounts::create();
            $this->fints->execute($getSepaAccounts);

            // Check if TAN is required
            if ($getSepaAccounts->needsTan()) {
                throw new FinTsTanRequiredException($this->accountid, $getSepaAccounts->getTanRequest());
            }

            $accounts = $getSepaAccounts->getAccounts();
            $this->logger->info('Found {count} accounts.', ['count' => count($accounts)]);

            $account = $accounts[0];

            // Use identifier when multiple accounts are available
            if (isset($this->config['ident'])) {
                foreach ($accounts as $acc) {
                    if (
                        (strpos($acc->getAccountNumber(), $this->config['ident']) !== false) ||
                        (strpos($acc->getIban(), $this->config['ident']) !== false)
                    ) {
                        $account = $acc;
                        break;
                    }
                }
            }

            return $account;
        });
    }

    /** @inheritdoc */
    public function importTransactions(\DateTime $since)
    {
        // Check if authentication has expired
        if ($this->needsReAuthentication()) {
            $daysExpired = $this->fintsState ? -$this->fintsState->getDaysUntilExpiry() : 0;
            throw new FinTsAuthenticationExpiredException($this->accountid, $daysExpired);
        }

        // Get the account to use (with auto-renewal)
        $account = $this->identifyAccount();

        $today = new \DateTime();
        $today->setTime(0, 0, 0);

        // Execute transaction fetching with auto-renewal
        $this->executeWithAutoRenewal(function() use ($account, $since, $today) {
            // Get all the transactions using GetStatementOfAccount action
            $getStatement = \Fhp\Action\GetStatementOfAccount::create($account, $since, new \DateTime(), false, true);
            $this->fints->execute($getStatement);

            // Check if TAN is required
            if ($getStatement->needsTan()) {
                throw new FinTsTanRequiredException($this->accountid, $getStatement->getTanRequest());
            }

            $soa = $getStatement->getStatement();

            foreach ($soa->getStatements() as $statement) {
                foreach ($statement->getTransactions() as $fintrans) {
                    $amount = $fintrans->getAmount();
                    if ($fintrans->getCreditDebit() == \Fhp\Model\StatementOfAccount\Transaction::CD_DEBIT) {
                        $amount *= -1;
                    }

                    $tx = new Transaction();
                    $tx->datetime = $fintrans->getBookingDate();
                    $tx->amount = $amount;

                    // Build description from available fields
                    $descParts = array_filter([
                        $fintrans->getMainDescription(),
                        $fintrans->getBookingText(),
                    ]);
                    $tx->description = join("\n", $descParts);

                    // Get counterparty information
                    $tx->xName = $fintrans->getName();
                    $tx->xBank = $fintrans->getBankCode() ?? '';
                    $tx->xAcct = $fintrans->getAccountNumber() ?? '';

                    if ($tx->datetime > $today) {
                        $this->logger->warning('Skipping future transaction ' . ((string) $tx));
                        continue;
                    }

                    if ($tx->datetime < $since) {
                        $this->logger->warning('Skipping too old transaction ' . ((string) $tx));
                        continue;
                    }

                    $this->storeTransaction($tx);
                }
            }

            // Persist FinTS state after successful operation
            $this->persistState();
        });
    }

    /**
     * Get FinTS state for current account
     *
     * @return FinTsState|null
     */
    protected function getFinTsState()
    {
        $container = Container::getInstance();
        return $container->db->fetch(FinTsState::class)
            ->where('account', '=', $this->accountid)
            ->one();
    }

    /**
     * Check if re-authentication is needed
     *
     * @return bool
     */
    protected function needsReAuthentication()
    {
        if (!$this->fintsState) {
            return true;
        }

        return $this->fintsState->isExpired();
    }

    /**
     * Persist FinTS state to database
     *
     * @param bool $updateAuthTimestamp Whether to update authentication timestamp
     */
    protected function persistState($updateAuthTimestamp = false)
    {
        if (!$this->fintsState) {
            // Create new state record
            $this->fintsState = new FinTsState();
            $this->fintsState->account = $this->accountid;
        }

        // Persist FinTS instance state
        $this->fintsState->persisted_state = $this->fints->persist();

        // Update auth timestamp if requested
        if ($updateAuthTimestamp) {
            $now = new \DateTime();
            $this->fintsState->setLastAuthFromDateTime($now);

            $expires = clone $now;
            $expires->modify('+90 days');
            $this->fintsState->setAuthExpiresFromDateTime($expires);

            // Reset warning level
            $this->fintsState->warning_level = 0;
            $this->fintsState->last_warning_sent = null;
        }

        $this->fintsState->save();
    }

    /**
     * Mark authentication as expired/invalid
     * Called when FinTS errors indicate the dialog is no longer valid
     */
    protected function markAuthenticationExpired()
    {
        if (!$this->fintsState) {
            return;
        }

        // Set expiry to now to force re-authentication
        $now = new \DateTime();
        $this->fintsState->setAuthExpiresFromDateTime($now);
        $this->fintsState->save();

        $this->logger->warning('Marked authentication as expired for account {account}', [
            'account' => $this->accountid
        ]);
    }

    /**
     * Check if an exception indicates invalid/expired authentication
     *
     * @param \Exception $e
     * @return bool
     */
    protected function isAuthenticationError(\Exception $e)
    {
        $message = $e->getMessage();

        // Common FinTS error codes for invalid/expired dialog
        $authErrors = [
            '9010', // Ungültige Dialogkennung
            '9120', // Dialog bereits beendet oder abgebrochen
            '9800', // Der Dialog wurde abgebrochen
        ];

        foreach ($authErrors as $errorCode) {
            if (strpos($message, $errorCode) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update FinTS state with authentication and TAN mode info
     * Called from FinTsSetupController after successful authentication
     *
     * @param string $tanMode
     * @param string|null $tanMedium
     * @param string $persistedState
     */
    public function updateFinTsState($tanMode, $tanMedium, $persistedState)
    {
        if (!$this->fintsState) {
            $this->fintsState = new FinTsState();
            $this->fintsState->account = $this->accountid;
        }

        $this->fintsState->tan_mode = $tanMode;
        $this->fintsState->tan_medium = $tanMedium;
        $this->fintsState->persisted_state = $persistedState;

        // Set authentication timestamps
        $now = new \DateTime();
        $this->fintsState->setLastAuthFromDateTime($now);

        $expires = clone $now;
        $expires->modify('+90 days');
        $this->fintsState->setAuthExpiresFromDateTime($expires);

        // Reset warning
        $this->fintsState->warning_level = 0;
        $this->fintsState->last_warning_sent = null;

        $this->fintsState->save();
    }

    /**
     * Check if auto-renewal is possible
     *
     * @return bool
     */
    protected function canAutoRenew()
    {
        // Must have FinTS state
        if (!$this->fintsState) {
            return false;
        }

        // Must use NoPsd2TanMode (ID -1)
        if ($this->fintsState->tan_mode !== '-1' && $this->fintsState->tan_mode !== -1) {
            return false;
        }

        // Must not have already attempted renewal in this instance
        if ($this->autoRenewalAttempted) {
            return false;
        }

        return true;
    }

    /**
     * Attempt to automatically renew authentication
     *
     * @return bool True if renewal succeeded, false otherwise
     */
    protected function attemptAutoRenewal()
    {
        // Mark that we've attempted renewal to prevent infinite loops
        $this->autoRenewalAttempted = true;

        $this->logger->info('Attempting auto-renewal for account {account}', [
            'account' => $this->accountid
        ]);

        try {
            // Create fresh FinTS instance without persisted state
            $options = new \Fhp\Options\FinTsOptions();
            $options->url = $this->config['url'];
            $options->bankCode = $this->config['code'] ?? '';
            $options->productName = 'FF5FB8B02F2BAAE9FE52FD96C';
            $options->productVersion = '1.0';

            $credentials = \Fhp\Options\Credentials::create(
                $this->config['user'],
                $this->config['pass']
            );

            $freshFints = \Fhp\FinTs::new($options, $credentials);

            // Select TAN mode
            $freshFints->selectTanMode(new \Fhp\Model\NoPsd2TanMode());

            // Attempt login
            $login = $freshFints->login();

            if ($login->needsTan()) {
                $this->logger->warning('Auto-renewal failed: TAN required for account {account}', [
                    'account' => $this->accountid
                ]);
                return false;
            }

            // Success! Update state with new authentication
            $this->updateFinTsState(
                $this->fintsState->tan_mode,
                $this->fintsState->tan_medium,
                $freshFints->persist()
            );

            // Reload state from database
            $this->fintsState = $this->getFinTsState();

            // Reinitialize current FinTS instance with new persisted state
            $this->fints = \Fhp\FinTs::new($options, $credentials, $this->fintsState->persisted_state);
            $this->fints->setLogger($this->logger);
            $this->fints->selectTanMode(new \Fhp\Model\NoPsd2TanMode());

            // Reset the flag to allow future renewals if needed
            $this->autoRenewalAttempted = false;

            $this->logger->info('Auto-renewal successful for account {account}', [
                'account' => $this->accountid
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Auto-renewal failed for account {account}: {error}', [
                'account' => $this->accountid,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Execute a FinTS operation with automatic renewal on auth errors
     *
     * @param callable $operation The operation to execute
     * @return mixed The result of the operation
     * @throws \Exception
     */
    protected function executeWithAutoRenewal(callable $operation)
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            // Check if this is an authentication error
            if ($this->isAuthenticationError($e)) {
                $this->logger->warning('Authentication error detected for account {account}', [
                    'account' => $this->accountid
                ]);

                // Mark as expired
                $this->markAuthenticationExpired();

                // Try auto-renewal if possible
                if ($this->canAutoRenew()) {
                    $this->logger->info('Attempting auto-renewal after authentication error for account {account}', [
                        'account' => $this->accountid
                    ]);

                    if ($this->attemptAutoRenewal()) {
                        // Retry the operation after successful renewal
                        $this->logger->info('Retrying operation after successful auto-renewal for account {account}', [
                            'account' => $this->accountid
                        ]);
                        return $operation();
                    } else {
                        $this->logger->error('Auto-renewal failed, cannot retry operation for account {account}', [
                            'account' => $this->accountid
                        ]);
                    }
                }
            }

            // Re-throw the exception
            throw $e;
        }
    }
}
