<?php

/*
|--------------------------------------------------------------------------
| Assignable Permissions
|--------------------------------------------------------------------------
|
| The permissions an admin can tick when creating a role. Each key is also
| registered as a Gate ability, so routes use `can:<key>` middleware and
| the frontend hides menus the user cannot use.
|
| Settings (users, roles, password resets) is deliberately NOT listed: it
| belongs to the built-in Admin role only.
|
*/

return [

    'dashboard.view' => ['group' => 'Dashboard', 'label' => 'View dashboard'],

    'billing.create' => ['group' => 'Billing', 'label' => 'Create bills (new orders)'],

    'orders.view' => ['group' => 'Orders', 'label' => 'View order history'],

    'products.view' => ['group' => 'Inventory', 'label' => 'View products & low stock'],
    'products.manage' => ['group' => 'Inventory', 'label' => 'Add & edit products'],
    'stock.adjust' => ['group' => 'Inventory', 'label' => 'Restock & correct stock'],

    'reports.view' => ['group' => 'Reports', 'label' => 'View order, customer, stock & employee reports'],

];
