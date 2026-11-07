<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // tracks: album_id = 专辑容器；albums: album_id = 被移动的专辑
            'album_id' => ['required', 'integer'],
            'track_id' => ['nullable', 'integer'],
            'target_id' => ['nullable', 'integer'],
        ];
    }

    public function targetId(): ?int
    {
        $value = $this->input('target_id');

        return $value === null || $value === '' ? null : (int) $value;
    }
}
