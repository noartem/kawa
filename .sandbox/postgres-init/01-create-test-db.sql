SELECT 'CREATE DATABASE ui_test'
WHERE NOT EXISTS (
    SELECT
    FROM pg_database
    WHERE datname = 'ui_test'
)\gexec
