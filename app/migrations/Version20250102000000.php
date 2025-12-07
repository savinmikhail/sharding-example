<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250102000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create distributed orders table sharded by user_id using Citus';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE orders (
    id SERIAL NOT NULL,
    user_id INT NOT NULL,
    amount NUMERIC(10, 2) NOT NULL,
    status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
    PRIMARY KEY(id)
)
SQL);

        // Ensure Citus extension is available (for existing clusters where init.sql was not executed)
        $this->addSql('CREATE EXTENSION IF NOT EXISTS citus');

        // Make orders a distributed table in Citus with user_id as shard key and 2 shards
        $this->addSql(<<<'SQL'
SELECT create_distributed_table('orders', 'user_id', 'hash', 2)
SQL);
    }

    public function down(Schema $schema): void
    {
        // Undistribute then drop the table if Citus is available
        $this->addSql(<<<'SQL'
DO
$$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_proc
        WHERE proname = 'undistribute_table'
    ) THEN
        PERFORM undistribute_table('orders');
    END IF;
END;
$$;
SQL);

        $this->addSql('DROP TABLE orders');
    }
}
