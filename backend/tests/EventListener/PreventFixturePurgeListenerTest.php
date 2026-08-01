<?php

namespace App\Tests\EventListener;

use App\EventListener\PreventFixturePurgeListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

final class PreventFixturePurgeListenerTest extends TestCase
{
    public function testRejectsFixturePurgeOutsideTheTestEnvironment(): void
    {
        $listener = new PreventFixturePurgeListener('dev');

        $this->expectException(\LogicException::class);
        $listener($this->event());
    }

    #[DataProvider('allowedCommands')]
    public function testAllowsSafeFixtureCommands(
        string $environment,
        string $commandName,
        bool $append,
    ): void {
        $listener = new PreventFixturePurgeListener($environment);

        self::expectNotToPerformAssertions();
        $listener($this->event($commandName, $append));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function allowedCommands(): iterable
    {
        yield 'append in development' => ['dev', 'doctrine:fixtures:load', true];
        yield 'test database fixture purge' => ['test', 'doctrine:fixtures:load', false];
        yield 'unrelated command' => ['dev', 'cache:clear', false];
    }

    private function event(
        string $commandName = 'doctrine:fixtures:load',
        bool $append = false,
    ): ConsoleCommandEvent {
        return new ConsoleCommandEvent(
            new Command($commandName),
            new ArrayInput(
                ['--append' => $append],
                new InputDefinition([new InputOption('append')]),
            ),
            new BufferedOutput(),
        );
    }
}
