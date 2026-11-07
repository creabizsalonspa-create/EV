<?php

use App\Models\User;
use App\Services\Commercial\Payments\GatewayService;
use Illuminate\Support\Facades\Hash;

ob_start();
require __DIR__.'/payout-browser.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$case = app(GatewayService::class)->createCase($f['invoice'], $f['customer']->id, 'dispute', 100, 'Synthetic private evidence review', 'case-evidence-'.$argv[1]);
User::create(['name' => 'Synthetic unrelated customer', 'email' => 'payout-foreign-'.$argv[1].'@example.test', 'password' => Hash::make($argv[2])]);
echo json_encode($fixture + ['case_id' => $case->id, 'fixture_only' => true]);
