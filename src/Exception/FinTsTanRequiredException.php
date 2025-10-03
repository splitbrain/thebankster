<?php

namespace splitbrain\TheBankster\Exception;

/**
 * Exception thrown when a TAN is required during an operation
 *
 * This can happen during login or during transaction imports if strong authentication is needed
 */
class FinTsTanRequiredException extends \Exception
{
    protected $tanRequest;

    /**
     * @param string $account The account that needs TAN input
     * @param mixed $tanRequest The TAN request object from the FinTS library (optional)
     */
    public function __construct($account, $tanRequest = null)
    {
        $this->tanRequest = $tanRequest;

        $message = "FinTS operation for account '{$account}' requires TAN input. ";
        $message .= "Please complete authentication via the web interface.";

        parent::__construct($message);
    }

    /**
     * Get the TAN request object
     *
     * @return mixed
     */
    public function getTanRequest()
    {
        return $this->tanRequest;
    }
}
