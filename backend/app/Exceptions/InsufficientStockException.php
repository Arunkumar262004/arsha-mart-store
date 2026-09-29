<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InsufficientStockException extends Exception
{
    /**
     * @param  array<int, array{index: int, product_id: int, name: string, requested: int, available: int}>  $shortages
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct('Insufficient stock for one or more products.');
    }

    /**
     * Rendered in the same shape as a validation error so clients can show
     * the message next to the offending order line.
     */
    public function render(): JsonResponse
    {
        $errors = [];

        foreach ($this->shortages as $shortage) {
            $errors["items.{$shortage['index']}.quantity"] = [
                "Only {$shortage['available']} unit(s) of {$shortage['name']} in stock; {$shortage['requested']} requested.",
            ];
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $errors,
            'shortages' => $this->shortages,
        ], 422);
    }
}
