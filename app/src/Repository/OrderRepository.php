<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * @return Order[]
     */
    public function findLatestByUser(int $userId, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('o.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{userId:int,total:string}>
     */
    public function getTotalAmountByUser(): array
    {
        $result = $this->createQueryBuilder('o')
            ->select('o.userId AS userId, SUM(o.amount) AS total')
            ->groupBy('o.userId')
            ->orderBy('o.userId', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => [
                'userId' => (int) $row['userId'],
                'total' => (string) $row['total'],
            ],
            $result,
        );
    }
}

