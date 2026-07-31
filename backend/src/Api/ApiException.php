<?php

namespace App\Api;

use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $fields
     * @param array<string, mixed>        $details
     */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        private readonly ?string $messageKey = null,
        private readonly array $fields = [],
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, previous: $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getMessageKey(): ?string
    {
        return $this->messageKey;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
