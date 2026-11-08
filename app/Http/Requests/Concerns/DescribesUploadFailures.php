<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait DescribesUploadFailures
{
    /**
     * 把 PHP 的 $_FILES error code 翻译成可执行的中文提示。
     * ini_get() 读的是本次 FPM 请求真实生效的值，跟命令行 php -i 可能不同。
     */
    protected function uploadFailureMessage(string $field): string
    {
        $file = $this->file($field);
        $error = $file?->getError();
        $single = (string) ini_get('upload_max_filesize');
        $post = (string) ini_get('post_max_size');

        $reason = match ($error) {
            UPLOAD_ERR_INI_SIZE => "文件超过 PHP 的 upload_max_filesize（当前 {$single}）",
            UPLOAD_ERR_FORM_SIZE => '文件超过表单声明的 MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分（上传超时或网络中断）',
            UPLOAD_ERR_NO_FILE => '请求里没有该文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器临时目录不可写',
            UPLOAD_ERR_EXTENSION => 'PHP 扩展中止了上传',
            default => $file === null
                ? "未收到文件（post_max_size 当前 {$post}，过小会让 PHP 丢弃整个请求体）"
                : '未知上传错误（code=' . var_export($error, true) . '）',
        };

        return "上传失败：{$reason}。当前 PHP-FPM 限制 upload_max_filesize={$single}、post_max_size={$post}；改完 php.ini 必须 reload php-fpm，详见 README 第 3 节。";
    }

    /**
     * @param  array<string, string>  $fields  字段名 => 业务大小上限文案
     * @return array<string, string>
     */
    protected function uploadMessages(array $fields): array
    {
        $messages = [];

        foreach ($fields as $field => $maxLabel) {
            $messages[$field.'.uploaded'] = $this->uploadFailureMessage($field);
            $messages[$field.'.required'] = "未收到 {$field} 文件：可能表单未选文件，或 post_max_size（当前 ".ini_get('post_max_size').'）过小导致 PHP 丢弃了整个请求体。';
            $messages[$field.'.max'] = "文件不能超过 {$maxLabel}。";
            $messages[$field.'.file'] = '请上传有效的文件。';
            $messages[$field.'.mimes'] = '文件格式不支持。';
        }

        return $messages;
    }
}
