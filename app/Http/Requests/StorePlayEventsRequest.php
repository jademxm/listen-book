<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlayEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.client_event_id' => ['required', 'string', 'max:64'],
            'events.*.session_id' => ['required', 'string', 'max:64'],
            'events.*.track_id' => ['required', 'integer'],
            'events.*.album_id' => ['nullable', 'integer'],
            'events.*.type' => ['required', 'string', 'in:start,tick,pause,end,ended,catchup'],
            'events.*.position_sec' => ['required', 'integer', 'min:0'],
            'events.*.occurred_at' => ['required', 'date'],
        ];
    }
}
