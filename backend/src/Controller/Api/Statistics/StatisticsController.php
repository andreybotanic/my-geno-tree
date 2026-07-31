<?php

namespace App\Controller\Api\Statistics;

use App\Application\Statistics\StatisticsService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class StatisticsController
{
    public function __construct(private StatisticsService $statistics)
    {
    }

    #[Route('/api/v1/statistics', name: 'api_statistics', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->statistics->current());
    }
}
