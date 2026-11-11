<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DescribesUploadFailures;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTrackRequest extends FormRequest
{
    use DescribesUploadFailures;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'album_id' => ['sometimes', 'required', 'integer', 'exists:albums,id'],
            'title' => ['sometimes', 'required', 'string', 'max:150'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'status' => ['sometimes', 'required', 'integer', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return array_merge($this->uploadMessages(['cover' => '5MB']), [
            'album_id.exists' => '所选专辑不存在。',
            'title.max' => '标题最多 150 字。',
            'status.in' => '上下架状态只能是 0 或 1。',
        ]);
    }
}
