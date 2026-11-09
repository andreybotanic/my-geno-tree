<?php

namespace App\Application\Chat\Voice;

use App\Api\ApiException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class AudioNormalizer
{
    public const int MAX_BYTES = 10 * 1024 * 1024;
    public const int MAX_DURATION_SECONDS = 180;

    public function normalize(UploadedFile $file): NormalizedRecording
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new ApiException(422, 'CHAT_AUDIO_SIZE');
        }
        $mime = $file->getMimeType();
        if (!in_array(
            $mime,
            [
                'audio/webm',
                'video/webm',
                'audio/ogg',
                'application/ogg',
                'audio/mp4',
                'video/mp4',
                'audio/x-m4a',
                'audio/wav',
                'audio/x-wav',
                'audio/vnd.wave',
            ],
            true
        )) {
            throw new ApiException(422, 'CHAT_AUDIO_FORMAT');
        }
        try {
            $probe = new Process(
                [
                    'ffprobe',
                    '-v',
                    'error',
                    '-protocol_whitelist',
                    'file,pipe',
                    '-show_entries',
                    'stream=codec_name,codec_type',
                    '-of',
                    'json',
                    $file->getPathname(),
                ],
                timeout: 10
            );
            $probe->mustRun();
            $info = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($info) || !is_array($info['streams'] ?? null) || 1 !== count($info['streams'])
                || !is_array($info['streams'][0] ?? null)
                || 'audio' !== ($info['streams'][0]['codec_type'] ?? null)
                || !in_array($info['streams'][0]['codec_name'] ?? null, ['opus', 'aac', 'pcm_s16le'], true)) {
                throw new \RuntimeException('Unsupported recording stream.');
            }
            $process = new Process(
                [
                    'ffmpeg',
                    '-nostdin',
                    '-v',
                    'error',
                    '-protocol_whitelist',
                    'file,pipe',
                    '-i',
                    $file->getPathname(),
                    '-map',
                    '0:a:0',
                    '-vn',
                    '-map_metadata',
                    '-1',
                    '-bitexact',
                    '-threads',
                    '2',
                    '-ac',
                    '1',
                    '-ar',
                    '16000',
                    '-c:a',
                    'pcm_s16le',
                    '-t',
                    (string)(self::MAX_DURATION_SECONDS + 1),
                    '-f',
                    'wav',
                    'pipe:1',
                ],
                timeout: 30
            );
            $process->mustRun();
            $wav = $process->getOutput();
            $data = strpos($wav, 'data', 12);
            if (false === $data || !str_starts_with($wav, 'RIFF')) {
                throw new \RuntimeException('Invalid normalized recording.');
            }
            $duration = (strlen($wav) - $data - 8) / 32000;
        } catch (\Throwable $error) {
            throw new ApiException(422, 'CHAT_AUDIO_FORMAT', previous: $error);
        }
        if ($duration <= 0 || $duration > self::MAX_DURATION_SECONDS) {
            throw new ApiException(422, 'CHAT_AUDIO_DURATION');
        }

        return new NormalizedRecording($wav, $mime, $duration);
    }
}
