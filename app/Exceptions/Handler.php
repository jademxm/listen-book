<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Api;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // 后台接口的校验失败统一成 Api 结构，前端只需读 message
        $this->renderable(function (ValidationException $e, Request $request) {
            if ($request->is('api/admin/*')) {
                return Api::fail(42200, $e->validator->errors()->first(), [
                    'errors' => $e->errors(),
                ], 422);
            }
        });
    }
}
