<?php

namespace App\Tests\Api\Media;

use App\Tests\Api\ApiWebTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaApiTest extends ApiWebTestCase
{
    public function testAvatarCropReplacementDeletionAndMetadataRemoval(): void
    {
        [$token, $user] = $this->register();
        $first = $this->image('png', 120, 160, metadata: true);
        $this->upload(
            '/api/v1/me/avatar',
            $token,
            $first,
            ['cropX' => 0, 'cropY' => 0, 'cropWidth' => 120, 'cropHeight' => 120],
        );
        self::assertResponseIsSuccessful();
        $profile = $this->responseData();
        self::assertIsString($profile['avatarUrl']);
        self::assertStringContainsString('/me/avatar?media=', $profile['avatarUrl']);
        self::assertSame(1, $this->mediaObjectCount());

        $this->authenticatedRequest('GET', '/api/v1' . $profile['avatarUrl'], $token);
        self::assertResponseIsSuccessful();
        self::assertSame(
            'image/webp',
            $this->client->getResponse()->headers->get('Content-Type'),
        );
        $contents = $this->client->getResponse()->getContent();
        self::assertIsString($contents);
        self::assertStringNotContainsString('SECRET_METADATA', $contents);
        self::assertSame([256, 256], $this->dimensions($contents));

        $oldPath = $this->singleStoragePath($user['id']);
        $second = $this->image('jpeg', 160, 160);
        $this->upload(
            '/api/v1/me/avatar',
            $token,
            $second,
            ['cropX' => 20, 'cropY' => 20, 'cropWidth' => 120, 'cropHeight' => 120],
        );
        self::assertResponseIsSuccessful();
        self::assertFalse($this->storage()->fileExists($oldPath));
        $newPath = $this->singleStoragePath($user['id']);
        self::assertSame(1, $this->mediaObjectCount());

        $this->authenticatedRequest('DELETE', '/api/v1/me/avatar', $token);
        self::assertResponseIsSuccessful();
        self::assertNull($this->responseData()['avatarUrl']);
        self::assertFalse($this->storage()->fileExists($newPath));
        self::assertSame(0, $this->mediaObjectCount());

        $this->authenticatedRequest('GET', '/api/v1/me/avatar', $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPersonPhotoVariantsArePrivateVersionedAndRemoved(): void
    {
        [$token] = $this->register();
        [$tree, $snapshot, $person] = $this->personTree($token);
        $photo = $this->image('webp', 120, 160);
        $path = sprintf(
            '/api/v1/trees/%s/people/%s/photo',
            $tree['id'],
            $person['id'],
        );
        $this->upload(
            $path,
            $token,
            $photo,
            ['cropX' => 15, 'cropY' => 20, 'cropWidth' => 90, 'cropHeight' => 120],
            $snapshot['etag'],
        );
        self::assertResponseIsSuccessful();
        $uploaded = $this->responseData();
        self::assertNotSame($snapshot['etag'], $uploaded['etag']);
        self::assertIsString($uploaded['person']['photoThumbnailUrl']);

        foreach ([
            [$path, [120, 160]],
            [$path . '/portrait', [600, 800]],
            [$path . '/thumbnail', [180, 240]],
        ] as [$imagePath, $expectedDimensions]) {
            $this->authenticatedRequest('GET', $imagePath, $token);
            self::assertResponseIsSuccessful();
            $contents = $this->client->getResponse()->getContent();
            self::assertIsString($contents);
            self::assertSame($expectedDimensions, $this->dimensions($contents));
        }

        [$otherToken] = $this->register([
            'username' => 'other_user',
            'email'    => 'other@example.test',
        ]);
        $this->authenticatedRequest('GET', $path, $otherToken);
        self::assertResponseStatusCodeSame(404);

        $paths = $this->storagePathsForPerson($person['id']);
        self::assertCount(3, $paths);
        $this->upload(
            $path,
            $token,
            $photo,
            ['cropX' => 15, 'cropY' => 20, 'cropWidth' => 90, 'cropHeight' => 120],
            $snapshot['etag'],
        );
        self::assertResponseStatusCodeSame(412);
        self::assertSame($paths, $this->storagePathsForPerson($person['id']));

        $this->upload(
            $path,
            $token,
            $photo,
            ['cropX' => 15, 'cropY' => 20, 'cropWidth' => 90, 'cropHeight' => 120],
            $uploaded['etag'],
        );
        self::assertResponseIsSuccessful();
        $replaced = $this->responseData();
        $replacementPaths = $this->storagePathsForPerson($person['id']);
        self::assertCount(3, $replacementPaths);
        self::assertNotSame($paths, $replacementPaths);
        foreach ($paths as $storedPath) {
            self::assertFalse($this->storage()->fileExists($storedPath));
        }
        self::assertSame(1, $this->mediaObjectCount());

        $this->authenticatedRequest(
            'DELETE',
            $path,
            $token,
            $replaced['etag'],
        );
        self::assertResponseIsSuccessful();
        $deleted = $this->responseData();
        self::assertNull($deleted['person']['photoThumbnailUrl']);
        foreach ($replacementPaths as $storedPath) {
            self::assertFalse($this->storage()->fileExists($storedPath));
        }
        self::assertSame(0, $this->mediaObjectCount());
    }

    public function testTreeDeletionRemovesPhotoFiles(): void
    {
        [$token] = $this->register();
        [$tree, $snapshot, $person] = $this->personTree($token);
        $photoPath = sprintf(
            '/api/v1/trees/%s/people/%s/photo',
            $tree['id'],
            $person['id'],
        );
        $this->upload(
            $photoPath,
            $token,
            $this->image('png', 90, 120),
            ['cropX' => 0, 'cropY' => 0, 'cropWidth' => 90, 'cropHeight' => 120],
            $snapshot['etag'],
        );
        $uploaded = $this->responseData();
        $storedPaths = $this->storagePathsForPerson($person['id']);

        $this->authenticatedRequest(
            'DELETE',
            '/api/v1/trees/' . $tree['id'],
            $token,
            $uploaded['etag'],
        );
        self::assertResponseStatusCodeSame(204);
        foreach ($storedPaths as $storedPath) {
            self::assertFalse($this->storage()->fileExists($storedPath));
        }
        self::assertSame(0, $this->mediaObjectCount());
    }

    public function testPersonDeletionRemovesPhotoFiles(): void
    {
        [$token] = $this->register();
        [$tree, $snapshot, $person] = $this->personTree($token);
        $photoPath = sprintf(
            '/api/v1/trees/%s/people/%s/photo',
            $tree['id'],
            $person['id'],
        );
        $this->upload(
            $photoPath,
            $token,
            $this->image('jpeg', 90, 120),
            ['cropX' => 0, 'cropY' => 0, 'cropWidth' => 90, 'cropHeight' => 120],
            $snapshot['etag'],
        );
        self::assertResponseIsSuccessful();
        $uploaded = $this->responseData();
        $storedPaths = $this->storagePathsForPerson($person['id']);

        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            [
                'type'              => 'delete_person',
                'personId'          => $person['id'],
                'coordinateUpdates' => [],
            ],
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $uploaded['etag'],
            ],
        );
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->responseData()['people']);
        foreach ($storedPaths as $storedPath) {
            self::assertFalse($this->storage()->fileExists($storedPath));
        }
        self::assertSame(0, $this->mediaObjectCount());
    }

    public function testInvalidImageContentSizeAnimationAndCropAreRejected(): void
    {
        [$token] = $this->register();
        $valid = [
            'cropX'      => 0,
            'cropY'      => 0,
            'cropWidth'  => 90,
            'cropHeight' => 90,
        ];
        $svg = $this->temporaryFile(
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        );
        $this->upload('/api/v1/me/avatar', $token, $svg, $valid);
        self::assertResponseStatusCodeSame(415);

        $animated = $this->image('webp', 90, 90, suffix: 'ANIM');
        $this->upload('/api/v1/me/avatar', $token, $animated, $valid);
        self::assertResponseStatusCodeSame(415);

        $large = $this->image(
            'png',
            90,
            90,
            suffix: str_repeat('x', 10 * 1024 * 1024),
        );
        $this->upload('/api/v1/me/avatar', $token, $large, $valid);
        self::assertResponseStatusCodeSame(413);

        $tooManyPixels = $this->pngHeader(5000, 4001);
        $this->upload(
            '/api/v1/me/avatar',
            $token,
            $tooManyPixels,
            $valid,
        );
        self::assertResponseStatusCodeSame(422);

        $this->upload(
            '/api/v1/me/avatar',
            $token,
            $this->image('png', 90, 120),
            [...$valid, 'cropHeight' => 80],
        );
        self::assertResponseStatusCodeSame(422);

        $this->authenticatedRequest('POST', '/api/v1/me/avatar', $token);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array{
     *     array<string, mixed>,
     *     array<string, mixed>,
     *     array<string, mixed>
     * }
     */
    private function personTree(string $token): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/trees',
            ['name'          => 'Media tree'],
            ['Authorization' => 'Bearer ' . $token],
        );
        $tree = $this->responseData();
        $etag = $this->client->getResponse()->headers->get('ETag');
        self::assertIsString($etag);
        $this->jsonRequest(
            'POST',
            '/api/v1/trees/' . $tree['id'] . '/graph-mutations',
            [
                'type'   => 'create_first_person',
                'person' => [
                    'birthSurname' => ['state' => 'unknown'],
                    'givenName'    => ['state' => 'unknown'],
                    'patronymic'   => ['state' => 'unknown'],
                    'gender'       => 'unknown',
                    'birthDate'    => ['state' => 'unknown'],
                    'deathDate'    => null,
                ],
                'coordinates' => ['x' => 0, 'y' => 0],
            ],
            [
                'Authorization' => 'Bearer ' . $token,
                'If-Match'      => $etag,
            ],
        );
        $snapshot = $this->responseData();

        return [$tree, $snapshot, $snapshot['people'][0]];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{string, array<string, mixed>}
     */
    private function register(array $overrides = []): array
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/auth/register',
            array_replace($this->validRegistration(), $overrides),
        );
        self::assertResponseStatusCodeSame(201);
        $response = $this->responseData();
        self::assertIsString($response['accessToken']);
        self::assertIsArray($response['user']);

        return [$response['accessToken'], $response['user']];
    }

    /** @param array<string, int> $crop */
    private function upload(
        string $path,
        string $token,
        string $filePath,
        array $crop,
        ?string $etag = null,
    ): void {
        $server = [
            'HTTPS'              => 'on',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
        if (null !== $etag) {
            $server['HTTP_IF_MATCH'] = $etag;
        }
        $this->client->request(
            'POST',
            'https://localhost' . $path,
            array_map(strval(...), $crop),
            ['image' => new UploadedFile(
                $filePath,
                'misleading-extension.txt',
                null,
                null,
                true,
            )],
            $server,
        );
    }

    private function authenticatedRequest(
        string $method,
        string $path,
        string $token,
        ?string $etag = null,
    ): void {
        $server = [
            'HTTPS'              => 'on',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];
        if (null !== $etag) {
            $server['HTTP_IF_MATCH'] = $etag;
        }
        $this->client->request(
            $method,
            'https://localhost' . $path,
            server: $server,
        );
    }

    private function image(
        string $format,
        int $width,
        int $height,
        bool $metadata = false,
        string $suffix = '',
    ): string {
        if (0 >= $width || 0 >= $height) {
            throw new \LogicException('Fixture dimensions must be positive.');
        }
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 45, 120, 180);
        self::assertIsInt($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        $encoded = match ($format) {
            'jpeg'  => imagejpeg($image, null, 90),
            'png'   => imagepng($image),
            'webp'  => imagewebp($image, null, 90),
            default => false,
        };
        $contents = ob_get_clean();
        self::assertTrue($encoded);
        self::assertIsString($contents);

        return $this->temporaryFile(
            $contents . ($metadata ? 'SECRET_METADATA' : '') . $suffix,
        );
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mygenotree-media-');
        self::assertIsString($path);
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    private function pngHeader(int $width, int $height): string
    {
        $path = $this->image('png', 1, 1);
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $contents = substr_replace(
            $contents,
            pack('NN', $width, $height),
            16,
            8,
        );
        $contents = substr_replace(
            $contents,
            pack('N', crc32(substr($contents, 12, 17))),
            29,
            4,
        );

        return $this->temporaryFile($contents);
    }

    /** @return array{int, int} */
    private function dimensions(string $contents): array
    {
        $size = getimagesizefromstring($contents);
        self::assertIsArray($size);

        return [$size[0], $size[1]];
    }

    private function storage(): FilesystemOperator
    {
        $storage = static::getContainer()->get('media.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    private function mediaObjectCount(): int
    {
        $connection = static::getContainer()->get(
            'doctrine.dbal.default_connection',
        );
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        return (int)$connection->fetchOne(
            'SELECT COUNT(*) FROM media_object',
        );
    }

    private function singleStoragePath(string $userId): string
    {
        $paths = $this->storagePathsForOwner($userId);
        self::assertCount(1, $paths);

        return $paths[0];
    }

    /** @return list<string> */
    private function storagePathsForOwner(string $userId): array
    {
        $paths = [];
        foreach ($this->storage()->listContents($userId, true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }
        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function storagePathsForPerson(string $personId): array
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);
        $paths = $connection->fetchFirstColumn(
            'SELECT variant.storage_path
             FROM media_variant variant
             JOIN media_object media ON media.id = variant.media_id
             JOIN media_attachment attachment ON attachment.media_id = media.id
             WHERE attachment.person_id = ?
             ORDER BY variant.storage_path',
            [$personId],
        );

        return array_map(strval(...), $paths);
    }
}
