<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders', name: 'order_')]
class OrderController extends AbstractController
{
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (
            !isset($data['userId'], $data['amount'])
            || !is_numeric($data['userId'])
            || !is_numeric($data['amount'])
        ) {
            return $this->json(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
        }

        $userId = (int) $data['userId'];
        $amount = (string) $data['amount'];
        $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : 'new';

        $order = new Order($userId, $amount, $status);

        $em->persist($order);
        $em->flush();

        return $this->json($this->normalizeOrder($order), Response::HTTP_CREATED);
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, OrderRepository $repository): JsonResponse
    {
        $userIdParam = $request->query->get('userId');

        if ($userIdParam === null || !is_numeric($userIdParam)) {
            return $this->json(['error' => 'Query parameter "userId" is required and must be numeric'], Response::HTTP_BAD_REQUEST);
        }

        $userId = (int) $userIdParam;

        $orders = $repository->findLatestByUser($userId);

        return $this->json(
            array_map(
                fn (Order $order): array => $this->normalizeOrder($order),
                $orders,
            ),
        );
    }

    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(OrderRepository $repository): JsonResponse
    {
        $totals = $repository->getTotalAmountByUser();

        return $this->json($totals);
    }

    private function normalizeOrder(Order $order): array
    {
        return [
            'id' => $order->getId(),
            'userId' => $order->getUserId(),
            'amount' => $order->getAmount(),
            'status' => $order->getStatus(),
            'createdAt' => $order->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $order->getUpdatedAt()?->format(\DATE_ATOM),
        ];
    }
}

