<?php

namespace App\Tests\Api\Tree;

use App\Entity\MediaObject;
use App\Model\Conversation;
use App\Tests\Api\ApiWebTestCase;
use App\Tests\Infrastructure\Ai\FixtureTranscriptionProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class TreeMergeTranscriptionApiTest extends ApiWebTestCase
{
    private string $token;
    private string $audio;
    private string $uploaded;

    protected function setUp(): void
    {
        parent::setUp();
        FixtureTranscriptionProvider::$calls = 0;
        FixtureTranscriptionProvider::$response = 'Мама Ирины — Вера.';
        FixtureTranscriptionProvider::$handler = null;
        $this->jsonRequest('POST', '/api/v1/auth/register', $this->validRegistration(['language' => 'ru']));
        $this->token = $this->responseString('accessToken');
        $this->audio = tempnam(sys_get_temp_dir(), 'merge-voice');
        (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=duration=0.2', '-f', 'wav', $this->audio]))->mustRun();
    }

    protected function tearDown(): void
    {
        foreach ([$this->audio, $this->uploaded ?? ''] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        FixtureTranscriptionProvider::$handler = null;
        parent::tearDown();
    }

    public function testTranscribesWithoutATreeAndDeletesTheUploadWithoutCreatingMediaOrDrafts(): void
    {
        FixtureTranscriptionProvider::$handler = static function (string $wav, string $language): string {
            self::assertStringStartsWith('RIFF', $wav);
            self::assertSame('ru', $language);

            return 'Мама Ирины — Вера.';
        };
        $this->upload();
        self::assertResponseIsSuccessful();
        self::assertSame('Мама Ирины — Вера.', $this->responseString('text'));
        self::assertGreaterThan(0, $this->responseData()['durationSeconds']);
        self::assertFileDoesNotExist($this->uploaded);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(MediaObject::class)->count([]));
        self::assertNotContains(Conversation::class, array_column($em->getMetadataFactory()->getAllMetadata(), 'name'));
    }

    public function testFailureAndInvalidTranscriptDiscardAudioAndRequireANewRecording(): void
    {
        foreach ([new \RuntimeException('offline'), ' ', str_repeat('x', 10001)] as $reply) {
            FixtureTranscriptionProvider::$response = $reply;
            $this->upload();
            self::assertResponseStatusCodeSame(503);
            self::assertSame('CHAT_TRANSCRIPTION_UNAVAILABLE', $this->responseString('code'));
            self::assertFileDoesNotExist($this->uploaded);
        }
        $this->upload(file: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(3, FixtureTranscriptionProvider::$calls);
    }

    public function testRejectsUnauthenticatedMissingExtraAndInvalidAudio(): void
    {
        $token = $this->token;
        $this->token = '';
        $this->upload(file: false);
        self::assertResponseStatusCodeSame(401);
        $this->token = $token;
        $this->upload(['treeId' => 'unwanted']);
        self::assertResponseStatusCodeSame(422);
        $this->upload(file: false);
        self::assertResponseStatusCodeSame(422);
        file_put_contents($this->audio, 'not audio');
        $this->upload();
        self::assertResponseStatusCodeSame(422);
        self::assertFileDoesNotExist($this->uploaded);
        self::assertSame(0, FixtureTranscriptionProvider::$calls);
    }

    public function testLimitsRepeatedRequestsPerUser(): void
    {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $this->upload();
            self::assertResponseIsSuccessful();
        }
        $this->upload();
        self::assertResponseStatusCodeSame(429);
        self::assertSame(10, FixtureTranscriptionProvider::$calls);
    }

    /** @param array<string, mixed> $fields */
    private function upload(array $fields = [], bool $file = true): void
    {
        $files = [];
        if ($file) {
            $this->uploaded = tempnam(sys_get_temp_dir(), 'voice-request');
            copy($this->audio, $this->uploaded);
            $files['audio'] = new UploadedFile($this->uploaded, 'voice.wav', 'audio/wav', test: true);
        }
        $this->client->request(
            'POST',
            'https://localhost/api/v1/chat-transcriptions',
            $fields,
            $files,
            ['HTTPS' => 'on', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token]
        );
    }
}
