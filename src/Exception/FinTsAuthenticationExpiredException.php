<?php

namespace splitbrain\TheBankster\Exception;

/**
 * Exception thrown when FinTS authentication has expired (90-day limit)
 *
 * This indicates that the user needs to re-authenticate via the web interface
 */
class FinTsAuthenticationExpiredException extends \Exception
{
    /**
     * @param string $account The account that needs re-authentication
     * @param int $daysExpired Number of days since expiry (negative if not yet expired)
     */
    public function __construct($account, $daysExpired = 0)
    {
        $message = "FinTS authentication for account '{$account}' has expired";
        if ($daysExpired > 0) {
            $message .= " ({$daysExpired} days ago)";
        }
        $message .= ". Please re-authenticate via the web interface.";

        parent::__construct($message);
    }
}
