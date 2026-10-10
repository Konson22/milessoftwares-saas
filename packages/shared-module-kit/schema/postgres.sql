-- Inbox for Miles\SharedModuleKit\Inbox. Create it in the shared module's own database.
-- The unique key is what makes retried events harmless.
CREATE TABLE IF NOT EXISTS shared_module_inbox (
    event_id              uuid PRIMARY KEY,
    source_app            varchar(32)  NOT NULL,
    organization_id       uuid         NOT NULL,
    source_transaction_id varchar(128) NOT NULL,
    event_type            varchar(100) NOT NULL,
    schema_version        smallint     NOT NULL,
    content_hash          char(64)     NOT NULL,
    payload               jsonb        NOT NULL,
    occurred_at           timestamptz  NOT NULL,
    received_at           timestamptz  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at          timestamptz,
    CONSTRAINT shared_module_inbox_source_transaction_unique UNIQUE (source_app, organization_id, source_transaction_id)
);

CREATE INDEX IF NOT EXISTS shared_module_inbox_organization_received_index
    ON shared_module_inbox (organization_id, received_at);
