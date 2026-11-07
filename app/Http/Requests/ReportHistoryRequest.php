<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'track_id' => ['required', 'integer'],
            'progress_sec' => ['required', 'integer', 'min:0'],
            'duration_sec' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
