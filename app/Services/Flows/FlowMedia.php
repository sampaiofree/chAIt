<?php

namespace App\Services\Flows;

use App\Models\FlowAsset;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FlowMedia
{
    public static function type(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/png', 'image/webp', 'image/gif' => 'image',
            'video/mp4' => 'video',
            'audio/mpeg', 'audio/ogg', 'application/ogg' => 'audio',
            'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain' => 'document',
            default => null,
        };
    }

    public static function validExtension(string $name, string $mime): bool
    {
        $extensions = match ($mime) {
            'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp'], 'image/gif' => ['gif'],
            'video/mp4' => ['mp4'], 'audio/mpeg' => ['mp3'], 'audio/ogg', 'application/ogg' => ['ogg'],
            'application/pdf' => ['pdf'], 'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'text/plain' => ['txt'], default => [],
        };

        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true);
    }

    public function contents(FlowAsset $asset): string
    {
        if ($asset->disk !== 'local' || ! Storage::disk($asset->disk)->exists($asset->path)
            || Storage::disk($asset->disk)->size($asset->path) > 20 * 1024 * 1024) {
            throw new RuntimeException('Mídia indisponível.');
        }
        $bytes = Storage::disk($asset->disk)->get($asset->path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! $bytes || strlen($bytes) > 20 * 1024 * 1024 || $mime !== $asset->mime_type
            || ! self::type($mime) || ! self::validExtension($asset->original_name, $mime)) {
            throw new RuntimeException('Mídia inválida.');
        }

        return $bytes;
    }
}
