<?php

namespace App\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND)]
final readonly class PreventFixturePurgeListener
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        if (
            'test' === $this->environment
            || 'doctrine:fixtures:load' !== $event->getCommand()?->getName()
            || true === $event->getInput()->getOption('append')
        ) {
            return;
        }

        throw new \LogicException('Run doctrine:fixtures:load with --append outside the test environment.');
    }
}
