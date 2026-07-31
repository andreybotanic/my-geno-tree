<?php

namespace App\Application\Media;

use App\Api\ApiException;
use App\Application\Tree\TreeVersion;
use App\Entity\FamilyTree;
use App\Entity\MediaAttachment;
use App\Entity\MediaObject;
use App\Entity\MediaVariant;
use App\Entity\MediaVariantType;
use App\Entity\Person;
use App\Entity\User;
use App\Repository\MediaAttachmentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class MediaService
{
    public function __construct(
        private ImageProcessor $images,
        private MediaStorage $storage,
        private MediaAttachmentRepository $attachments,
        private TreeVersion $versions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function uploadAvatar(
        User $user,
        UploadedFile $file,
        CropArea $crop,
    ): void {
        $createdAt = $this->clock->now();
        $media = $this->storage->store(
            $user,
            $this->images->avatar($file, $crop),
            $createdAt,
        );
        $oldMedia = null;
        try {
            $this->entityManager->wrapInTransaction(function () use (
                $user,
                $media,
                $createdAt,
                &$oldMedia,
            ): void {
                $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($user);
                $oldMedia = $this->detachAvatarRecord($user, $createdAt);
                if ($oldMedia instanceof MediaObject) {
                    $this->entityManager->flush();
                }
                $attachment = MediaAttachment::avatar(
                    $media,
                    $user,
                    $createdAt,
                );
                $this->entityManager->persist($media);
                $this->entityManager->persist($attachment);
            });
        } catch (\Throwable $error) {
            $this->storage->remove($media);
            throw $error;
        }
        if ($oldMedia instanceof MediaObject) {
            $this->storage->remove($oldMedia);
        }
    }

    public function deleteAvatar(User $user): void
    {
        $changedAt = $this->clock->now();
        $media = $this->entityManager->wrapInTransaction(function () use (
            $user,
            $changedAt,
        ): ?MediaObject {
            $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($user);

            return $this->detachAvatarRecord($user, $changedAt);
        });
        if ($media instanceof MediaObject) {
            $this->storage->remove($media);
        }
    }

    public function uploadPersonPhoto(
        FamilyTree $tree,
        Person $person,
        User $owner,
        UploadedFile $file,
        CropArea $crop,
        ?string $ifMatch,
    ): void {
        $changedAt = $this->clock->now();
        $media = $this->storage->store(
            $owner,
            $this->images->personPhoto($file, $crop),
            $changedAt,
        );
        $oldMedia = null;
        try {
            $this->entityManager->wrapInTransaction(function () use (
                $tree,
                $person,
                $media,
                $changedAt,
                $ifMatch,
                &$oldMedia,
            ): void {
                $this->lockTreeAndPerson($tree, $person, $ifMatch);
                $oldMedia = $this->detachPersonPhotoRecord(
                    $person,
                    $changedAt,
                );
                if ($oldMedia instanceof MediaObject) {
                    $this->entityManager->flush();
                }
                $attachment = MediaAttachment::personPhoto(
                    $media,
                    $person,
                    $changedAt,
                );
                $this->entityManager->persist($media);
                $this->entityManager->persist($attachment);
                $tree->touch($changedAt);
            });
        } catch (\Throwable $error) {
            $this->storage->remove($media);
            throw $error;
        }
        if ($oldMedia instanceof MediaObject) {
            $this->storage->remove($oldMedia);
        }
    }

    public function deletePersonPhoto(
        FamilyTree $tree,
        Person $person,
        ?string $ifMatch,
    ): void {
        $changedAt = $this->clock->now();
        $media = $this->entityManager->wrapInTransaction(function () use (
            $tree,
            $person,
            $ifMatch,
            $changedAt,
        ): ?MediaObject {
            $this->lockTreeAndPerson($tree, $person, $ifMatch);
            $media = $this->detachPersonPhotoRecord($person, $changedAt);
            if ($media instanceof MediaObject) {
                $tree->touch($changedAt);
            }

            return $media;
        });
        if ($media instanceof MediaObject) {
            $this->storage->remove($media);
        }
    }

    public function avatar(User $user): MediaVariant
    {
        $attachment = $user->getAvatarAttachment();

        return $this->requiredVariant(
            $attachment?->getMedia(),
            MediaVariantType::AVATAR,
        );
    }

    public function personPhoto(
        Person $person,
        MediaVariantType $type,
    ): MediaVariant {
        return $this->requiredVariant(
            $person->getPhotoAttachment()?->getMedia(),
            $type,
        );
    }

    public function contents(MediaVariant $variant): string
    {
        return $this->storage->contents($variant);
    }

    public function detachPersonPhotoRecord(
        Person $person,
        \DateTimeImmutable $changedAt,
    ): ?MediaObject {
        $attachment = $person->getPhotoAttachment();
        if (null === $attachment) {
            return null;
        }
        $media = $attachment->getMedia();
        $person->detachPhoto($changedAt);
        $this->entityManager->remove($attachment);
        $this->entityManager->remove($media);

        return $media;
    }

    /** @return list<MediaObject> */
    public function detachTreeMediaRecords(FamilyTree $tree): array
    {
        $media = [];
        foreach ($this->attachments->findAllForTree($tree) as $attachment) {
            $media[] = $attachment->getMedia();
            $this->entityManager->remove($attachment);
            $this->entityManager->remove($attachment->getMedia());
        }

        return $media;
    }

    /** @param list<MediaObject> $media */
    public function deleteStored(array $media): void
    {
        foreach ($media as $object) {
            $this->storage->remove($object);
        }
    }

    private function detachAvatarRecord(
        User $user,
        \DateTimeImmutable $changedAt,
    ): ?MediaObject {
        $attachment = $user->getAvatarAttachment();
        if (null === $attachment) {
            return null;
        }
        $media = $attachment->getMedia();
        $user->detachAvatar($changedAt);
        $this->entityManager->remove($attachment);
        $this->entityManager->remove($media);

        return $media;
    }

    private function lockTreeAndPerson(
        FamilyTree $tree,
        Person $person,
        ?string $ifMatch,
    ): void {
        $this->entityManager->lock($tree, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($tree);
        $this->versions->assertMatches($tree, $ifMatch);
        $this->entityManager->lock($person, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($person);
    }

    private function requiredVariant(
        ?MediaObject $media,
        MediaVariantType $type,
    ): MediaVariant {
        $variant = $media?->variant($type);
        if (null === $variant) {
            throw new ApiException(JsonResponse::HTTP_NOT_FOUND, 'NOT_FOUND', 'not_found');
        }

        return $variant;
    }
}
