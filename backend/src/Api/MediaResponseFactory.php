<?php

namespace App\Api;

use App\Entity\MediaVariant;
use Symfony\Component\HttpFoundation\Response;

final readonly class MediaResponseFactory
{
    public function create(MediaVariant $variant, string $contents): Response
    {
        return new Response($contents, Response::HTTP_OK, [
            'Cache-Control'          => 'private, no-store',
            'Content-Length'         => (string)$variant->getByteSize(),
            'Content-Type'           => $variant->getMimeType(),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
