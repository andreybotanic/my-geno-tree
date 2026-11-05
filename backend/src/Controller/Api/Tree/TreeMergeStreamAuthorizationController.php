<?php

namespace App\Controller\Api\Tree;

use App\Application\TreeMerge\TreeMergeSubscription;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TreeMergeStreamAuthorizationController
{
    #[Route('/api/v1/merge-events/authorize', methods: ['GET'])]
    public function __invoke(Request $request, TreeMergeSubscription $subscriptions): Response
    {
        $subscriptions->authorize($request);

        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }
}
