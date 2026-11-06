<?php

namespace App\Controller\Api;

use App\Api\ApiException;
use App\Api\RequestBodyMapper;
use App\Application\OAuth\OAuthClientRegistration;
use App\Application\OAuth\OAuthConnections;
use App\Application\OAuth\OAuthConsent;
use App\Application\OAuth\OAuthMetadata;
use App\Entity\AuthSession;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Routing\Attribute\Route;

final class OAuthController extends AbstractController
{
    public function __construct(
        private readonly OAuthMetadata $metadata,
        private readonly OAuthClientRegistration $clients,
        private readonly OAuthConsent $consent,
        private readonly OAuthConnections $connections,
        private readonly RequestBodyMapper $body,
    ) {
    }

    /** RFC 8414: clients discover the OAuth server's endpoints at this standardized URL. */
    #[Route('/.well-known/oauth-authorization-server', methods: ['GET'])]
    public function authorizationServer(): JsonResponse
    {
        return new JsonResponse($this->metadata->authorizationServer());
    }

    /** RFC 9728: metadata for the MCP protected resource, including its authorization server. */
    #[Route('/.well-known/oauth-protected-resource', methods: ['GET'])]
    #[Route('/.well-known/oauth-protected-resource/api/v1/mcp', methods: ['GET'])]
    public function protectedResource(): JsonResponse
    {
        return new JsonResponse($this->metadata->protectedResource());
    }

    #[Route('/api/v1/oauth/register', methods: ['POST'])]
    public function register(Request $request): HttpResponse
    {
        try {
            return new JsonResponse(
                $this->clients->register($this->body->decodeObject($request)),
                201,
                ['Cache-Control' => 'no-store'],
            );
        } catch (OAuthServerException $error) {
            return new HttpFoundationFactory()->createResponse($error->generateHttpResponse(new Response()));
        } catch (ApiException) {
            return new JsonResponse(['error' => 'invalid_client_metadata'], 400);
        }
    }

    #[Route('/api/v1/oauth/authorization-requests', methods: ['POST'])]
    public function requestConsent(Request $request): HttpResponse
    {
        $session = $request->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession) {
            throw new ApiException(401, 'INVALID_SESSION');
        }
        try {
            return $this->json(
                $this->consent->requestConsent($this->body->decodeObject($request), $session),
                201,
                ['Cache-Control' => 'no-store'],
            );
        } catch (OAuthServerException $error) {
            return new JsonResponse(['error' => $error->getErrorType()], $error->getHttpStatusCode());
        }
    }

    #[Route('/api/v1/oauth/authorization-requests/{id}/decision', methods: ['POST'])]
    public function completeAuthorization(Request $request, string $id): JsonResponse
    {
        $payload = $this->body->decodeObject($request);
        $session = $request->attributes->get(AuthSession::class);
        if (!$session instanceof AuthSession || !is_bool($payload['approved'] ?? null) || 1 !== count($payload)) {
            throw new ApiException(422, 'OAUTH_DECISION_INVALID');
        }

        return $this->json(
            ['redirectUrl' => $this->consent->completeAuthorization($id, $payload['approved'], $session)],
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    #[Route('/api/v1/oauth/token', methods: ['POST'])]
    public function issueTokens(Request $request): HttpResponse
    {
        try {
            return $this->connections->issueTokens($request);
        } catch (OAuthServerException $error) {
            $response = $error->generateHttpResponse(new Response());
        }

        return new HttpFoundationFactory()->createResponse($response);
    }

    #[Route('/api/v1/oauth/revoke', methods: ['POST'])]
    public function revokeTokens(Request $request): HttpResponse
    {
        try {
            return $this->connections->revokeTokens($request);
        } catch (OAuthServerException $error) {
            return new HttpFoundationFactory()->createResponse($error->generateHttpResponse(new Response()));
        }
    }
}
