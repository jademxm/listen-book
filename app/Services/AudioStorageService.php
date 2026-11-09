<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CoverUrl;
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

    /** 回滚清理：删除失败不应掩盖原始错误，也不应再次触发 SDK 异常导致 500。 */
    public function deleteQuietly(?string $key): void
    {
        if (! $key) {
            return;
        }

        try {
            Storage::disk('cos')->delete($key);
        } catch (\Throwable $e) {
            // 清理失败留待人工处理，主错误信息优先返回给后台
        }
    }

    /**
     * 批量删除桶对象：兼容相对 key 与历史完整 cover_url，去重后逐个删。
     * 单个失败（对象已不存在 / 权限不足）不影响其余，也不抛异常打断后台请求。
     *
     * @param  array<int, string|null>  $values
     * @return int 实际删除成功的对象数
     */
    public function deleteMany(array $values): int
    {
        $keys = [];
        foreach ($values as $value) {
            $key = CoverUrl::keyFrom($value);
            if ($key !== null) {
                $keys[$key] = $key;
            }
        }

        $deleted = 0;
        foreach ($keys as $key) {
            try {
                if (Storage::disk('cos')->delete($key)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // 对象可能已被手工删除；残留可在控制台核对，不掩盖主流程
            }
        }

        return $deleted;
    }

    /** 发网络请求前拦截配置缺失，避免 AWS SDK 抛出难懂的底层凭证异常。 */
    private function assertConfigured(): void
    {
        $disk = config('filesystems.disks.cos', []);

        if (($disk['driver'] ?? null) !== 's3') {
            return; // local 挂载模式不需要密钥
        }

        $missing = [];
        foreach ([
            'key' => 'COS_SECRET_ID',
            'secret' => 'COS_SECRET_KEY',
            'bucket' => 'COS_BUCKET',
            'endpoint' => 'COS_ENDPOINT',
            'url' => 'COS_CDN_DOMAIN',
        ] as $config => $envKey) {
            if (trim((string) ($disk[$config] ?? '')) === '') {
                $missing[] = $envKey;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                '对象存储未配置：.env 里的 '.implode('、', $missing).' 为空。'
                .'请填入腾讯云访问密钥与存储桶信息，或改 AUDIO_DRIVER=local 走服务器挂载目录；'
                .'改动后执行 php artisan config:clear。'
            );
        }

        foreach (['bucket' => 'COS_BUCKET', 'url' => 'COS_CDN_DOMAIN'] as $config => $envKey) {
            if (str_contains((string) $disk[$config], 'your-bucket-1250000000')) {
                throw new RuntimeException(
                    $envKey.' 仍是 .env.example 的模板占位值，请改成控制台里的真实存储桶名/访问域名（含 AppId 后缀）。'
                );
            }
        }
    }

    private function put(string $key, UploadedFile $file): void
    {
        $this->assertConfigured();

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('无法读取上传文件');
        }

        try {
            if (config('filesystems.disks.cos.driver') === 's3') {
                $this->putToCosWithoutAcl($key, $stream);
            } elseif (! Storage::disk('cos')->put($key, $stream)) {
                throw new RuntimeException('对象存储写入失败');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * 标准 COS 的 S3 兼容层会把 x-amz-acl: private 当成对象 ACL 生效，覆盖桶的
     * 「公有读私有写」，新上传的图片/音频一律 403。Flysystem 的 writeStream()
     * 无条件携带该头（AwsS3V3Adapter::upload() 默认 determineAcl = private），
     * 因此 s3 模式改走底层 S3Client：upload() 第 4 参数传 null 时完全不发 ACL 头
     * （分片上传的 CreateMultipartUpload 同理），对象保持 default 继承桶权限。
     *
     * 不用 visibility=public 修：那会变成逐对象 public-read ACL，撞腾讯云
     * 单账号 1000 条 ACL/Policy 配额，且桶权限此后对这些对象失效。
     */
    private function putToCosWithoutAcl(string $key, $stream): void
    {
        /** @var \Illuminate\Filesystem\AwsS3V3Adapter $disk */
        $disk = Storage::disk('cos');

        $disk->getClient()->upload(
            (string) config('filesystems.disks.cos.bucket'),
            $key,
            $stream,
            null,
            ['params' => ['ContentType' => $this->mimeType($key)]]
        );
    }

    /** 与 Flysystem 之前按扩展名推断 ContentType 的行为保持一致。 */
    private function mimeType(string $key): string
    {
        return match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
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
