<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'product_id', 'unit_price', 'tax_percent', 'quantity',
    'line_subtotal', 'line_tax', 'line_total',
    'cgst_percent', 'cgst_amount', 'sgst_percent', 'sgst_amount', 'igst_percent', 'igst_amount',
])]
class OrderItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'quantity' => 'integer',
            'line_subtotal' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'cgst_percent' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_percent' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_percent' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
