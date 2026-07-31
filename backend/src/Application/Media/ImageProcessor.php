<?php

namespace App\Application\Media;

use App\Api\ApiException;
use App\Entity\MediaVariantType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class ImageProcessor
{
    private const int MAX_BYTES = 10 * 1024 * 1024;
    private const int MAX_PIXELS = 20_000_000;

    /** @return list<ProcessedVariant> */
    public function avatar(UploadedFile $file, CropArea $crop): array
    {
        $image = $this->decode($file);
        $this->assertCrop($image, $crop, 1.0);

        return [
            $this->crop(
                $image,
                $crop,
                MediaVariantType::AVATAR,
                256,
                256,
            ),
        ];
    }

    /** @return list<ProcessedVariant> */
    public function personPhoto(UploadedFile $file, CropArea $crop): array
    {
        $image = $this->decode($file);
        $this->assertCrop($image, $crop, 3 / 4);
        [$fullWidth, $fullHeight] = $this->boundedDimensions(
            imagesx($image),
            imagesy($image),
            2400,
        );

        return [
            $this->resize(
                $image,
                MediaVariantType::FULL,
                $fullWidth,
                $fullHeight,
            ),
            $this->crop(
                $image,
                $crop,
                MediaVariantType::PORTRAIT,
                600,
                800,
            ),
            $this->crop(
                $image,
                $crop,
                MediaVariantType::THUMBNAIL,
                180,
                240,
            ),
        ];
    }

    private function decode(UploadedFile $file): \GdImage
    {
        if (!$file->isValid()) {
            if (
                in_array(
                    $file->getError(),
                    [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE],
                    true,
                )
            ) {
                $this->tooLarge();
            }
            $this->unsupported();
        }
        $size = $file->getSize();
        if (false === $size || self::MAX_BYTES < $size) {
            $this->tooLarge();
        }
        $contents = file_get_contents($file->getPathname());
        if (false === $contents) {
            $this->unsupported();
        }
        $info = @getimagesizefromstring($contents);
        if (
            false === $info
            || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || (IMAGETYPE_PNG === $info[2] && str_contains($contents, 'acTL'))
            || (IMAGETYPE_WEBP === $info[2] && str_contains($contents, 'ANIM'))
        ) {
            $this->unsupported();
        }
        $width = $info[0];
        $height = $info[1];
        if (
            0 >= $width
            || 0 >= $height
            || self::MAX_PIXELS < $width * $height
        ) {
            $this->dimensionsInvalid();
        }
        $image = @imagecreatefromstring($contents);
        if (false === $image) {
            $this->unsupported();
        }

        return IMAGETYPE_JPEG === $info[2]
            ? $this->orientJpeg($image, $file)
            : $image;
    }

    private function orientJpeg(\GdImage $image, UploadedFile $file): \GdImage
    {
        $exif = @exif_read_data($file->getPathname());
        $orientation = is_array($exif)
            && is_int($exif['Orientation'] ?? null)
            ? $exif['Orientation']
            : 1;
        $angle = match ($orientation) {
            3       => 180,
            6       => -90,
            8       => 90,
            default => 0,
        };
        if (0 === $angle) {
            return $image;
        }
        $oriented = imagerotate($image, $angle, 0);
        if (false === $oriented) {
            $this->unsupported();
        }

        return $oriented;
    }

    private function assertCrop(
        \GdImage $image,
        CropArea $crop,
        float $aspect,
    ): void {
        if (
            $crop->x + $crop->width > imagesx($image)
            || $crop->y + $crop->height > imagesy($image)
            || 0.02 < abs($crop->width / $crop->height - $aspect)
        ) {
            throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'IMAGE_CROP_INVALID', 'image_crop_invalid', ['crop' => ['crop_outside_image_or_aspect_invalid']]);
        }
    }

    private function crop(
        \GdImage $source,
        CropArea $crop,
        MediaVariantType $type,
        int $width,
        int $height,
    ): ProcessedVariant {
        $target = $this->canvas($width, $height);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            $crop->x,
            $crop->y,
            $width,
            $height,
            $crop->width,
            $crop->height,
        );

        return $this->variant($target, $type, $width, $height);
    }

    private function resize(
        \GdImage $source,
        MediaVariantType $type,
        int $width,
        int $height,
    ): ProcessedVariant {
        $target = $this->canvas($width, $height);
        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source),
        );

        return $this->variant($target, $type, $width, $height);
    }

    private function canvas(int $width, int $height): \GdImage
    {
        if (0 >= $width || 0 >= $height) {
            throw new \LogicException('Image dimensions must be positive.');
        }
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        if (false === $transparent) {
            $this->unsupported();
        }
        imagefill($target, 0, 0, $transparent);

        return $target;
    }

    private function variant(
        \GdImage $image,
        MediaVariantType $type,
        int $width,
        int $height,
    ): ProcessedVariant {
        ob_start();
        $encoded = imagewebp($image, null, 85);
        $contents = ob_get_clean();
        if (!$encoded || !is_string($contents)) {
            $this->unsupported();
        }

        return new ProcessedVariant($type, $contents, $width, $height);
    }

    /** @return array{int, int} */
    private function boundedDimensions(
        int $width,
        int $height,
        int $maximum,
    ): array {
        $scale = min(1, $maximum / max($width, $height));

        return [
            max(1, (int)round($width * $scale)),
            max(1, (int)round($height * $scale)),
        ];
    }

    private function tooLarge(): never
    {
        throw new ApiException(JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE, 'IMAGE_TOO_LARGE', 'image_too_large');
    }

    private function dimensionsInvalid(): never
    {
        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'IMAGE_DIMENSIONS_INVALID', 'image_dimensions_invalid');
    }

    private function unsupported(): never
    {
        throw new ApiException(JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE, 'IMAGE_TYPE_UNSUPPORTED', 'image_type_unsupported');
    }
}
