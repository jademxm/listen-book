<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AudioStorageService
{
    private const MAX_AUDIO_BYTES = 104857600; // 100 MB
    private const MAX_COVER_BYTES = 5242880;  // 5 MB

    /** @return array{object_key: string, mime: string, size_bytes: int} */
    public function storeAudio(UploadedFile $file, int $albumId): array
    {
        if (! $file->isValid()) {
            throw new InvalidArgumentException('上传失败：'.$file->getErrorMessage());
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['mp3', 'm4a', 'aac', 'wav'], true)) {
            throw new InvalidArgumentException('仅支持 mp3 / m4a / aac / wav');
        }

        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_AUDIO_BYTES) {
            throw new InvalidArgumentException('音频大小需在 0 ~ 100MB 之间');
        }

        if (! $this->matchesSignature($this->readHead($file), $ext)) {
            throw new InvalidArgumentException('文件内容与音频格式不匹配');
        }

        $key = sprintf('audio/%d/%s.%s', $albumId, (string) Str::ulid(), $ext);
        $this->put($key, $file);

        return [
            'object_key' => $key,
            'mime' => (string) $file->getClientMimeType(),
            'size_bytes' => $size,
        ];
    }

    public function storeCover(UploadedFile $file, int $albumId): ?string
    {
        if (! $file->isValid()) {
            return null;
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new InvalidArgumentException('封面仅支持 jpg / png / webp');
        }
        if ((int) $file->getSize() > self::MAX_COVER_BYTES) {
            throw new InvalidArgumentException('封面不能超过 5MB');
        }
        if (@getimagesize($file->getRealPath()) === false) {
            throw new InvalidArgumentException('封面不是有效图片');
        }

        $key = sprintf('cover/%d/%s.%s', $albumId, (string) Str::ulid(), $ext);
        $this->put($key, $file);

        return $key;
    }

    public function url(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        return rtrim((string) config('services.cos.cdn_domain'), '/').'/'.ltrim($key, '/');
    }

    public function toUrl(?string $value): ?string
    {
        if (! $value || str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return $this->url($value);
    }

    private function put(string $key, UploadedFile $file): void
    {
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法读取上传文件');
        }

        try {
            if (! Storage::disk('cos')->put($key, $stream)) {
                throw new RuntimeException('对象存储写入失败');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function readHead(UploadedFile $file): string
    {
        $head = @file_get_contents((string) $file->getRealPath(), false, null, 0, 16);

        return $head === false ? '' : $head;
    }

    private function matchesSignature(string $head, string $ext): bool
    {
        $frameSync = strlen($head) >= 2 && ord($head[0]) === 0xFF;

        return match ($ext) {
            'mp3' => str_starts_with($head, 'ID3') || ($frameSync && (ord($head[1]) & 0xE0) === 0xE0),
            'wav' => str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE',
            default => substr($head, 4, 4) === 'ftyp' || ($frameSync && (ord($head[1]) & 0xF0) === 0xF0),
        };
    }
}
