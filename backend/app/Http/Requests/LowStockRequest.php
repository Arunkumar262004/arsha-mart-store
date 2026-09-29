<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LowStockRequest extends FormRequest
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
            'threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * The per-request threshold, falling back to the configured default.
     */
    public function threshold(): int
    {
        return $this->filled('threshold')
            ? $this->integer('threshold')
            : (int) config('inventory.low_stock_threshold');
    }
}
