<?php

namespace App\Http\Requests;

use App\Models\SalesReturn;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesReturnRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // route is behind can:returns.manage
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_mode' => ['required', Rule::in(SalesReturn::REFUND_MODES)],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
