-- Run once per product database, as a PostgreSQL superuser, after roles.sql
-- (the roles are the cluster's; each product has its own database):
--   createdb pharmacy
--   psql -v db_name=pharmacy -f ops/postgres/product-database.sql
--
-- Same split as the platform: miles_owner owns the schema and migrates,
-- miles_app runs the product, miles_backup reads.

\set ON_ERROR_STOP on

\if :{?db_name}
\else
    DO $$ BEGIN RAISE EXCEPTION 'set -v db_name=<database>'; END $$;
\endif

ALTER DATABASE :"db_name" OWNER TO miles_owner;
REVOKE ALL ON DATABASE :"db_name" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db_name" TO miles_app;
GRANT CONNECT ON DATABASE :"db_name" TO miles_backup;

\connect :"db_name"
ALTER SCHEMA public OWNER TO miles_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO miles_app;

ALTER DEFAULT PRIVILEGES FOR ROLE miles_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO miles_app;
ALTER DEFAULT PRIVILEGES FOR ROLE miles_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO miles_app;
