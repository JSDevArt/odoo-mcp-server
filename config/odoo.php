<?php

return [
    'url' => env('ODOO_URL'),
    'db' => env('ODOO_DB'),
    'login' => env('ODOO_LOGIN'),
    'api_key' => env('ODOO_API_KEY'),

    'timeout' => env('ODOO_TIMEOUT', 30),
];
