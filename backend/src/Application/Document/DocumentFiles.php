<?php

namespace App\Application\Document;

use App\Api\ApiException;
use App\Entity\FamilyTree;
use App\Entity\MediaObject;
use App\Entity\MediaVariant;
use App\Entity\MediaVariantType;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class DocumentFiles
{
    public function __construct(#[Target('media.storage')] private FilesystemOperator $storage)
    {
    }

    /** @return array{name: string, mime: string, bytes: int, checksum: string, contents: string} */
    public function validate(?UploadedFile $file): array
    {
        if (null !== $file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException(413, 'DOCUMENT_TOO_LARGE');
        }
        if (null === $file || !$file->isValid()) {
            throw new ApiException(422, 'DOCUMENT_UPLOAD_INVALID');
        }
        $size = $file->getSize();
        if ($size > 10 * 1024 * 1024) {
            throw new ApiException(413, 'DOCUMENT_TOO_LARGE');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        $contents = $file->getContent();
        $text = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        if (!in_array($extension, ['txt', 'md'], true)
            || !mb_check_encoding($text, 'UTF-8')
            || '' === trim($text)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text)) {
            throw new ApiException(422, 'DOCUMENT_CONTENT_INVALID');
        }
        $name = str_replace(['\\', '/'], '_', $file->getClientOriginalName());
        if (mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new ApiException(422, 'DOCUMENT_NAME_INVALID');
        }

        return [
            'name'     => $name,
            'mime'     => 'md' === $extension ? 'text/markdown' : 'text/plain',
            'bytes'    => $size,
            'checksum' => hash('sha256', $contents),
            'contents' => $contents,
        ];
    }

    public function store(
        FamilyTree $tree,
        string $contents,
        string $mime,
        \DateTimeImmutable $now,
    ): MediaObject {
        $media = new MediaObject($tree->getOwner(), $now);
        $path = $tree->getOwner()->getId() . '/' . $media->getId() . '/original';
        $this->storage->write($path, $contents);
        new MediaVariant($media, MediaVariantType::ORIGINAL, $path, $mime, null, null, strlen($contents));

        return $media;
    }
}
