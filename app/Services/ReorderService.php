<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ReorderService
{
    /**
     * 同一专辑内重排音频。
     * targetId 表示插入到该项之前；null 表示移到末尾。
     *
     * 返回 true 表示目标行已失效（页面数据过期、被删除或被移出该专辑），
     * 此时退化为「移到该专辑末尾」而不是抛错，调用方提示用户刷新页面即可。
     *
     * 所属专辑以数据库为准：页面可能打开较久，行上的 album_id 已经过期，
     * 采信前端传来的 album_id 会直接报「目标项不存在」。
     *
     * @param  int  $albumId  前端传来的容器专辑 id，仅作兜底，实际以 track 当前所属专辑为准
     */
    public function tracks(int $albumId, int $trackId, ?int $targetId): bool
    {
        return DB::transaction(function () use ($albumId, $trackId, $targetId) {
            $moving = Track::query()->find($trackId);
            if (! $moving) {
                throw new InvalidArgumentException('音频不存在或已被移除');
            }

            $groupId = (int) $moving->album_id;
            if ($groupId <= 0) {
                throw new InvalidArgumentException('音频不属于任何专辑');
            }

            $ids = Track::query()
                ->where('album_id', $groupId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            [$sorted, $degraded] = $this->move($ids, $trackId, $targetId, '音频不属于该专辑');

            foreach ($sorted as $index => $id) {
                Track::query()->where('id', $id)->update(['sort_order' => $index + 1]);
            }

            if ($degraded) {
                Log::warning('reorder: 目标音频已不在该专辑，退化为移到末尾', [
                    'album_id' => $groupId,
                    'track_id' => $trackId,
                    'target_id' => $targetId,
                ]);
            }

            return $degraded;
        });
    }

    /** 全集重排专辑；语义同上，返回 true 表示已退化为移到末尾 */
    public function albums(int $albumId, ?int $targetId): bool
    {
        return DB::transaction(function () use ($albumId, $targetId) {
            if (! Album::query()->whereKey($albumId)->exists()) {
                throw new InvalidArgumentException('专辑不存在或已被移除');
            }

            $ids = Album::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            [$sorted, $degraded] = $this->move($ids, $albumId, $targetId, '专辑不存在');

            foreach ($sorted as $index => $id) {
                Album::query()->where('id', $id)->update(['sort_order' => $index + 1]);
            }

            if ($degraded) {
                Log::warning('reorder: 目标专辑已失效，退化为移到末尾', [
                    'album_id' => $albumId,
                    'target_id' => $targetId,
                ]);
            }

            return $degraded;
        });
    }

    /**
     * @return array{0: array<int, int>, 1: bool} [排好序的 id 列表, 是否退化到末尾]
     */
    private function move(array $ids, int $movingId, ?int $targetId, string $error): array
    {
        $from = array_search($movingId, $ids, true);
        if ($from === false) {
            throw new InvalidArgumentException($error);
        }
        array_splice($ids, $from, 1);

        // 移到末尾；target 与自身相同时也落到末尾，避免移除后再查不到目标
        if ($targetId === null || $targetId === $movingId) {
            $ids[] = $movingId;

            return [$ids, false];
        }

        $position = array_search($targetId, $ids, true);
        if ($position === false) {
            // 目标已不在该分组（页面数据过期最常见）：退化为移到末尾，不抛 422
            $ids[] = $movingId;

            return [$ids, true];
        }
        array_splice($ids, $position, 0, [$movingId]);

        return [$ids, false];
    }
}
