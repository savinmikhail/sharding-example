CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
CREATE EXTENSION IF NOT EXISTS citus;

DO
$$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app') THEN
        CREATE ROLE app LOGIN PASSWORD 'app';
    END IF;
END;
$$;

GRANT pg_read_all_stats TO app;

