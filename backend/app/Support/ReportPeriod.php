<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The date filter shared by every report. A preset (this month, last
 * financial year, ...) or a custom from/to range becomes a start and end
 * moment, both inclusive.
 *
 * Financial years run 1 April to 31 March (India).
 */
final class ReportPeriod
{
    public const PRESETS = [
        'this_month', 'last_month', 'last_3_months', 'last_6_months',
        'this_year', 'current_fin_year', 'last_fin_year', 'custom',
    ];

    private function __construct(
        public readonly string $preset,
        public readonly Carbon $from,
        public readonly Carbon $to,
    ) {}

    public static function resolve(string $preset, ?string $from = null, ?string $to = null, ?Carbon $now = null): self
    {
        $now ??= now();
        $today = $now->copy()->endOfDay();
        $finYearStart = $now->copy()->month(4)->startOfMonth()->startOfDay();
        if ($now->month < 4) {
            $finYearStart->subYear();
        }

        [$start, $end] = match ($preset) {
            'this_month' => [$now->copy()->startOfMonth(), $today],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            // Rolling windows that include today, e.g. 29 Jun – 29 Sep.
            'last_3_months' => [$now->copy()->subMonthsNoOverflow(3)->startOfDay(), $today],
            'last_6_months' => [$now->copy()->subMonthsNoOverflow(6)->startOfDay(), $today],
            'this_year' => [$now->copy()->startOfYear(), $today],
            'current_fin_year' => [$finYearStart->copy(), $today],
            'last_fin_year' => [$finYearStart->copy()->subYear(), $finYearStart->copy()->subSecond()],
            'custom' => [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()],
        };

        return new self($preset, $start, $end);
    }

    /**
     * @return array{preset: string, from: string, to: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }
}
