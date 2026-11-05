<?php

namespace App\Controller\Api;

use App\Application\Chat\ChatSubscription;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ChatStreamAuthorizationController
{
    #[Route('/api/v1/chat-events/authorize', methods: ['GET'])]
    public function __invoke(Request $request, ChatSubscription $subscriptions): Response
    {
        $subscriptions->authorize($request);

        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }
}
