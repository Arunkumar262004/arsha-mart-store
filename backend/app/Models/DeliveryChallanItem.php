<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['delivery_challan_id', 'product_id', 'quantity', 'unit_price', 'tax_percent'])]
class DeliveryChallanItem extends Model
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
        ];
    }

    /**
     * @return BelongsTo<DeliveryChallan, $this>
     */
    public function challan(): BelongsTo
    {
        return $this->belongsTo(DeliveryChallan::class, 'delivery_challan_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
