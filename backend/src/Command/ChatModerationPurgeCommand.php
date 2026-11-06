<?php

namespace App\Command;

use App\Application\Chat\Moderation\ModerationPolicy;
use App\Repository\SuspiciousChatMessageRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsCommand(name: 'app:chat-moderation:purge', description: 'Remove expired suspicious chat messages.')]
#[AsPeriodicTask(frequency: '1 hour')]
final class ChatModerationPurgeCommand extends Command
{
    public function __construct(
        private readonly SuspiciousChatMessageRepository $messages,
        private readonly ModerationPolicy $policy,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->messages->purgeBefore(
            $this->clock->now()->modify('-' . $this->policy->retentionDays . ' days'),
        );
        $output->writeln('Removed ' . $count . ' expired suspicious chat messages.');

        return Command::SUCCESS;
    }
}
