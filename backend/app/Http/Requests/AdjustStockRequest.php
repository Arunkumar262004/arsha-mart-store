<?php

namespace App\Http\Requests;

use App\Models\StockMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:stock.adjust
    }

    /**
     * A restock always adds; a correction may add or remove (e.g. damaged
     * goods, stock-take differences) and must say why.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([StockMovement::TYPE_RESTOCK, StockMovement::TYPE_CORRECTION])],
            'quantity' => [
                'required', 'integer', 'not_in:0', 'between:-1000000,1000000',
                Rule::when($this->input('type') === StockMovement::TYPE_RESTOCK, ['min:1']),
            ],
            'note' => [
                Rule::requiredIf($this->input('type') === StockMovement::TYPE_CORRECTION),
                'nullable', 'string', 'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.not_in' => 'Quantity cannot be zero.',
            'quantity.min' => 'A restock must add at least 1 unit.',
            'note.required' => 'Give a reason for the correction.',
        ];
    }
}
