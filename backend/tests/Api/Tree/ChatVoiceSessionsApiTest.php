<?php

namespace App\Tests\Api\Tree;

use App\Application\Chat\Moderation\ChatModeration;
use App\Entity\AuthSession;
use App\Entity\ChatBan;
use App\Entity\User;
use App\Tests\Api\ApiWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

final class ChatVoiceSessionsApiTest extends ApiWebTestCase
{
    private MockClock $clock;
    private string $token;
    private string $userId;
    private string $id;
    private string $credential;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        Clock::set($this->clock);
        parent::setUp();
        $this->client->disableReboot();
        static::getContainer()->get('cache.chat_access')->deleteItem('chat_moderation_unavailable');
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['language' => 'ru']));
        $this->token = $this->responseString('accessToken');
        $this->userId = $this->responseString('user', 'id');
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('cache.chat_access')->deleteItem('chat_moderation_unavailable');
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testIssuesOneUseScopedCredentialAndReleasesIt(): void
    {
        $this->issue();
        self::assertSame('7', $this->id[14]);
        self::assertSame('/api/v1/chat-transcriptions/stream', $this->responseString('url'));
        self::assertSame(24000, $this->responseInt('sampleRate'));
        self::assertSame(90, $this->responseInt('maxDurationSeconds'));
        self::assertSame(30, $this->responseInt('expiresIn'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control') ?? '');
        $this->authorize('check');
        self::assertResponseStatusCodeSame(401);
        $this->authorize('claim');
        self::assertResponseIsSuccessful();
        self::assertSame('ru', $this->responseString('language'));
        $this->authorize('claim');
        self::assertResponseStatusCodeSame(401);
        $this->authorize('check');
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', '/api/v1/trees', headers: ['Authorization' => 'Bearer ' . $this->credential]);
        self::assertResponseStatusCodeSame(401);
        $this->authorize('release');
        self::assertResponseIsSuccessful();
        $this->authorize('check');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsMissingAndMalformedCredentialsAndDifferentSessionIds(): void
    {
        $this->jsonRequest('POST', '/api/v1/chat-transcription-sessions');
        self::assertResponseStatusCodeSame(401);
        $this->issue();
        foreach (['', 'not-a-grant', $this->id . '.wrong', $this->id . '.' . str_repeat('a', 64)] as $credential) {
            $this->jsonRequest(
                'POST',
                '/api/v1/chat-transcription-sessions/' . $this->id . '/authorize',
                ['operation'     => 'claim'],
                ['Authorization' => 'Bearer ' . $credential],
            );
            self::assertResponseStatusCodeSame(401);
        }
        $id = $this->id;
        $this->id = Uuid::v7()->toRfc4122();
        $this->authorize('claim');
        self::assertResponseStatusCodeSame(401);
        $this->id = $id;
        $this->authorize('claim');
        self::assertResponseIsSuccessful();
    }

    public function testRejectsUnknownFieldsAndOperations(): void
    {
        $this->client->request(
            'POST',
            'https://localhost/api/v1/chat-transcription-sessions',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token],
            content: '[]',
        );
        self::assertResponseStatusCodeSame(400);
        $this->jsonRequest(
            'POST',
            '/api/v1/chat-transcription-sessions',
            ['treeId'        => 'unwanted'],
            ['Authorization' => 'Bearer ' . $this->token],
        );
        self::assertResponseStatusCodeSame(422);
        $this->issue();
        $this->authorize('unknown');
        self::assertResponseStatusCodeSame(422);
        $this->jsonRequest(
            'POST',
            '/api/v1/chat-transcription-sessions/' . $this->id . '/authorize',
            ['operation'     => 'claim', 'extra' => true],
            ['Authorization' => 'Bearer ' . $this->credential],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testExpiresUnclaimedAndActiveCredentials(): void
    {
        $this->issue();
        $this->clock->sleep(30);
        $this->authorize('claim');
        self::assertResponseStatusCodeSame(401);
        $this->issue();
        $this->authorize('claim');
        self::assertResponseIsSuccessful();
        $this->clock->sleep(300);
        $this->authorize('check');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRevocationImmediatelyInvalidatesAnActiveRecording(): void
    {
        $this->issue();
        $this->authorize('claim');
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $session = $manager->getRepository(AuthSession::class)->findOneBy(['user' => $this->userId]);
        self::assertInstanceOf(AuthSession::class, $session);
        $session->revoke($this->clock->now());
        $manager->flush();
        $this->authorize('check');
        self::assertResponseStatusCodeSame(401);
    }

    public function testBanAndModeratorOutageBlockCreationAndActiveStreams(): void
    {
        $this->issue();
        $this->authorize('claim');
        $moderation = static::getContainer()->get(ChatModeration::class);
        $moderation->unavailable();
        $this->authorize('check');
        self::assertResponseStatusCodeSame(503);
        static::getContainer()->get('cache.chat_access')->deleteItem('chat_moderation_unavailable');
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $manager->find(User::class, $this->userId);
        self::assertInstanceOf(User::class, $user);
        for ($i = 0; $i < 3; ++$i) {
            $ban = $user->warnAboutChat($this->clock->now(), 3600, 2);
        }
        self::assertInstanceOf(ChatBan::class, $ban);
        $manager->persist($ban);
        $manager->flush();
        $this->authorize('check');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('CHAT_BANNED', $this->responseString('code'));
        $this->createRecording();
        self::assertResponseStatusCodeSame(403);
    }

    public function testSharesTheExistingVoiceRateLimiter(): void
    {
        $limiter = static::getContainer()->get('limiter.chat_voice')->create($this->userId);
        $limiter->consume(9);
        $this->issue();
        $this->createRecording();
        self::assertResponseStatusCodeSame(429);
        self::assertSame('CHAT_VOICE_RATE_LIMIT', $this->responseString('code'));
    }

    private function issue(): void
    {
        $this->createRecording();
        self::assertResponseStatusCodeSame(201);
        $this->id = $this->responseString('id');
        $this->credential = $this->responseString('credential');
    }

    private function createRecording(): void
    {
        $this->client->request(
            'POST',
            'https://localhost/api/v1/chat-transcription-sessions',
            server: [
                'HTTPS'              => 'on',
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ],
            content: '{}',
        );
    }

    private function authorize(string $operation): void
    {
        $this->jsonRequest(
            'POST',
            '/api/v1/chat-transcription-sessions/' . $this->id . '/authorize',
            ['operation'     => $operation],
            ['Authorization' => 'Bearer ' . $this->credential],
        );
    }
}
