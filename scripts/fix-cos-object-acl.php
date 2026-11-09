<?php

declare(strict_types=1);

/**
 * 把桶内 audio/ 与 cover/ 下所有对象的 ACL 重置为 default（继承桶权限）。
 *
 * 用途：修复「后台上传成功，但图片裂开 / 音频 403」——旧上传经 Flysystem 时
 * 带了 x-amz-acl: private，标准 COS 会把它当成对象级 ACL 生效，覆盖桶的公有读。
 * 代码已改为上传时不发 ACL 头；本脚本只负责清理**历史对象**。
 *
 * 用法（服务器项目根目录执行）：
 *   php scripts/fix-cos-object-acl.php             # dry-run，只列出待处理对象
 *   php scripts/fix-cos-object-acl.php --apply     # 真正修改
 *
 * 官方口径：PUT Object acl 的 x-cos-acl=default 表示继承桶权限、不创建对象级 ACL，
 * 因此不会消耗单账号 1000 条 ACL/Policy 配额。
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = in_array('--apply', $argv, true);

if (config('filesystems.disks.cos.driver') !== 's3') {
    fwrite(STDERR, "当前 cos 磁盘不是 s3 驱动（AUDIO_DRIVER=local），无需修复。\n");
    exit(1);
}

/** @var \Aws\S3\S3Client $client */
$client = Storage::disk('cos')->getClient();
$bucket = (string) config('filesystems.disks.cos.bucket');

$done = 0;
$failed = 0;

foreach (['audio/', 'cover/'] as $prefix) {
    $pages = $client->getPaginator('ListObjectsV2', [
        'Bucket' => $bucket,
        'Prefix' => $prefix,
    ]);

    foreach ($pages as $page) {
        foreach ($page['Contents'] ?? [] as $object) {
            $key = (string) $object['Key'];

            if (! $apply) {
                echo "  [dry-run] {$key}\n";
                ++$done;

                continue;
            }

            try {
                $client->putObjectAcl([
                    'Bucket' => $bucket,
                    'Key'    => $key,
                    'ACL'    => 'default',
                ]);
                ++$done;
                echo "  [fixed] {$key}\n";
            } catch (Throwable $e) {
                ++$failed;
                fwrite(STDERR, "  [failed] {$key}: ".$e->getMessage()."\n");
            }
        }
    }
}

echo $apply
    ? "完成：成功 {$done} 个，失败 {$failed} 个。\n"
    : "dry-run：共 {$done} 个对象。确认无误后加 --apply 执行。\n";

exit($failed > 0 ? 1 : 0);
