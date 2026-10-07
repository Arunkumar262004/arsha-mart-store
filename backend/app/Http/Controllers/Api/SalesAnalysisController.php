<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SalesAnalysisService;
use App\Support\ReportPeriod;
use App\Support\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales by category (with margin), payment mode, store, weekday and hour,
 * plus the top products, for a report period.
 */
class SalesAnalysisController extends Controller
{
    public function __invoke(Request $request, SalesAnalysisService $analysis, StoreContext $context): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['nullable', Rule::in(ReportPeriod::PRESETS)],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // A bare from/to (no preset) is a custom range.
        $preset = $filters['period'] ?? (isset($filters['from'], $filters['to']) ? 'custom' : 'this_month');
        $period = ReportPeriod::resolve($preset, $filters['from'] ?? null, $filters['to'] ?? null);

        return response()->json([
            'data' => $analysis->analyse($context->scopeId(), $period),
            'period' => $period->toArray(),
            'all_stores' => $context->isAll(),
        ]);
    }
}
