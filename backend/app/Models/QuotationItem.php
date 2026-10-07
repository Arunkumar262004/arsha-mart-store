<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'quotation_id', 'product_id', 'quantity', 'unit_price', 'tax_percent', 'line_subtotal', 'line_tax',
    'cgst_percent', 'cgst_amount', 'sgst_percent', 'sgst_amount', 'igst_percent', 'igst_amount', 'line_total',
])]
class QuotationItem extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_percent' => 'decimal:2',
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
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
