<?php

/*
|--------------------------------------------------------------------------
| Document Number Prefixes
|--------------------------------------------------------------------------
|
| Every document type gets its own gap-free series per store and financial
| year (April to March): {STORE CODE}/{PREFIX}/{YY-YY}/{00001}, for example
| MAIN/INV/26-27/00001. Accounting vouchers without a document of their own
| (journal, contra, ...) are numbered from here too.
|
*/

return [

    'invoice' => 'INV',
    'quotation' => 'QT',
    'delivery_challan' => 'DC',
    'stock_transfer' => 'ST',
    'purchase' => 'PUR',
    'purchase_return' => 'PR',
    'sales_return' => 'SR',
    'receipt' => 'RCT',
    'payment' => 'PAY',
    'expense' => 'EXP',
    'journal' => 'JV',
    'contra' => 'CV',
    'credit_note' => 'CN',
    'debit_note' => 'DN',
    'sales' => 'SV',

];
