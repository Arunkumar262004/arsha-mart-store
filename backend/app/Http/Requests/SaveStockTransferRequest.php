<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route is behind can:transfers.manage
    }

    /**
     * The source is always the current store; StockTransferService rejects
     * the same store or an inactive one as destination.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'to_store_id' => ['required', 'integer', 'exists:stores,id'],
            'vehicle_number' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_store_id.required' => 'Choose the store to send the stock to.',
            'items.required' => 'Add at least one product.',
            'items.*.product_id.distinct' => 'Each product may appear only once; adjust the quantity instead.',
        ];
    }
}
