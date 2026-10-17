<?php

namespace App\Services\Uazapi;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Throwable;

class VoiceAudioConverter
{
    public function convert(UploadedFile $file): array
    {
        $directory = sys_get_temp_dir().'/chait-voice-'.bin2hex(random_bytes(16));
        $source = $file->getRealPath();
        $output = $directory.'/voice.ogg';

        try {
            if (! mkdir($directory, 0700)) {
                $this->invalid('Não foi possível preparar o áudio. Tente novamente.');
            }

            $input = $this->probe($source);
            $streams = $input['streams'] ?? [];
            $audio = array_filter($streams, fn ($stream) => ($stream['codec_type'] ?? null) === 'audio');
            $video = array_filter($streams, fn ($stream) => ($stream['codec_type'] ?? null) === 'video');
            $format = (string) data_get($input, 'format.format_name');
            if (! $audio || $video || ! preg_match('/\b(webm|ogg|mp4)\b/', $format)) {
                $this->invalid('Envie uma gravação de áudio WebM, OGG ou MP4, sem vídeo.');
            }

            $inputDuration = data_get($input, 'format.duration');
            if (is_numeric($inputDuration) && (round((float) $inputDuration, 1) < 1 || round((float) $inputDuration, 1) > 120)) {
                $this->invalid('O áudio deve durar entre 1 segundo e 2 minutos.');
            }

            // Browser WebM recordings may omit duration. Decode at most 121 seconds,
            // then inspect the result to reject a recording longer than 120 seconds.
            $result = Process::timeout(15)->run([
                config('services.voice.ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-protocol_whitelist', 'file,pipe', '-i', $source, '-map', '0:a:0', '-vn',
                '-t', '121', '-c:a', 'libopus', '-ac', '1', '-ar', '48000', '-b:a', '32k',
                '-threads', '2', '-f', 'ogg', $output,
            ]);

            if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
                $this->invalid('Não foi possível converter a gravação. Verifique o áudio e tente novamente.');
            }
            chmod($output, 0600);
            $converted = $this->probe($output);
            // Account for Opus frame padding rather than treating it as recording time.
            $duration = round((float) data_get($converted, 'format.duration', 0), 1);
            if ($duration < 1 || $duration > 120 || filesize($output) > 10 * 1024 * 1024) {
                $this->invalid('O áudio deve durar entre 1 segundo e 2 minutos e ter até 10 MB.');
            }

            return [
                'file' => 'data:audio/ogg;base64,'.base64_encode(file_get_contents($output)),
                'duration_seconds' => $duration,
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Process exceptions can contain filenames and stderr. Never let them
            // reach application logs or expose the recording in an error response.
            $this->invalid('Não foi possível processar a gravação. Verifique a disponibilidade de FFmpeg e FFprobe.');
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            if ($source && is_file($source)) {
                unlink($source);
            }
        }
    }

    private function probe(string $path): array
    {
        $result = Process::timeout(5)->run([
            config('services.voice.ffprobe'), '-v', 'error', '-protocol_whitelist', 'file,pipe',
            '-show_entries', 'format=format_name,duration:stream=codec_type', '-of', 'json', $path,
        ]);
        $data = json_decode($result->output(), true);
        if (! $result->successful() || ! is_array($data)) {
            $this->invalid('A gravação está inválida ou não pode ser decodificada.');
        }

        return $data;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
