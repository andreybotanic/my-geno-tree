<?php

namespace App\Model;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/** Application state carried by Symfony AI's MessageBag metadata. */
#[Exclude]
final class Conversation
{
    public const string METADATA = 'conversation';
    public readonly UuidV7 $id;
    public int $version = 1;
    public string $status = 'idle';
    public ?string $attemptId = null;
    public ?string $attemptRequestId = null;
    public ?\DateTimeImmutable $attemptStartedAt = null;
    public \DateTimeImmutable $updatedAt;
    /** @var array<string, object> */
    public array $tools = [];
    /** @var array<string, int> */
    public array $toolVersions = [];
    /** @var list<string> */
    public array $progress = [];
    /** @var array{input: int, output: int, total: int} */
    public array $usage = ['input' => 0, 'output' => 0, 'total' => 0];

    /** @param array<string, string|null> $context */
    public function __construct(
        public readonly string $contextKey,
        public readonly string $profile,
        public readonly array $context,
        public readonly Uuid $sessionId,
        public readonly Uuid $userId,
        public readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->updatedAt = $createdAt;
    }

    public function changed(\DateTimeImmutable $now): void
    {
        ++$this->version;
        $this->updatedAt = $now;
    }

    public function beginAttempt(\DateTimeImmutable $now, string $requestId): void
    {
        $this->attemptId = Uuid::v7()->toRfc4122();
        $this->attemptRequestId = $requestId;
        $this->attemptStartedAt = $now;
        $this->status = 'processing';
        $this->progress = [];
        $this->usage = ['input' => 0, 'output' => 0, 'total' => 0];
        $this->changed($now);
    }

    public function finishAttempt(\DateTimeImmutable $now, bool $failed = false): void
    {
        $this->status = $failed ? 'error' : 'idle';
        $this->changed($now);
    }

    public function invalidateAttempt(\DateTimeImmutable $now): void
    {
        $this->attemptId = $this->attemptRequestId = null;
        $this->attemptStartedAt = null;
        $this->status = 'idle';
        $this->changed($now);
    }
}
