<?php

namespace App\Application\Media;

use App\Entity\MediaObject;
use App\Entity\MediaVariant;
use App\Entity\User;
use DateTimeImmutable;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

final readonly class MediaStorage
{
    public function __construct(
        #[Target('media.storage')]
        private FilesystemOperator $storage,
    ) {
    }

    /**
     * @param list<ProcessedVariant> $variants
     */
    public function store(
        User $owner,
        array $variants,
        DateTimeImmutable $createdAt,
    ): MediaObject {
        $media = new MediaObject($owner, $createdAt);
        $written = [];
        try {
            foreach ($variants as $variant) {
                $path = sprintf(
                    '%s/%s/%s.webp',
                    $owner->getId()->toRfc4122(),
                    $media->getId()->toRfc4122(),
                    $variant->type->value,
                );
                $this->storage->write($path, $variant->contents);
                $written[] = $path;
                new MediaVariant(
                    $media,
                    $variant->type,
                    $path,
                    'image/webp',
                    $variant->width,
                    $variant->height,
                    strlen($variant->contents),
                );
            }
        } catch (\Throwable $error) {
            $this->deletePaths($written);
            throw $error;
        }

        return $media;
    }

    public function contents(MediaVariant $variant): string
    {
        return $this->storage->read($variant->getStoragePath());
    }

    public function remove(MediaObject $media): void
    {
        $this->deletePaths(array_values(array_map(
            static fn (MediaVariant $variant): string => $variant->getStoragePath(),
            $media->getVariants()->toArray(),
        )));
    }

    /** @param list<string> $paths */
    private function deletePaths(array $paths): void
    {
        foreach ($paths as $path) {
            if ($this->storage->fileExists($path)) {
                $this->storage->delete($path);
            }
        }
    }
}
