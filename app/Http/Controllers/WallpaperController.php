<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WallpaperQueryRequest;
use App\Http\Resources\WallpaperResource;
use App\Services\WallpaperQueryService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class WallpaperController
{
    public function __invoke(WallpaperQueryRequest $request, WallpaperQueryService $service): AnonymousResourceCollection
    {
        $date = $request->query('date');
        $from = $request->query('from');
        $to = $request->query('to');
        $wallpapers = $date !== null ? $service->forDate($date) : $service->between($from, $to);

        return WallpaperResource::collection($wallpapers)->additional([
            'meta' => [
                'query' => ['date' => $date, 'from' => $from, 'to' => $to],
                'count' => $wallpapers->count(),
            ],
        ]);
    }
}
