<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DescribesUploadFailures;
use Illuminate\Foundation\Http\FormRequest;

class StoreTrackRequest extends FormRequest
{
    use DescribesUploadFailures;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'album_id' => ['required', 'integer', 'exists:albums,id'],
            'title' => ['required', 'string', 'max:150'],
            'audio' => ['required', 'file', 'max:102400'],
            'cover' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'duration_sec' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ];
    }

    public function messages(): array
    {
        return array_merge($this->uploadMessages(['audio' => '100MB', 'cover' => '5MB']), [
            'album_id.required' => '请选择所属专辑。',
            'album_id.exists' => '所选专辑不存在。',
            'title.required' => '请填写音频标题。',
            'title.max' => '标题最多 150 字。',
            'duration_sec.integer' => '时长必须是整数秒。',
            'duration_sec.max' => '时长不能超过 86400 秒。',
        ]);
    }
}
