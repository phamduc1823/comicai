<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Welcome Bonus
    |--------------------------------------------------------------------------
    |
    | The initial coin amount credited to a newly registered user account.
    |
    */
    'welcome_bonus' => (int) env('VIP_WELCOME_BONUS', 100),

    /*
    |--------------------------------------------------------------------------
    | Demo Faucet Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for test coins faucet to facilitate user testing.
    |
    */
    'faucet' => [
        'enabled' => (bool) env('VIP_FAUCET_ENABLED', true),
        'amount' => (int) env('VIP_FAUCET_AMOUNT', 100),
        'daily_limit' => (int) env('VIP_FAUCET_DAILY_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | VIP Pricing Limits
    |--------------------------------------------------------------------------
    |
    | Defines allowable range of coins required to unlock a VIP chapter.
    |
    */
    'pricing' => [
        'min_price' => 5,
        'max_price' => 50,
        'default_price' => 10,
    ],
];
