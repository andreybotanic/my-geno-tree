<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiErrorResponseFactory
{
    public function create(ApiException $exception): JsonResponse
    {
        return new JsonResponse([
            'code' => $exception->getErrorCode(),
            'message' => $exception->getMessageKey(),
            'fields' => $exception->getFields(),
            'details' => $exception->getDetails(),
        ], $exception->getStatus());
    }

    public function unauthorized(): JsonResponse
    {
        return $this->create(new ApiException(
            JsonResponse::HTTP_UNAUTHORIZED,
            'UNAUTHORIZED',
            'authentication_required',
        ));
    }
}
