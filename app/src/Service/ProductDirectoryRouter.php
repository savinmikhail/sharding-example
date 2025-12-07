<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

class ProductDirectoryRouter
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Установить бакет (hot/cold) для продукта в директории.
     */
    public function setBucket(int $productId, string $bucket): void
    {
        if (!in_array($bucket, ['hot', 'cold'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported bucket "%s"', $bucket));
        }

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO product_shard_directory (product_id, bucket)
VALUES (:product_id, :bucket)
ON CONFLICT (product_id) DO UPDATE SET bucket = EXCLUDED.bucket
SQL,
            [
                'product_id' => $productId,
                'bucket' => $bucket,
            ],
        );
    }

    /**
     * Получить бакет (hot/cold) для продукта или null, если он ещё не назначен.
     */
    public function getBucket(int $productId): ?string
    {
        $bucket = $this->connection->fetchOne(
            'SELECT bucket FROM product_shard_directory WHERE product_id = :product_id',
            ['product_id' => $productId],
        );

        if ($bucket === false || $bucket === null) {
            return null;
        }

        return (string) $bucket;
    }

    /**
     * Простейшее правило по умолчанию: часть продуктов считаем "горячими".
     *
     * Например, каждые N-й продукт можно отнести к hot,
     * чтобы показать различие бакетов даже без явной записи в директорию.
     */
    public function determineDefaultBucket(int $productId, float $hotRatio): string
    {
        if ($hotRatio <= 0.0) {
            return 'cold';
        }

        if ($hotRatio >= 1.0) {
            return 'hot';
        }

        // Простое детерминированное правило: используем хеш productId
        // и сравниваем с порогом.
        $hash = crc32((string) $productId);
        $normalized = ($hash & 0xffffffff) / 0xffffffff;

        return $normalized < $hotRatio ? 'hot' : 'cold';
    }

    /**
     * Вставить или обновить продукт в соответствующем бакете.
     *
     * Если бакет не указан явно и не найден в директории, используется determineDefaultBucket().
     */
    public function upsertProduct(
        int $productId,
        string $name,
        string $price,
        ?string $explicitBucket = null,
        float $defaultHotRatio = 0.2,
    ): void {
        $bucket = $explicitBucket ?? $this->getBucket($productId);

        if ($bucket === null) {
            $bucket = $this->determineDefaultBucket($productId, $defaultHotRatio);
            $this->setBucket($productId, $bucket);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $table = $bucket === 'hot' ? 'product_hot' : 'product_cold';

        $this->connection->executeStatement(
            <<<SQL
INSERT INTO {$table} (product_id, name, price, updated_at)
VALUES (:product_id, :name, :price, :updated_at)
ON CONFLICT (product_id) DO UPDATE SET
    name = EXCLUDED.name,
    price = EXCLUDED.price,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'product_id' => $productId,
                'name' => $name,
                'price' => $price,
                'updated_at' => $now,
            ],
        );
    }

    /**
     * Прочитать продукт с учётом директории.
     *
     * Возвращает ассоциативный массив с полями или null, если продукт не найден.
     */
    public function getProduct(int $productId): ?array
    {
        $bucket = $this->getBucket($productId);

        if ($bucket === null) {
            return null;
        }

        $table = $bucket === 'hot' ? 'product_hot' : 'product_cold';

        $row = $this->connection->fetchAssociative(
            "SELECT product_id, name, price, updated_at FROM {$table} WHERE product_id = :product_id",
            ['product_id' => $productId],
        );

        if ($row === false || $row === null) {
            return null;
        }

        return [
            'productId' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'price' => (string) $row['price'],
            'updatedAt' => (string) $row['updated_at'],
            'bucket' => $bucket,
        ];
    }

    /**
     * Подсчитать количество продуктов в каждом бакете.
     *
     * @return array<string,int> ['hot' => 10, 'cold' => 90]
     */
    public function countByBucket(): array
    {
        $hot = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_hot');
        $cold = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM product_cold');

        return [
            'hot' => $hot,
            'cold' => $cold,
        ];
    }
}

