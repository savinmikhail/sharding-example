<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sharding-demo',
    description: 'Demonstrate Citus sharding with the distributed orders table',
)]
class ShardingDemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderRepository $orderRepository,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'users',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of distinct user IDs to generate orders for',
                4,
            )
            ->addOption(
                'orders-per-user',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of orders to create per user',
                5,
            )
            ->addOption(
                'truncate',
                null,
                InputOption::VALUE_NONE,
                'Truncate orders table before inserting demo data',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $usersCount = (int) $input->getOption('users');
        $ordersPerUser = (int) $input->getOption('orders-per-user');
        $truncate = (bool) $input->getOption('truncate');

        if ($usersCount <= 0 || $ordersPerUser <= 0) {
            $io->error('Both --users and --orders-per-user must be positive integers.');

            return Command::INVALID;
        }

        $io->title('Citus sharding demo for orders table');

        $this->printClusterNodes($io);
        $this->printShardsInfo($io);

        if ($truncate) {
            $io->section('Truncating existing data in distributed table orders');

            try {
                $this->connection->executeStatement('TRUNCATE TABLE orders');
            } catch (\Throwable $exception) {
                $io->warning(sprintf('Could not truncate orders table: %s', $exception->getMessage()));
            }
        }

        $this->generateOrders($io, $usersCount, $ordersPerUser);

        $this->printAggregatedTotals($io);
        $this->printUserShardMapping($io, $usersCount);

        $io->success('Sharding demo completed.');

        return Command::SUCCESS;
    }

    private function printClusterNodes(SymfonyStyle $io): void
    {
        $io->section('Citus nodes (pg_dist_node)');

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT nodename, nodeport, noderole FROM pg_dist_node ORDER BY nodename, nodeport',
            );
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Could not query pg_dist_node: %s', $exception->getMessage()));

            return;
        }

        if ($rows === []) {
            $io->warning('No rows in pg_dist_node (is Citus configured correctly?)');

            return;
        }

        $io->table(
            ['Node', 'Port', 'Role'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['nodename'],
                    (string) $row['nodeport'],
                    (string) $row['noderole'],
                ],
                $rows,
            ),
        );
    }

    private function printShardsInfo(SymfonyStyle $io): void
    {
        $io->section('Orders table shards (pg_dist_shard / pg_dist_placement)');

        try {
            $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
    s.shardid,
    s.shardminvalue,
    s.shardmaxvalue,
    n.nodename,
    n.nodeport
FROM pg_dist_shard s
JOIN pg_dist_placement p USING (shardid)
JOIN pg_dist_node n ON n.groupid = p.groupid
WHERE s.logicalrelid = 'orders'::regclass
ORDER BY s.shardid, n.nodename, n.nodeport
SQL);
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Could not query shard layout: %s', $exception->getMessage()));

            return;
        }

        if ($rows === []) {
            $io->warning('orders is not registered as distributed in pg_dist_shard (did migration run successfully?)');

            return;
        }

        $io->table(
            ['Shard ID', 'Min value', 'Max value', 'Node', 'Port'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['shardid'],
                    (string) $row['shardminvalue'],
                    (string) $row['shardmaxvalue'],
                    (string) $row['nodename'],
                    (string) $row['nodeport'],
                ],
                $rows,
            ),
        );
    }

    private function generateOrders(SymfonyStyle $io, int $usersCount, int $ordersPerUser): void
    {
        $io->section(sprintf('Generating demo orders: %d users × %d orders', $usersCount, $ordersPerUser));

        $this->entityManager->beginTransaction();

        try {
            for ($userId = 1; $userId <= $usersCount; $userId++) {
                for ($i = 0; $i < $ordersPerUser; $i++) {
                    $amountCents = random_int(500, 10000);
                    $amount = number_format($amountCents / 100, 2, '.', '');

                    $order = new Order($userId, $amount, 'new');

                    $this->entityManager->persist($order);
                }
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $io->success('Demo orders generated via Doctrine ORM (coordinator will route writes to shards).');
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            $io->error(sprintf('Failed to generate demo orders: %s', $exception->getMessage()));
        }
    }

    private function printAggregatedTotals(SymfonyStyle $io): void
    {
        $io->section('Aggregated totals per user (distributed query)');

        try {
            $totals = $this->orderRepository->getTotalAmountByUser();
        } catch (\Throwable $exception) {
            $io->error(sprintf('Failed to load totals via repository: %s', $exception->getMessage()));

            return;
        }

        if ($totals === []) {
            $io->warning('No orders found.');

            return;
        }

        $io->table(
            ['User ID', 'Total amount'],
            array_map(
                static fn (array $row): array => [
                    (string) $row['userId'],
                    (string) $row['total'],
                ],
                $totals,
            ),
        );
    }

    private function printUserShardMapping(SymfonyStyle $io, int $usersCount): void
    {
        $io->section('User-to-shard mapping (which shard each userId goes to)');

        // Check if helper function exists in this Citus version
        try {
            $hasFunction = (bool) $this->connection->fetchOne(<<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM pg_proc
    WHERE proname = 'get_shard_id_for_distribution_column'
)
SQL);
        } catch (\Throwable $exception) {
            $io->warning(sprintf('Could not check for get_shard_id_for_distribution_column: %s', $exception->getMessage()));

            return;
        }

        if (!$hasFunction) {
            $io->warning('Function get_shard_id_for_distribution_column is not available in this Citus version.');

            return;
        }

        $rows = [];

        for ($userId = 1; $userId <= $usersCount; $userId++) {
            try {
                $shardId = $this->connection->fetchOne(
                    "SELECT get_shard_id_for_distribution_column('orders', :value::integer)",
                    ['value' => $userId],
                );
            } catch (\Throwable $exception) {
                $io->warning(sprintf('Could not compute shard for userId %d: %s', $userId, $exception->getMessage()));

                continue;
            }

            if ($shardId === false || $shardId === null) {
                continue;
            }

            $rows[] = [
                'userId' => (string) $userId,
                'shardId' => (string) $shardId,
            ];
        }

        if ($rows === []) {
            $io->warning('Could not compute shard IDs for any user.');

            return;
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $leftShard = (int) $left['shardId'];
                $rightShard = (int) $right['shardId'];

                if ($leftShard === $rightShard) {
                    return (int) $left['userId'] <=> (int) $right['userId'];
                }

                return $leftShard <=> $rightShard;
            },
        );

        $io->table(
            ['User ID', 'Shard ID'],
            array_map(
                static fn (array $row): array => [
                    $row['userId'],
                    $row['shardId'],
                ],
                $rows,
            ),
        );
    }
}
