<?php

namespace App\Tests\Unit\Application\TreeMerge;

use App\Application\TreeMerge\Ai\AcpTreeMergeAi;
use App\Application\TreeMerge\Ai\MergePrompt;
use App\Application\TreeMerge\Ai\MergeTokenBudget;
use App\Application\TreeMerge\MergeActivity;
use App\Entity\AuthSession;
use App\Entity\FamilyTree;
use App\Entity\TreeMergeDraft;
use App\Entity\User;
use App\Security\TreeMergeAgentGrant;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class AcpTreeMergeAiTest extends KernelTestCase
{
    public function testOrdinaryChatSelectsConfiguredAcpAgent(): void
    {
        self::bootKernel();
        self::assertInstanceOf(AcpTreeMergeAi::class, self::getContainer()->get('app.tree_merge.agent'));
    }

    /** @return iterable<string, array{int}> */
    public static function exits(): iterable
    {
        yield 'completed' => [0];
        yield 'failed' => [1];
    }

    #[DataProvider('exits')]
    public function testEachMessageGetsAnIsolatedGrantAndOnlyMcpCanWrite(int $exitCode): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $now = new \DateTimeImmutable();
        $owner = new User('acp', 'acp@example.test', 'hash', 'ru', $now);
        $draft = new TreeMergeDraft(new FamilyTree($owner, 'Tree', $now), null, 'etag', $now);
        $draft->addMessage('first', 'Добавь двух безымянных сыновей.', $now);
        $request = new Request();
        $request->attributes->set(AuthSession::class, new AuthSession($owner, 'hash', $now->modify('+1 hour'), $now->modify('+1 day'), $now));
        $requests = $container->get(RequestStack::class);
        $requests->push($request);
        $grants = $container->get(TreeMergeAgentGrant::class);
        $capture = tempnam(sys_get_temp_dir(), 'acp-capture-');
        self::assertIsString($capture);
        $files = new Filesystem();
        $agent = new AcpTreeMergeAi($grants, $container->get(MergeTokenBudget::class), $files,
            [PHP_BINARY, dirname(__DIR__, 3) . '/Infrastructure/Ai/acpx-fixture.php', $capture, (string)$exitCode],
            'configured-acp-command', 'configured-model', 'http://localhost:17082/mcp/agent');
        $previous = null;
        try {
            foreach (['first', 'second'] as $attempt) {
                $before = $draft->messages;
                try {
                    $agent->respond($draft, $attempt);
                    self::assertSame(0, $exitCode);
                } catch (\RuntimeException $error) {
                    self::assertSame(1, $exitCode);
                    self::assertSame('ACP session failed.', $error->getMessage());
                }
                /** @var array{input: string, arguments: list<string>, home: string, config: array{mcpServers: list<array{url: string, headers: list<array{name: string, value: string}>}>}} $captured */
                $captured = json_decode((string)file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(MergePrompt::SYSTEM . "\n\n" . MergePrompt::input($draft), $captured['input']);
                self::assertContains('configured-acp-command', $captured['arguments']);
                self::assertContains('configured-model', $captured['arguments']);
                self::assertContains('--no-fs', $captured['arguments']);
                self::assertContains('--no-terminal', $captured['arguments']);
                self::assertContains('--deny-all', $captured['arguments']);
                self::assertContains('exec', $captured['arguments']);
                self::assertDirectoryDoesNotExist($captured['home']);
                $server = $captured['config']['mcpServers'][0];
                self::assertSame('http://localhost:17082/mcp/agent', $server['url']);
                self::assertSame('Authorization', $server['headers'][0]['name']);
                $token = substr($server['headers'][0]['value'], strlen('Merge '));
                self::assertNotSame($previous, $token);
                self::assertSame($before, $draft->messages);
                try {
                    $grants->consume($token);
                    self::fail('The message credential must be revoked.');
                } catch (AuthenticationException) {
                    $previous = $token;
                }
            }
            self::assertSame(60, $container->get(MergeActivity::class)->read($draft)['usage']['total']);
        } finally {
            $requests->pop();
            $files->remove($capture);
        }
    }
}
