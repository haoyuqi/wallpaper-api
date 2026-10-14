<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\WallpaperQueryRequest;
use App\Http\Resources\WallpaperResource;
use App\Models\Wallpaper;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class WallpaperController
{
    public function __invoke(WallpaperQueryRequest $request): AnonymousResourceCollection
    {
        $date = $request->query('date');
        $from = $request->query('from');
        $to = $request->query('to');
        $query = Wallpaper::query()->select([
            'id', 'source', 'market', 'source_date', 'title', 'copyright_text',
            'copyright_link', 'image_url', 'download_url',
        ]);

        if ($date !== null) {
            $query->where('source_date', $date);
        } else {
            $query->whereBetween('source_date', [$from, $to]);
        }

        $wallpapers = $query->orderByDesc('source_date')->orderByDesc('id')->get();

        return WallpaperResource::collection($wallpapers)->additional([
            'meta' => [
                'query' => ['date' => $date, 'from' => $from, 'to' => $to],
                'count' => $wallpapers->count(),
            ],
        ]);
    }
}
