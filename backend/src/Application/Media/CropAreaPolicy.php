<?php

namespace App\Application\Media;

use App\Api\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class CropAreaPolicy
{
    public function fromRequest(Request $request): CropArea
    {
        $values = [];
        foreach (['cropX', 'cropY', 'cropWidth', 'cropHeight'] as $field) {
            $value = $request->request->get($field);
            if (
                !is_string($value)
                || !ctype_digit($value)
                || false === ($integer = filter_var(
                    $value,
                    FILTER_VALIDATE_INT,
                ))
            ) {
                $this->invalid($field);
            }
            $values[$field] = $integer;
        }
        if (0 >= $values['cropWidth'] || 0 >= $values['cropHeight']) {
            $this->invalid('crop');
        }

        return new CropArea(
            $values['cropX'],
            $values['cropY'],
            $values['cropWidth'],
            $values['cropHeight'],
        );
    }

    public function uploadedFile(Request $request): UploadedFile
    {
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            throw new ApiException(
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                'IMAGE_REQUIRED',
                'image_required',
                ['image' => ['image_file_required']],
            );
        }

        return $file;
    }

    private function invalid(string $field): never
    {
        throw new ApiException(
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
            'IMAGE_CROP_INVALID',
            'image_crop_invalid',
            [$field => ['crop_value_invalid']],
        );
    }
}
