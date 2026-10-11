-- Run as miles_owner after every `php artisan migrate` (ops/deploy/deploy.sh does this).
-- Idempotent.

GRANT USAGE ON SCHEMA public TO miles_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO miles_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO miles_app;

-- Append-only audit trail. The trigger rejects changes too; this makes the
-- database refuse them before the trigger is even reached.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM miles_app;
GRANT SELECT, INSERT ON audit_logs TO miles_app;

-- The runtime role never changes the schema or migration history.
REVOKE INSERT, UPDATE, DELETE ON migrations FROM miles_app;
