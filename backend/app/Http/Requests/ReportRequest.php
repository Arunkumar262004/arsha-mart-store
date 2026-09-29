<?php

namespace App\Http\Requests;

use App\Models\StockMovement;
use App\Support\ReportPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters shared by every report: a date period plus optional employee,
 * search text and stock movement type.
 */
class ReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(ReportPeriod::PRESETS)],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            // "adjustments" = everything except sales.
            'type' => ['nullable', Rule::in([
                'adjustments', StockMovement::TYPE_SALE, StockMovement::TYPE_RESTOCK,
                StockMovement::TYPE_CORRECTION, StockMovement::TYPE_INITIAL,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function period(): ReportPeriod
    {
        return ReportPeriod::resolve(
            $this->validated('period') ?? 'this_month',
            $this->validated('from'),
            $this->validated('to'),
        );
    }

    /**
     * @return array{employee_id: ?int, search: ?string, type: ?string}
     */
    public function filters(): array
    {
        return [
            'employee_id' => $this->filled('employee_id') ? $this->integer('employee_id') : null,
            'search' => $this->search(),
            'type' => $this->validated('type'),
        ];
    }

    public function search(): ?string
    {
        return filled($this->validated('search')) ? trim($this->validated('search')) : null;
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 20);
    }
}
