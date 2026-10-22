-- The shared TURN capacity ledger (lib/ice-kit). One database for every AICOUNTLY product that uses the same
-- relays, so the books that guarantee the paid reserve are ONE set of books.
--
-- Create it ONCE per environment (staging and production separately), as a PostgreSQL administrator:
--
--   CREATE ROLE turn_ledger LOGIN PASSWORD '<generate; keep in the deploy secret store, never here>';
--   CREATE DATABASE aicountly_turn OWNER turn_ledger;
--   \c aicountly_turn
--   \i lib/ice-kit/sql/turn_capacity.sql        -- run as turn_ledger
--
-- Then give EVERY product the same WEBRTC_TURN_LEDGER_DSN / _USER (non-secret) and _PASSWORD (a secret, injected
-- by bin/ops/inject-turn-secrets.sh). The role has no access to any product database and no other privileges.
-- Idempotent: safe to run again.

CREATE TABLE IF NOT EXISTS turn_leases (
    lease_key    text    PRIMARY KEY,
    node_id      text    NOT NULL,
    tier         text    NOT NULL CHECK (tier IN ('paid', 'free')),
    product      text    NOT NULL,
    profile      text    NOT NULL,
    subject      text    NOT NULL,
    session_ref  text    NOT NULL,
    allocations  integer NOT NULL CHECK (allocations >= 0),
    bps          bigint  NOT NULL CHECK (bps >= 0),
    issued_at    bigint  NOT NULL,
    expires_at   bigint  NOT NULL,
    reported     boolean NOT NULL DEFAULT false,
    is_primary   boolean NOT NULL DEFAULT false
);
CREATE INDEX IF NOT EXISTS turn_leases_node_idx    ON turn_leases (node_id, expires_at);
CREATE INDEX IF NOT EXISTS turn_leases_client_idx  ON turn_leases (product, session_ref, subject);
CREATE INDEX IF NOT EXISTS turn_leases_expires_idx ON turn_leases (expires_at);

-- Measured load per node (bin/ingest-load.php), one row each.
CREATE TABLE IF NOT EXISTS turn_node_load (
    node_id     text   PRIMARY KEY,
    at          bigint NOT NULL,
    allocations integer NOT NULL,
    rx_bps      bigint NOT NULL,
    tx_bps      bigint NOT NULL,
    by_tier     jsonb
);

-- What each product believes each node's capacity is (bin/reconcile.php --register). A mismatch between
-- products means the reserve is not guaranteed.
CREATE TABLE IF NOT EXISTS turn_capacity_config (
    product         text    NOT NULL,
    node_id         text    NOT NULL,
    max_allocations integer NOT NULL,
    max_bps         bigint  NOT NULL,
    reserve_percent integer NOT NULL,
    tiers           text    NOT NULL,
    updated_at      bigint  NOT NULL,
    PRIMARY KEY (product, node_id)
);

-- ── Licence entitlement cache (Aicountly\Ice\Licence) ───────────────────────────────────────────────────────────
-- Signed entitlement envelopes from the central licensing backend (docs/LICENCE_ENTITLEMENT_CONTRACT.md), shared by every
-- product and host. NOT trusted storage: each envelope carries the central backend's Ed25519 signature and is
-- re-verified when used, so a forged or edited row is ignored. Tenant isolation is by product_id in every key.
CREATE TABLE IF NOT EXISTS aic_entitlement_cache (
    scope       text   NOT NULL CHECK (scope IN ('customer', 'member', 'user')),
    ref         text   NOT NULL,
    product_id  text   NOT NULL,
    revision    bigint NOT NULL,
    envelope    text   NOT NULL,
    fetched_at  bigint NOT NULL,
    PRIMARY KEY (scope, ref, product_id)
);
-- Revisions the central backend has revoked: a cached document at or below the revision is not trusted until a newer one arrives.
-- customer_id is '' for a personal (user-scoped) entitlement; subject is '' for a company-level one. A seat revoked or a member
-- removed is a revocation of that (customer, subject) pair only.
CREATE TABLE IF NOT EXISTS aic_entitlement_revoked (
    customer_id text   NOT NULL,
    subject     text   NOT NULL DEFAULT '',
    product_id  text   NOT NULL,
    revision    bigint NOT NULL,
    at          bigint NOT NULL,
    PRIMARY KEY (customer_id, subject, product_id)
);
-- How far each product has read the central changes feed, and when it last synced (the feed's heartbeat).
CREATE TABLE IF NOT EXISTS aic_entitlement_sync (
    product_id   text   PRIMARY KEY,
    cursor_seq   bigint NOT NULL DEFAULT 0,
    last_sync_at bigint,
    last_full_at bigint
);
