<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminOperationLog;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 操作日志只读查询；写记录由 LogAdminOperation 中间件统一完成。 */
class OperationLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 30);
        $perPage = $perPage < 1 ? 30 : min($perPage, 100);

        $from = (string) $request->input('from', '');
        $to = (string) $request->input('to', '');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';

        $paginator = AdminOperationLog::query()
            ->when($request->filled('admin_id'), fn ($q) => $q->where('admin_id', (int) $request->input('admin_id')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', (string) $request->input('action')))
            ->when($request->filled('success'), fn ($q) => $q->where('success', (int) $request->input('success') === 1))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $like = '%'.$request->input('keyword').'%';
                $q->where(fn ($sub) => $sub->where('summary', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('path', 'like', $like));
            })
            ->when($from !== '', fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to !== '', fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($perPage);

        $items = $paginator->getCollection()->map(fn (AdminOperationLog $log) => [
            'id' => $log->id,
            'admin_id' => $log->admin_id,
            'username' => $log->username,
            'action' => $log->action,
            'action_label' => AdminOperationLog::label($log->action),
            'summary' => $log->summary,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'payload' => $log->payload,
            'method' => $log->method,
            'path' => $log->path,
            'status' => (int) $log->status,
            'success' => (bool) $log->success,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
        ]);

        $actions = [];
        foreach (AdminOperationLog::ACTIONS as $action => $label) {
            $actions[] = ['value' => $action, 'label' => $label];
        }

        return Api::ok([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'actions' => $actions,
                'admins' => Admin::withTrashed()->orderBy('id')->get(['id', 'username', 'name'])
                    ->map(fn (Admin $admin) => [
                        'id' => $admin->id,
                        'label' => $admin->displayName(),
                    ])->all(),
            ],
        ]);
    }
}
