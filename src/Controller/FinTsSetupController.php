<?php

namespace splitbrain\TheBankster\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use splitbrain\TheBankster\Backend\FinTS;
use splitbrain\TheBankster\Entity\Account;
use splitbrain\TheBankster\Entity\FinTsState;

/**
 * Controller for FinTS TAN mode setup and authentication
 */
class FinTsSetupController extends BaseController
{
    /**
     * Main entry point for FinTS setup
     * Routes to appropriate step based on POST data or current state
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, $args)
    {
        $accountId = $args['account'];
        $account = $this->container->db->fetch(Account::class)->where('account', '=', $accountId)->one();

        if (!$account) {
            return $response->withStatus(404)->write('Account not found');
        }

        if ($account->backend !== 'FinTS') {
            return $response->withStatus(400)->write('This account is not a FinTS account');
        }

        // Check which step we're at
        $post = $request->getParsedBody();

        if (isset($post['step'])) {
            switch ($post['step']) {
                case 'select_tan_mode':
                    return $this->selectTanMode($request, $response, $account);
                case 'select_tan_medium':
                    return $this->selectTanMedium($request, $response, $account);
                case 'authenticate':
                    return $this->authenticate($request, $response, $account);
                case 'submit_tan':
                    return $this->submitTan($request, $response, $account);
            }
        }

        // Step 1: Show available TAN modes
        return $this->showTanModes($request, $response, $account);
    }

    /**
     * Step 1: Display available TAN modes
     */
    protected function showTanModes(ServerRequestInterface $request, ResponseInterface $response, Account $account)
    {
        try {
            // Initialize FinTS instance directly without going through backend
            // (backend expects state to exist already)
            $config = $account->configuration;
            $bankCode = $config['code'] ?? '';

            $options = new \Fhp\Options\FinTsOptions();
            $options->url = $config['url'];
            $options->bankCode = $bankCode;
            $options->productName = 'FF5FB8B02F2BAAE9FE52FD96C';
            $options->productVersion = '1.0';

            $credentials = \Fhp\Options\Credentials::create($config['user'], $config['pass']);
            $fints = \Fhp\FinTs::new($options, $credentials);

            // Special handling for ING DiBa (no PSD2 support, no anonymous dialog)
            if (trim($bankCode) == "50010517") {
                // ING DiBa only supports the NoPsd2TanMode
                $tanModes = [new \Fhp\Model\NoPsd2TanMode()];
            } else {
                // Try to get TAN modes
                try {
                    $tanModes = $fints->getTanModes();
                } catch (\Exception $e) {
                    // If anonymous dialog fails, it might still work with NoPsd2TanMode
                    if (strpos($e->getMessage(), 'anonyme Dialog') !== false ||
                        strpos($e->getMessage(), 'anonymous') !== false) {
                        // Bank doesn't support anonymous dialog, offer NoPsd2TanMode
                        $tanModes = [new \Fhp\Model\NoPsd2TanMode()];
                    } else {
                        throw $e;
                    }
                }
            }

            return $this->view->render($response, 'fints-setup-tan-modes.twig', [
                'account' => $account,
                'tanModes' => $tanModes,
                'error' => null,
            ]);
        } catch (\Exception $e) {
            return $this->view->render($response, 'fints-setup-tan-modes.twig', [
                'account' => $account,
                'tanModes' => [],
                'error' => 'Failed to connect to bank: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Step 2: User selected TAN mode, check if TAN medium is needed
     */
    protected function selectTanMode(ServerRequestInterface $request, ResponseInterface $response, Account $account)
    {
        $post = $request->getParsedBody();
        $tanModeId = $post['tan_mode'] ?? null;

        if (!$tanModeId) {
            return $response->withRedirect(
                $this->container->router->pathFor('fints-setup', ['account' => $account->account])
            );
        }

        // Store selected TAN mode in session
        if (!isset($_SESSION)) {
            session_start();
        }
        $_SESSION['fints_tan_mode'] = $tanModeId;

        try {
            $config = $account->configuration;
            $bankCode = $config['code'] ?? '';

            // Check if this is NoPsd2TanMode (special handling)
            // NoPsd2TanMode has ID -1
            if ($tanModeId === '-1' || $tanModeId === -1) {
                // For NoPsd2TanMode, no need to query bank for modes
                // Just proceed directly to authentication
                $_SESSION['fints_tan_mode'] = '-1';
                $_SESSION['fints_tan_medium'] = null;
                return $this->startAuthentication($request, $response, $account);
            }

            // Initialize FinTS instance for other TAN modes
            $options = new \Fhp\Options\FinTsOptions();
            $options->url = $config['url'];
            $options->bankCode = $bankCode;
            $options->productName = 'FF5FB8B02F2BAAE9FE52FD96C';
            $options->productVersion = '1.0';

            $credentials = \Fhp\Options\Credentials::create($config['user'], $config['pass']);
            $fints = \Fhp\FinTs::new($options, $credentials);

            // Get TAN modes to find the selected one
            $tanModes = $fints->getTanModes();
            $selectedMode = null;
            foreach ($tanModes as $mode) {
                if ($mode->getId() == $tanModeId) {
                    $selectedMode = $mode;
                    break;
                }
            }

            if (!$selectedMode) {
                throw new \Exception('Invalid TAN mode selected');
            }

            // Check if TAN medium is required
            if ($selectedMode->needsTanMedium()) {
                $tanMedia = $fints->getTanMedia($selectedMode);

                return $this->view->render($response, 'fints-setup-tan-media.twig', [
                    'account' => $account,
                    'tanMode' => $selectedMode,
                    'tanMedia' => $tanMedia,
                    'error' => null,
                ]);
            } else {
                // No TAN medium needed, proceed to authentication
                $_SESSION['fints_tan_medium'] = null;
                return $this->startAuthentication($request, $response, $account);
            }
        } catch (\Exception $e) {
            // If error occurs after selecting NoPsd2TanMode, show error page instead
            if ($tanModeId === '-1' || $tanModeId === -1) {
                return $this->view->render($response, 'fints-setup-error.twig', [
                    'account' => $account,
                    'error' => $e->getMessage(),
                ]);
            }

            return $this->view->render($response, 'fints-setup-tan-modes.twig', [
                'account' => $account,
                'tanModes' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Step 3: User selected TAN medium (if applicable)
     */
    protected function selectTanMedium(ServerRequestInterface $request, ResponseInterface $response, Account $account)
    {
        $post = $request->getParsedBody();
        $tanMediumName = $post['tan_medium'] ?? null;

        if (!$tanMediumName) {
            return $response->withRedirect(
                $this->container->router->pathFor('fints-setup', ['account' => $account->account])
            );
        }

        // Store selected TAN medium in session
        if (!isset($_SESSION)) {
            session_start();
        }
        $_SESSION['fints_tan_medium'] = $tanMediumName;

        return $this->startAuthentication($request, $response, $account);
    }

    /**
     * Step 4: Start authentication process
     */
    protected function startAuthentication(ServerRequestInterface $request, ResponseInterface $response, Account $account)
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        $tanMode = $_SESSION['fints_tan_mode'] ?? null;
        $tanMedium = $_SESSION['fints_tan_medium'] ?? null;

        if (!$tanMode) {
            return $response->withRedirect(
                $this->container->router->pathFor('fints-setup', ['account' => $account->account])
            );
        }

        try {
            // Initialize FinTS instance directly
            $config = $account->configuration;

            $options = new \Fhp\Options\FinTsOptions();
            $options->url = $config['url'];
            $options->bankCode = $config['code'] ?? '';
            $options->productName = 'FF5FB8B02F2BAAE9FE52FD96C';
            $options->productVersion = '1.0';

            $credentials = \Fhp\Options\Credentials::create($config['user'], $config['pass']);
            $fints = \Fhp\FinTs::new($options, $credentials);

            // Select TAN mode
            if ($tanMode === 'NoPsd2TanMode' || $tanMode === '-1' || $tanMode === -1) {
                $fints->selectTanMode(new \Fhp\Model\NoPsd2TanMode());
            } else {
                $fints->selectTanMode($tanMode, $tanMedium);
            }

            // Attempt login
            $login = $fints->login();

            if ($login->needsTan()) {
                // TAN required - show TAN input form
                $tanRequest = $login->getTanRequest();

                // Store FinTS state in session for later
                $_SESSION['fints_persisted'] = $fints->persist();
                $_SESSION['fints_login_action'] = serialize($login);

                return $this->view->render($response, 'fints-setup-tan-input.twig', [
                    'account' => $account,
                    'tanRequest' => $tanRequest,
                    'challenge' => $tanRequest->getChallenge(),
                    'challengeHhdUc' => $tanRequest->getChallengeHhdUc(),
                    'tanMediumName' => $tanRequest->getTanMediumName(),
                    'error' => null,
                ]);
            } else {
                // No TAN required (rare case) - save state directly
                $backend = new FinTS($account->configuration, $account->account);
                $backend->updateFinTsState($tanMode, $tanMedium, $fints->persist());

                return $this->view->render($response, 'fints-setup-success.twig', [
                    'account' => $account,
                ]);
            }
        } catch (\Exception $e) {
            return $this->view->render($response, 'fints-setup-error.twig', [
                'account' => $account,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Step 5: User submitted TAN
     */
    protected function submitTan(ServerRequestInterface $request, ResponseInterface $response, Account $account)
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        $post = $request->getParsedBody();
        $tan = $post['tan'] ?? null;

        if (!$tan) {
            return $this->view->render($response, 'fints-setup-error.twig', [
                'account' => $account,
                'error' => 'No TAN provided',
            ]);
        }

        $tanMode = $_SESSION['fints_tan_mode'] ?? null;
        $tanMedium = $_SESSION['fints_tan_medium'] ?? null;
        $persistedState = $_SESSION['fints_persisted'] ?? null;
        $serializedAction = $_SESSION['fints_login_action'] ?? null;

        if (!$tanMode || !$persistedState || !$serializedAction) {
            return $this->view->render($response, 'fints-setup-error.twig', [
                'account' => $account,
                'error' => 'Session expired. Please start over.',
            ]);
        }

        try {
            // Restore FinTS instance
            $backend = new FinTS($account->configuration, $account->account);
            $config = $account->configuration;

            $options = new \Fhp\Options\FinTsOptions();
            $options->url = $config['url'];
            $options->bankCode = $config['code'];
            $options->productName = 'FF5FB8B02F2BAAE9FE52FD96C';
            $options->productVersion = '1.0';

            $credentials = \Fhp\Options\Credentials::create($config['user'], $config['pass']);
            $fints = \Fhp\FinTs::new($options, $credentials, $persistedState);

            // Restore login action
            $login = unserialize($serializedAction);

            // Submit TAN
            $fints->submitTan($login, $tan);

            // Success! Save the state
            $backend->updateFinTsState($tanMode, $tanMedium, $fints->persist());

            // Clear session
            unset($_SESSION['fints_tan_mode']);
            unset($_SESSION['fints_tan_medium']);
            unset($_SESSION['fints_persisted']);
            unset($_SESSION['fints_login_action']);

            return $this->view->render($response, 'fints-setup-success.twig', [
                'account' => $account,
            ]);
        } catch (\Exception $e) {
            return $this->view->render($response, 'fints-setup-error.twig', [
                'account' => $account,
                'error' => 'TAN submission failed: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Handles authentication (alias for startAuthentication for route consistency)
     */
    protected function authenticate(ServerRequestInterface $request, ResponseInterface $response, Account $account)
    {
        return $this->startAuthentication($request, $response, $account);
    }
}
