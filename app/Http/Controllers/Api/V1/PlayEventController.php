<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlayEventsRequest;
use App\Services\PlayEventService;
use App\Support\Api;
use Illuminate\Http\JsonResponse;

class PlayEventController extends Controller
{
    public function store(StorePlayEventsRequest $request, PlayEventService $service): JsonResponse
    {
        return Api::ok($service->ingest($request->user(), $request->validated()['events']));
    }
}
