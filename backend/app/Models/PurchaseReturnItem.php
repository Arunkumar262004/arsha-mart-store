<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_return_id', 'purchase_item_id', 'product_id', 'quantity', 'unit_cost', 'tax_percent',
    'line_subtotal', 'line_tax', 'cgst_amount', 'sgst_amount', 'igst_amount', 'line_total',
])]
class PurchaseReturnItem extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'tax_percent' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
