<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Wallpaper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallpaper */
final class WallpaperResource extends JsonResource
{
    /** @return array<string, ?string> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'market' => $this->market,
            'sourceDate' => $this->source_date->format('Y-m-d'),
            'title' => $this->title,
            'copyright' => $this->copyright_text,
            'copyrightLink' => $this->copyright_link,
            'imageUrl' => $this->image_url,
            'downloadUrl' => $this->download_url,
        ];
    }
}
