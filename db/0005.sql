-- Migration 0005: Add fints_state table for FinTS authentication state persistence
--
-- This table stores FinTS-specific state separately from the account table,
-- including persisted authentication state, TAN mode configuration, and
-- authentication expiry tracking.

CREATE TABLE fints_state (
    account TEXT PRIMARY KEY,
    persisted_state TEXT DEFAULT NULL,
    tan_mode TEXT DEFAULT NULL,
    tan_medium TEXT DEFAULT NULL,
    auth_expires TEXT DEFAULT NULL,
    last_auth TEXT DEFAULT NULL,
    last_warning_sent TEXT DEFAULT NULL,
    warning_level INTEGER DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (account) REFERENCES account(account) ON DELETE CASCADE
);

-- Create index for faster lookups by expiry date
CREATE INDEX idx_fints_state_auth_expires ON fints_state(auth_expires);
