<?php

namespace Tests\Unit;

use App\Support\ReportPeriod;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function presets(): array
    {
        return [
            'this month' => ['2026-09-29', 'this_month', '2026-09-01', '2026-09-29'],
            'last month' => ['2026-09-29', 'last_month', '2026-08-01', '2026-08-31'],
            'last month from 31st' => ['2026-03-31', 'last_month', '2026-02-01', '2026-02-28'],
            'last 3 months' => ['2026-09-29', 'last_3_months', '2026-06-29', '2026-09-29'],
            'last 6 months' => ['2026-09-29', 'last_6_months', '2026-03-29', '2026-09-29'],
            'this year' => ['2026-09-29', 'this_year', '2026-01-01', '2026-09-29'],
            'current FY after April' => ['2026-09-29', 'current_fin_year', '2026-04-01', '2026-09-29'],
            'current FY before April' => ['2026-02-10', 'current_fin_year', '2025-04-01', '2026-02-10'],
            'last FY after April' => ['2026-09-29', 'last_fin_year', '2025-04-01', '2026-03-31'],
            'last FY before April' => ['2026-02-10', 'last_fin_year', '2024-04-01', '2025-03-31'],
        ];
    }

    #[DataProvider('presets')]
    public function testPresetsResolveToTheRightDates(string $today, string $preset, string $from, string $to): void
    {
        $period = ReportPeriod::resolve($preset, now: Carbon::parse($today.' 15:30:00'));

        $this->assertSame($from, $period->from->toDateString());
        $this->assertSame('00:00:00', $period->from->toTimeString());
        $this->assertSame($to, $period->to->toDateString());
        $this->assertSame('23:59:59', $period->to->toTimeString());
    }

    public function testCustomRangeCoversWholeDays(): void
    {
        $period = ReportPeriod::resolve('custom', '2026-09-01', '2026-09-10');

        $this->assertSame('2026-09-01 00:00:00', $period->from->toDateTimeString());
        $this->assertSame('2026-09-10 23:59:59', $period->to->toDateTimeString());
    }
}
