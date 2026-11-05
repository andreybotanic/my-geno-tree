<?php

namespace App\Tests\Api\Tree;

use App\Application\Chat\Chat;
use App\Model\Conversation;
use App\Tests\Api\ApiWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\Component\Uid\Uuid;

final class ChatApiTest extends ApiWebTestCase
{
    /** @var array<string, string> */
    private array $headers;
    private string $treeId;
    private string $base;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration());
        $this->headers = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('POST', '/api/v1/trees', ['name' => 'Chat'], $this->headers);
        $this->treeId = $this->responseString('id');
        $this->base = '/api/v1/trees/' . $this->treeId . '/chat';
        $this->jsonRequest('GET', $this->base, headers: $this->headers);
        self::assertNull($this->responseData()['conversation']);
        $this->jsonRequest('POST', $this->base, ['referencePersonId' => null], $this->headers);
        self::assertResponseStatusCodeSame(201);
        $this->path = '/api/v1/chats/' . $this->responseString('id');
    }

    public function testMessagesAreStoredOnceAndResetDeletesHistoryAndCurrentIndex(): void
    {
        $message = ['version' => 1, 'requestId' => 'first', 'content' => 'Мама Ирины — Вера.'];
        $this->jsonRequest('POST', $this->path . '/messages', $message, $this->headers);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->responseData()['version']);
        self::assertSame('idle', $this->responseString('status'));
        $saved = $this->responseData();
        $this->jsonRequest('POST', $this->path . '/messages', $message, $this->headers);
        self::assertSame($saved, $this->responseData());
        $this->jsonRequest('GET', $this->base, headers: $this->headers);
        self::assertSame($saved, $this->responseData()['conversation']);
        $this->jsonRequest('POST', $this->path . '/messages', array_replace($message, ['content' => 'Different']), $this->headers);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CHAT_REQUEST_CONFLICT', $this->responseString('code'));
        $this->jsonRequest('POST', $this->path . '/messages', array_replace($message, ['requestId' => 'other']), $this->headers);
        self::assertResponseStatusCodeSame(409);
        $this->jsonRequest('POST', $this->path . '/reset', ['version' => 1], $this->headers);
        self::assertResponseStatusCodeSame(409);
        $this->jsonRequest('POST', $this->path . '/reset', ['version' => 2], $this->headers);
        self::assertResponseStatusCodeSame(204);
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', $this->base, headers: $this->headers);
        self::assertNull($this->responseData()['conversation']);
        $this->jsonRequest('POST', $this->base, ['referencePersonId' => null], $this->headers);
        self::assertNotSame(basename($this->path), $this->responseString('id'));
        self::assertSame([], $this->responseArray('messages'));
        $this->jsonRequest('GET', '/api/v1/trees/' . $this->treeId . '/snapshot', headers: $this->headers);
        self::assertSame([], $this->responseData()['people']);
    }

    public function testInputAndProcessShapesAndConversationLimitsAreValidated(): void
    {
        foreach ([[], ['unexpected' => true], ['referencePersonId' => 12], ['referencePersonId' => Uuid::v7()->toRfc4122()]] as $payload) {
            $this->jsonRequest('POST', $this->base, $payload, $this->headers);
            self::assertResponseStatusCodeSame([] === $payload ? 400 : 422);
        }
        foreach ([['unexpected' => true], ['version' => '1', 'requestId' => 'a', 'content' => 'Family'],
            ['version'                               => 1, 'requestId' => '', 'content' => 'Family'], ['version' => 1, 'requestId' => 'a', 'content' => ' '],
            ['version'                               => 1, 'requestId' => 'a', 'content' => str_repeat('Я', 10001)]] as $payload) {
            $this->jsonRequest('POST', $this->path . '/messages', $payload, $this->headers);
            self::assertResponseStatusCodeSame(422);
        }
        foreach ([['version' => 1], ['version' => '1', 'requestId' => 'x'], ['version' => 1, 'requestId' => 'x', 'extra' => true]] as $payload) {
            $this->jsonRequest('POST', $this->path . '/process', $payload, $this->headers);
            self::assertResponseStatusCodeSame(422);
        }
        $this->jsonRequest('POST', $this->path . '/process', ['version' => 1, 'requestId' => 'x'], $this->headers);
        self::assertResponseStatusCodeSame(409);
        $this->jsonRequest('POST', $this->path . '/stop', ['requestId' => ''], $this->headers);
        self::assertResponseStatusCodeSame(422);
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => 1, 'requestId' => 'full', 'content' => str_repeat('Я', 10000)], $this->headers);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => 2, 'requestId' => 'over', 'content' => 'Ещё'], $this->headers);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('CHAT_INPUT_LIMIT', $this->responseString('code'));
        $this->jsonRequest('POST', $this->path . '/stop', ['requestId' => 'obsolete'], $this->headers);
        self::assertResponseStatusCodeSame(409);
    }

    public function testThereIsOneConversationPerContextAndLoginWithNoDoctrineMapping(): void
    {
        $id = basename($this->path);
        $this->jsonRequest('POST', $this->base, ['referencePersonId' => null], $this->headers);
        self::assertSame($id, $this->responseString('id'));
        $this->jsonRequest('POST', $this->path . '/messages', ['version' => 1, 'requestId' => 'first', 'content' => 'Private first session.'], $this->headers);
        $this->jsonRequest('POST', '/api/v1/auth/login', ['identifier' => 'family_admin', 'password' => 'StrongPass1!']);
        $other = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        $this->jsonRequest('GET', $this->path, headers: $other);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', $this->base, headers: $other);
        self::assertNull($this->responseData()['conversation']);
        $this->jsonRequest('POST', $this->base, ['referencePersonId' => null], $other);
        self::assertNotSame($id, $this->responseString('id'));
        $otherPath = '/api/v1/chats/' . $this->responseString('id');
        $this->jsonRequest('POST', $otherPath . '/messages', ['version' => 1, 'requestId' => 'first', 'content' => 'Second session.'], $other);
        self::assertResponseIsSuccessful();
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertSame('Private first session.', $this->responseString('messages', '0', 'content'));
        $mappings = static::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata();
        self::assertNotContains(Conversation::class, array_column($mappings, 'name'));
        self::assertNotContains('tree_merge_conversation', array_map(static fn ($mapping) => $mapping->getTableName(), $mappings));
    }

    public function testDeletedMessageStoreItemImmediatelyMakesTheConversationUnavailable(): void
    {
        $store = static::getContainer()->get('ai.message_store.cache.chat');
        self::assertInstanceOf(MessageStoreInterface::class, $store);
        $id = Uuid::fromString(basename($this->path));
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $id);
        self::assertInstanceOf(Conversation::class, $store->load($id)->getMetadata()->get(Chat::METADATA));
        $store->delete($id);
        self::assertCount(0, $store->load($id));
        $this->jsonRequest('GET', $this->path, headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', $this->base, headers: $this->headers);
        self::assertNull($this->responseData()['conversation']);
    }

    public function testConversationAndTreeProfileRequireAuthorization(): void
    {
        $this->jsonRequest('GET', $this->path);
        self::assertResponseStatusCodeSame(401);
        $this->jsonRequest('GET', '/api/v1/chats/invalid', headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('GET', '/api/v1/chats/' . Uuid::v4()->toRfc4122(), headers: $this->headers);
        self::assertResponseStatusCodeSame(404);
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['username' => 'other', 'email' => 'other@example.test']));
        $other = ['Authorization' => 'Bearer ' . $this->responseString('accessToken')];
        foreach ([$this->path, $this->base] as $path) {
            $this->jsonRequest('GET', $path, headers: $other);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testTheSameChatRunsAnotherConfiguredAgentWithoutTreeToolsOrTreePrompt(): void
    {
        $this->client->disableReboot();
        $container = static::getContainer();
        $sessions = $container->get(EntityManagerInterface::class)->getRepository(\App\Entity\AuthSession::class)->findAll();
        self::assertCount(1, $sessions);
        $session = $sessions[0];
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->attributes->set(\App\Entity\AuthSession::class, $session);
        $requests = $container->get(\Symfony\Component\HttpFoundation\RequestStack::class);
        $requests->push($request);
        $tokens = $container->get(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $previous = $tokens->getToken();
        $tokens->setToken(new \Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken($session->getUser(), 'main', ['ROLE_USER']));
        $calls = 0;
        $container->set('chat_ai.client', new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url, array $options) use (&$calls): \Symfony\Component\HttpClient\Response\MockResponse {
            ++$calls;
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertEmpty($body['tools'] ?? []);
            self::assertStringNotContainsString('Tree context', $options['body']);

            return new \Symfony\Component\HttpClient\Response\MockResponse(json_encode([
                'id'      => 'general', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-5.6-luna',
                'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hello from the general profile.']]],
            ], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
        }));
        try {
            $chat = $container->get(Chat::class);
            $conversation = $chat->create('general', 'general', []);
            $service = $container->get(\App\Application\Chat\ConversationService::class);
            $conversation = $service->append($conversation, ['version' => 1, 'requestId' => 'hello', 'content' => 'Hello.']);
            $conversation = $service->process($conversation, $conversation->version, 'hello');
            self::assertSame(1, $calls);
            self::assertSame('general', $conversation->profile);
            self::assertSame([], $conversation->tools);
            self::assertSame('Hello from the general profile.', $chat->messages($conversation)[1]['content']);
            self::assertNull($request->attributes->get(\App\Security\ChatExecution::class));
        } finally {
            $tokens->setToken($previous);
            $requests->pop();
        }
    }
}
