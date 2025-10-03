<?php

namespace splitbrain\TheBankster\Entity;

use ORM\Entity;

/**
 * Class FinTsState
 *
 * Entity for storing FinTS authentication state and configuration
 *
 * @package splitbrain\TheBankster\Entity
 */
class FinTsState extends Entity
{
    protected static $tableName = 'fints_state';
    protected static $primaryKey = 'account';
    protected static $autoIncrement = false;

    /**
     * Check if authentication has expired
     *
     * @return bool
     */
    public function isExpired()
    {
        if (!$this->auth_expires) {
            return true;
        }
        $expires = new \DateTime($this->auth_expires);
        $now = new \DateTime();
        return $now >= $expires;
    }

    /**
     * Check if authentication is expiring soon and needs warning
     *
     * @param int $daysThreshold Number of days before expiry to warn
     * @return bool
     */
    public function needsWarning($daysThreshold = 7)
    {
        if (!$this->auth_expires) {
            return true;
        }
        $expires = new \DateTime($this->auth_expires);
        $now = new \DateTime();
        $now->modify("+{$daysThreshold} days");
        return $now >= $expires;
    }

    /**
     * Get days until expiry
     *
     * @return int Number of days (negative if already expired)
     */
    public function getDaysUntilExpiry()
    {
        if (!$this->auth_expires) {
            return -1;
        }
        $expires = new \DateTime($this->auth_expires);
        $now = new \DateTime();
        $interval = $now->diff($expires);
        return $interval->invert ? -$interval->days : $interval->days;
    }

    /**
     * Check if TAN mode is configured
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->tan_mode);
    }

    /**
     * Get auth expires as DateTime object
     *
     * @return \DateTime|null
     */
    public function getAuthExpiresDateTime()
    {
        if (!$this->auth_expires) {
            return null;
        }
        return new \DateTime($this->auth_expires);
    }

    /**
     * Get last auth as DateTime object
     *
     * @return \DateTime|null
     */
    public function getLastAuthDateTime()
    {
        if (!$this->last_auth) {
            return null;
        }
        return new \DateTime($this->last_auth);
    }

    /**
     * Set auth expires from DateTime
     *
     * @param \DateTime $dt
     */
    public function setAuthExpiresFromDateTime(\DateTime $dt)
    {
        $this->auth_expires = $dt->format('Y-m-d H:i:s');
    }

    /**
     * Set last auth from DateTime
     *
     * @param \DateTime $dt
     */
    public function setLastAuthFromDateTime(\DateTime $dt)
    {
        $this->last_auth = $dt->format('Y-m-d H:i:s');
    }

    /**
     * Update timestamps before persisting
     */
    public function prePersist()
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        if (!isset($this->created_at) || !$this->created_at) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;
    }
}
