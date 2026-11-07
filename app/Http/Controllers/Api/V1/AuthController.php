<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\WxUser;
use App\Services\WeChatService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Throwable;

class AuthController extends Controller
{
    public function login(LoginRequest $request, WeChatService $weChat): JsonResponse
    {
        try {
            $session = $weChat->code2session((string) $request->validated()['code']);
        } catch (Throwable $e) {
            report($e);

            return Api::fail(5020, '微信登录失败，请稍后重试', null, 502);
        }

        $user = WxUser::updateOrCreate(
            ['openid' => $session['openid']],
            ['unionid' => $session['unionid'] ?? null, 'last_active_at' => now()]
        );

        return Api::ok([
            'token' => $user->createToken('miniapp')->plainTextToken,
            'user' => ['id' => $user->id],
        ]);
    }
}
