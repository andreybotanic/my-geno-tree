<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class RequestBodyMapper
{
    public function __construct(
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return T
     */
    public function map(Request $request, string $type): object
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new ApiException(JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE, 'UNSUPPORTED_MEDIA_TYPE', 'json_body_required');
        }

        try {
            $payload = $this->serializer->deserialize(
                $request->getContent(),
                $type,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (SerializerException $exception) {
            throw new ApiException(JsonResponse::HTTP_BAD_REQUEST, 'INVALID_REQUEST_BODY', 'invalid_request_body', previous: $exception);
        }

        $violations = $this->validator->validate($payload);
        if (0 === $violations->count()) {
            return $payload;
        }

        $fields = [];
        foreach ($violations as $violation) {
            $property = $violation->getPropertyPath();
            $fields[$property][] = (string)$violation->getMessage();
        }

        throw new ApiException(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED', 'validation_failed', $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeObject(Request $request): array
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new ApiException(JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE, 'UNSUPPORTED_MEDIA_TYPE', 'json_body_required');
        }

        try {
            $payload = json_decode(
                $request->getContent(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new ApiException(JsonResponse::HTTP_BAD_REQUEST, 'INVALID_REQUEST_BODY', 'invalid_request_body', previous: $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new ApiException(JsonResponse::HTTP_BAD_REQUEST, 'INVALID_REQUEST_BODY', 'json_object_required');
        }

        return $payload;
    }
}
