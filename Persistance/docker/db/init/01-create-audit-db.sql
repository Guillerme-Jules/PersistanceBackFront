-- Crée la base dédiée au journal central (EntityManager "audit").
-- Exécuté une seule fois, au premier démarrage du conteneur Postgres.
SELECT 'CREATE DATABASE audit'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'audit')\gexec
