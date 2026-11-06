<?php

namespace App\Application\Chat\Moderation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Validated application policy; changes apply only to future decisions. */
final readonly class ModerationPolicy
{
    public function __construct(
        #[Autowire('%app.chat_moderation.ban_seconds%')] public int $banSeconds,
        #[Autowire('%app.chat_moderation.penalty_tokens%')] public int $penaltyTokens,
        #[Autowire('%app.chat_moderation.temporary_bans%')] public int $temporaryBans,
        #[Autowire('%app.chat_moderation.audit_threshold%')] public float $auditThreshold,
        #[Autowire('%app.chat_moderation.warning_threshold%')] public float $warningThreshold,
        #[Autowire('%app.chat_moderation.retention_days%')] public int $retentionDays,
    ) {
        if ($banSeconds <= 0 || $penaltyTokens < 0 || $temporaryBans < 0 || $retentionDays <= 0
            || !is_finite($auditThreshold) || !is_finite($warningThreshold)
            || $auditThreshold <= 0 || $auditThreshold > $warningThreshold || $warningThreshold > 1) {
            throw new \InvalidArgumentException('Invalid chat moderation policy.');
        }
    }
}
