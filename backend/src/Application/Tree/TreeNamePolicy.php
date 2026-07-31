<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;

final class TreeNamePolicy
{
    public function normalize(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 100) {
            throw new ApiException(
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'VALIDATION_FAILED',
                'validation_failed',
                ['name' => ['tree_name_invalid']],
            );
        }

        return $name;
    }
}
