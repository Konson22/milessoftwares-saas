-- Run as miles_owner on a product database after every `php artisan migrate`
-- (ops/deploy/deploy.sh does this for each product). Idempotent.

GRANT USAGE ON SCHEMA public TO miles_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO miles_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO miles_app;

-- The runtime role never changes the schema or migration history.
REVOKE INSERT, UPDATE, DELETE ON migrations FROM miles_app;

-- Append-only ledgers. Their triggers reject changes too; this makes the
-- database refuse them before the trigger is reached. Only in the products
-- that have them.
DO $$
BEGIN
    IF to_regclass('public.stock_movements') IS NOT NULL THEN
        REVOKE UPDATE, DELETE, TRUNCATE ON stock_movements FROM miles_app;
    END IF;
    IF to_regclass('public.journal_lines') IS NOT NULL THEN
        REVOKE UPDATE, DELETE, TRUNCATE ON journal_lines FROM miles_app;
    END IF;
END $$;
