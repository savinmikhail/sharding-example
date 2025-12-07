<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Directory-based sharding example for products (product_shard_directory, product_hot, product_cold)';
    }

    public function up(Schema $schema): void
    {
        // Таблица-директория: к какому "бакету" относится продукт
        $this->addSql(<<<'SQL'
CREATE TABLE product_shard_directory (
    product_id INT NOT NULL PRIMARY KEY,
    bucket VARCHAR(10) NOT NULL CHECK (bucket IN ('hot', 'cold'))
)
SQL);

        // Хранилище "горячих" продуктов
        $this->addSql(<<<'SQL'
CREATE TABLE product_hot (
    product_id INT NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price NUMERIC(10, 2) NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL);

        // Хранилище "холодных" продуктов
        $this->addSql(<<<'SQL'
CREATE TABLE product_cold (
    product_id INT NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price NUMERIC(10, 2) NOT NULL,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS product_hot');
        $this->addSql('DROP TABLE IF EXISTS product_cold');
        $this->addSql('DROP TABLE IF EXISTS product_shard_directory');
    }
}

