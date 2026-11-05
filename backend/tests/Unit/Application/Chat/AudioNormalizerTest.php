<?php

namespace App\Tests\Unit\Application\Chat;

use App\Api\ApiException;
use App\Application\Chat\Voice\AudioNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class AudioNormalizerTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function formats(): iterable
    {
        yield ['webm', 'libopus'];
        yield ['ogg', 'libopus'];
        yield ['mp4', 'aac'];
        yield ['wav', 'pcm_s16le'];
    }

    #[DataProvider('formats')]
    public function testDecodesRealBrowserContainersToMonoPcm(string $format, string $codec): void
    {
        $path = tempnam(sys_get_temp_dir(), 'merge-audio');
        try {
            (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
                '-ac', '2', '-ar', '48000', '-c:a', $codec, '-f', $format, $path]))->mustRun();
            $result = new AudioNormalizer()->normalize(new UploadedFile($path, 'misleading.txt', 'text/plain', test: true));
            self::assertStringStartsWith('RIFF', $result->wav);
            self::assertEqualsWithDelta(1, $result->durationSeconds, .05);
            $rate = unpack('Vrate', substr($result->wav, 24, 4));
            $channels = unpack('vchannels', substr($result->wav, 22, 2));
            self::assertIsArray($rate);
            self::assertIsArray($channels);
            self::assertSame(16000, $rate['rate']);
            self::assertSame(1, $channels['channels']);
        } finally {
            unlink($path);
        }
    }

    public function testRejectsOversizeInvalidAndLongRecordings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'merge-audio');
        try {
            foreach ([['bad', 'CHAT_AUDIO_FORMAT'], [str_repeat('x', AudioNormalizer::MAX_BYTES + 1), 'CHAT_AUDIO_SIZE']] as [$bytes, $code]) {
                file_put_contents($path, $bytes);
                $this->reject($path, $code);
            }
            unset($bytes);
            (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=16000:cl=mono', '-t', '91', '-f', 'wav', $path]))->mustRun();
            $this->reject($path, 'CHAT_AUDIO_DURATION');
            (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=16000:cl=mono', '-t', '90', '-f', 'wav', $path]))->mustRun();
            self::assertSame(90.0, new AudioNormalizer()->normalize(new UploadedFile($path, 'audio.wav', test: true))->durationSeconds);
            (new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=duration=1', '-c:a', 'libvorbis', '-f', 'ogg', $path]))->mustRun();
            $this->reject($path, 'CHAT_AUDIO_FORMAT');
            // A recognizable container with damaged streams must not reach the provider.
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            file_put_contents($path, substr($contents, 0, 128));
            $this->reject($path, 'CHAT_AUDIO_FORMAT');
        } finally {
            unlink($path);
        }
    }

    private function reject(string $path, string $code): void
    {
        try {
            new AudioNormalizer()->normalize(new UploadedFile($path, 'recording', test: true));
            self::fail('Recording accepted');
        } catch (ApiException $error) {
            self::assertSame($code, $error->getErrorCode());
        }
    }
}
