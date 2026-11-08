<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DescribesUploadFailures;
use Illuminate\Foundation\Http\FormRequest;

class StoreAlbumRequest extends FormRequest
{
    use DescribesUploadFailures;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return array_merge($this->uploadMessages(['cover' => '5MB']), [
            'title.required' => '请填写专辑标题。',
            'title.max' => '标题最多 120 字。',
            'description.max' => '简介最多 500 字。',
        ]);
    }
}
