<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

ob_start();
require __DIR__.'/payout-browser.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
User::create(['name' => 'Synthetic unrelated reporting customer', 'email' => 'payout-foreign-'.$argv[1].'@example.test', 'password' => Hash::make($argv[2])]);
echo json_encode($fixture + ['fixture_only' => true]);
