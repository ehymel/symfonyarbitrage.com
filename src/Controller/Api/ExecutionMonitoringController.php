<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\TradeExecutionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ExecutionMonitoringController extends AbstractController
{
    #[Route('/api/executions/recent', name: 'api_executions_recent', methods: ['GET'])]
    public function getRecentExecutions(TradeExecutionRepository $repository): JsonResponse
    {
        $executions = $repository->findBy([], ['createdAt' => 'DESC'], 50);

        $data = array_map(function ($e) {
            $opp = $e->opportunity;
            return [
                'id' => $e->id,
                'pair' => $opp->pair,
                'buyExchange' => strtoupper($opp->buyExchange),
                'sellExchange' => strtoupper($opp->sellExchange),
                'buyPrice' => (float)$opp->buyPrice,
                'sellPrice' => (float)$opp->sellPrice,
                'buyFilledPrice' => $e->buyFilledPrice ? (float)$e->buyFilledPrice : null,
                'sellFilledPrice' => $e->sellFilledPrice ? (float)$e->sellFilledPrice : null,
                'actualProfit' => $e->actualProfitUsd ? (float)$e->actualProfitUsd : 0.0,
                'status' => $e->status,
                'latencyMs' => $e->executionTimeMs,
                'createdAt' => $e->createdAt->format('Y-m-d H:i:s'),
                'buyOrderId' => $e->buyOrderId ?? 'N/A',
                'sellOrderId' => $e->sellOrderId ?? 'N/A',
            ];
        }, $executions);

        return $this->json([
            'summary' => [
                'totalExecutions' => count($executions),
                'successful' => count(array_filter($data, fn($x) => $x['status'] === 'COMPLETED')),
                'totalProfitUsd' => array_sum(array_column($data, 'actualProfit')),
            ],
            'executions' => $data,
        ]);
    }
}
