<?php

namespace App\Application\Tree;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use Symfony\Component\HttpFoundation\JsonResponse;

final class TreeVersion
{
    public function etag(FamilyTree $tree): string
    {
        $payload = sprintf(
            '%s:%d',
            $tree->getId()->toRfc4122(),
            $tree->getVersion(),
        );
        $hash = rtrim(strtr(
            base64_encode(hash('sha256', $payload, true)),
            '+/',
            '-_',
        ), '=');

        return sprintf('"tree-%s"', $hash);
    }

    public function assertMatches(FamilyTree $tree, ?string $ifMatch): void
    {
        $current = $this->etag($tree);
        if (null !== $ifMatch && hash_equals($current, trim($ifMatch))) {
            return;
        }

        throw new ApiException(JsonResponse::HTTP_PRECONDITION_FAILED, 'TREE_VERSION_CONFLICT', 'tree_version_conflict');
    }
}
