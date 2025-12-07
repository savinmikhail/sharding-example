<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ProductDirectoryRouter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:directory-sharding-products',
    description: 'Демонстрация directory-based шардирования продуктов (горячие/холодные ключи)',
)]
class DirectoryShardingProductsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProductDirectoryRouter $router,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'products',
                null,
                InputOption::VALUE_OPTIONAL,
                'Сколько productId сгенерировать',
                50,
            )
            ->addOption(
                'hot-ratio',
                null,
                InputOption::VALUE_OPTIONAL,
                'Доля горячих ключей (0..1), например 0.2 = 20%',
                0.2,
            )
            ->addOption(
                'truncate',
                null,
                InputOption::VALUE_NONE,
                'Очистить product_shard_directory, product_hot и product_cold перед демо',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $productsCount = (int) $input->getOption('products');
        $hotRatio = (float) $input->getOption('hot-ratio');
        $truncate = (bool) $input->getOption('truncate');

        if ($productsCount <= 0) {
            $io->error('Опция --products должна быть > 0.');

            return Command::INVALID;
        }

        if ($hotRatio < 0.0 || $hotRatio > 1.0) {
            $io->error('Опция --hot-ratio должна быть в диапазоне [0,1].');

            return Command::INVALID;
        }

        $io->title('Directory-based шардирование продуктов (горячие/холодные ключи)');

        if ($truncate) {
            $this->truncateTables($io);
        }

        $this->generateDirectoryAndProducts($io, $productsCount, $hotRatio);
        $this->printDirectorySample($io);
        $this->printBucketStats($io);

        $io->success('Directory-based демо для продуктов выполнено.');

        return Command::SUCCESS;
    }

    private function truncateTables(SymfonyStyle $io): void
    {
        $io->section('Очистка product_shard_directory, product_hot, product_cold');

        try {
            $this->connection->executeStatement('TRUNCATE TABLE product_shard_directory, product_hot, product_cold');
            $io->success('Таблицы очищены.');
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Не удалось очистить таблицы: %s', $exception->getMessage()));
        }
    }

    private function generateDirectoryAndProducts(SymfonyStyle $io, int $productsCount, float $hotRatio): void
    {
        $io->section(sprintf('Генерация %d продуктов с долей горячих ключей %.2f', $productsCount, $hotRatio));

        $this->connection->beginTransaction();

        try {
            for ($productId = 1; $productId <= $productsCount; $productId++) {
                $bucket = $this->router->determineDefaultBucket($productId, $hotRatio);

                // Явно фиксируем решение в директории — это и есть "directory-based"
                $this->router->setBucket($productId, $bucket);

                $priceCents = random_int(100, 10000);
                $price = number_format($priceCents / 100, 2, '.', '');
                $name = sprintf('Product-%03d', $productId);

                $this->router->upsertProduct($productId, $name, $price, $bucket, $hotRatio);
            }

            $this->connection->commit();

            $io->success('Данные для продуктов сгенерированы через directory-based роутер.');
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            $io->error(sprintf('Ошибка при генерации продуктов: %s', $exception->getMessage()));
        }
    }

    private function printDirectorySample(SymfonyStyle $io): void
    {
        $io->section('Пример содержимого product_shard_directory (первые 20 строк)');

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT product_id, bucket FROM product_shard_directory ORDER BY product_id LIMIT 20',
            );
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Не удалось прочитать product_shard_directory: %s', $exception->getMessage()));

            return;
        }

        if ($rows === []) {
            $io->warning('Таблица product_shard_directory пуста.');

            return;
        }

        $io->table(
            ['Product ID', 'Bucket'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['product_id'],
                    (string) $row['bucket'],
                ],
                $rows,
            ),
        );
    }

    private function printBucketStats(SymfonyStyle $io): void
    {
        $io->section('Статистика по бакетам (product_hot / product_cold)');

        $counts = $this->router->countByBucket();

        $io->table(
            ['Bucket', 'Row count'],
            [
                ['hot', (string) $counts['hot']],
                ['cold', (string) $counts['cold']],
            ],
        );

        $io->section('Отображение продуктов на бакеты (первые 20 строк, сортировка по bucket, затем product_id)');

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT product_id, bucket FROM product_shard_directory ORDER BY bucket, product_id LIMIT 20',
            );
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Не удалось прочитать product_shard_directory для маппинга: %s', $exception->getMessage()));

            return;
        }

        if ($rows === []) {
            $io->warning('Таблица product_shard_directory пуста.');

            return;
        }

        $io->table(
            ['Product ID', 'Bucket'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['product_id'],
                    (string) $row['bucket'],
                ],
                $rows,
            ),
        );
    }
}

