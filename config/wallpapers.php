<?php

declare(strict_types=1);

return [
    'requests_per_minute' => max(1, (int) env('WALLPAPER_REQUESTS_PER_MINUTE', 60)),
];
