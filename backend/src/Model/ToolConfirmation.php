<?php

namespace App\Model;

use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;

#[Exclude]
final class ToolConfirmation implements \JsonSerializable
{
    public readonly string $id;
    public string $status = 'pending';
    /** @var array<string, mixed>|null */
    public ?array $result = null;

    /** @param array<string, mixed> $summary */
    public function __construct(
        public readonly ToolCall $call,
        public readonly string $requestId,
        public readonly array $summary,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id'        => $this->id,
            'requestId' => $this->requestId,
            'tool'      => $this->call->getName(),
            'summary'   => $this->summary,
            'status'    => $this->status,
            'result'    => $this->result,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
