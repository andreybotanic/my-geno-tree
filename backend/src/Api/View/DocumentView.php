<?php

namespace App\Api\View;

use App\Entity\TreeDocument;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Uid\Uuid;

#[Map(source: TreeDocument::class)]
final readonly class DocumentView
{
    public function __construct(
        public Uuid $id,
        #[Map(source: 'tree.id')] public Uuid $treeId,
        #[Map(source: 'person?.id')] public ?Uuid $personId,
        public string $originalName,
        public string $mimeType,
        public int $originalSizeBytes,
        public string $status,
        public ?string $errorCode,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        #[Map(source: 'id', transform: [self::class, 'downloadUrl'])] public string $downloadUrl,
    ) {
    }

    public static function downloadUrl(Uuid $id, TreeDocument $document): string
    {
        return '/trees/' . $document->getTree()?->getId() . '/documents/' . $id . '/download';
    }
}
