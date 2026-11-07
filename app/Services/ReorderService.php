<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReorderService
{
    /** targetId 表示插入到该项之前；null 表示移到末尾 */
    public function tracks(int $albumId, int $trackId, ?int $targetId): void
    {
        DB::transaction(function () use ($albumId, $trackId, $targetId) {
            $ids = Track::query()
                ->where('album_id', $albumId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $sorted = $this->move($ids, $trackId, $targetId, '音频不属于该专辑');

            foreach ($sorted as $index => $id) {
                Track::query()->where('id', $id)->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function albums(int $albumId, ?int $targetId): void
    {
        DB::transaction(function () use ($albumId, $targetId) {
            $ids = Album::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $sorted = $this->move($ids, $albumId, $targetId, '专辑不存在');

            foreach ($sorted as $index => $id) {
                Album::query()->where('id', $id)->update(['sort_order' => $index + 1]);
            }
        });
    }

    private function move(array $ids, int $movingId, ?int $targetId, string $error): array
    {
        $from = array_search($movingId, $ids, true);
        if ($from === false) {
            throw new InvalidArgumentException($error);
        }
        array_splice($ids, $from, 1);

        if ($targetId === null) {
            $ids[] = $movingId;

            return $ids;
        }

        $position = array_search($targetId, $ids, true);
        if ($position === false) {
            throw new InvalidArgumentException('目标项不存在');
        }
        array_splice($ids, $position, 0, [$movingId]);

        return $ids;
    }
}
