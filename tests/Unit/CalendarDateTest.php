<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CalendarDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarDateTest extends TestCase
{
    #[DataProvider('dates')]
    public function test_only_real_canonical_calendar_dates_are_accepted(mixed $date, bool $valid): void
    {
        $this->assertSame($valid, CalendarDate::isCanonical($date));
    }

    public static function dates(): array
    {
        return [
            ['0001-01-01', true], ['9999-12-31', true], ['2028-02-29', true],
            ['2000-02-29', true], ['1900-02-29', false], ['2026-02-29', false],
            ['0000-01-01', false], ['10000-01-01', false], ['2026-13-01', false],
            ['2026-08-00', false], ['2026-8-1', false], [' 2026-08-01', false],
            ["2026-08-01\n", false], [null, false], [[], false], [20260801, false],
        ];
    }
}
