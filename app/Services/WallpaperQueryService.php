<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallpaper;
use App\Support\CalendarDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class WallpaperQueryService
{
    /** @return Collection<int, Wallpaper> */
    public function forDate(string $date): Collection
    {
        $this->validateDate($date);

        return $this->query()->where('source_date', $date)->get();
    }

    /** @return Collection<int, Wallpaper> */
    public function between(string $from, string $to): Collection
    {
        $this->validateDate($from);
        $this->validateDate($to);

        if ($from > $to) {
            throw new InvalidArgumentException('The from date must be on or before the to date.');
        }

        return $this->query()->whereBetween('source_date', [$from, $to])->get();
    }

    /** @return Builder<Wallpaper> */
    private function query(): Builder
    {
        return Wallpaper::query()->select([
            'id', 'source', 'market', 'source_date', 'title', 'copyright_text',
            'copyright_link', 'image_url', 'download_url',
        ])->orderByDesc('source_date')->orderByDesc('id');
    }

    private function validateDate(string $date): void
    {
        if (! CalendarDate::isCanonical($date)) {
            throw new InvalidArgumentException('A real date in YYYY-MM-DD format is required.');
        }
    }
}
