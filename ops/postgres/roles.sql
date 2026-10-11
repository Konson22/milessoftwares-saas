-- Run once per environment as a PostgreSQL superuser (or a role with CREATEROLE),
-- after creating the database:
--   createdb miles_platform
--   psql -v db_name=miles_platform -v owner_password="'...'" -v app_password="'...'" \
--        -v backup_password="'...'" -f ops/postgres/roles.sql
--
-- miles_owner  owns the schema and runs migrations. Deploys only.
-- miles_app    is what the running application connects as. It can read and
--              write tenant data but can only INSERT/SELECT audit_logs, so the
--              audit trail stays append-only even if the app is compromised.
-- miles_backup reads everything for ops/backup/backup.sh and writes nothing.
--
-- Roles belong to the whole cluster: one cluster serves one environment.

\set ON_ERROR_STOP on

\if :{?db_name}
\else
    DO $$ BEGIN RAISE EXCEPTION 'set -v db_name=<database>'; END $$;
\endif

CREATE ROLE miles_owner LOGIN PASSWORD :owner_password;
CREATE ROLE miles_app LOGIN PASSWORD :app_password;
CREATE ROLE miles_backup LOGIN PASSWORD :backup_password;
GRANT pg_read_all_data TO miles_backup;

ALTER DATABASE :"db_name" OWNER TO miles_owner;
REVOKE ALL ON DATABASE :"db_name" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"db_name" TO miles_app;
GRANT CONNECT ON DATABASE :"db_name" TO miles_backup;

\connect :"db_name"
ALTER SCHEMA public OWNER TO miles_owner;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO miles_app;

-- Tables created by future migrations get ordinary data access by default;
-- grants.sql then narrows audit_logs again after every migration.
ALTER DEFAULT PRIVILEGES FOR ROLE miles_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO miles_app;
ALTER DEFAULT PRIVILEGES FOR ROLE miles_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO miles_app;
