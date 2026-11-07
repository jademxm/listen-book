<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WeChatService
{
    public function code2session(string $code): array
    {
        $response = Http::timeout(5)->retry(2, 200)->get('https://api.weixin.qq.com/sns/jscode2session', [
            'appid' => config('services.wechat.appid'),
            'secret' => config('services.wechat.secret'),
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('wechat http '.$response->status());
        }

        $data = $response->json();
        if (empty($data['openid'])) {
            throw new RuntimeException('wechat '.($data['errcode'] ?? -1).': '.($data['errmsg'] ?? 'unknown'));
        }

        return $data;
    }
}
