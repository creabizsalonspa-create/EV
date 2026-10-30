<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || config('payments.provider') !== 'mock' || ! preg_match('/^[a-f0-9]{8}$/D', $argv[2] ?? '')) {
    throw new RuntimeException('Campaign browser fixtures require local mock SQLite and isolated synthetic identities.');
}
if (($argv[1] ?? '') === 'delivery') {
    if (count($argv) !== 4 || ! ctype_digit($argv[3])) {
        throw new RuntimeException('Expected synthetic campaign identity.');
    }
    $c = DB::table('commercial_campaigns as c')->join('users as u', 'u.id', '=', 'c.vendor_id')->where('c.id', $argv[3])->where('u.email', 'campaign-vendor-'.$argv[2].'@example.test')->select('c.*')->first();
    if (! $c) {
        throw new RuntimeException('Synthetic campaign is missing.');
    }
    $secret = config('campaigns.delivery_secret');
    if (! is_string($secret) || strlen($secret) < 32) {
        throw new RuntimeException('Configure a private local campaign delivery key first.');
    }
    $raw = json_encode(['campaign_id' => (int) $c->id, 'event_id' => 'browser-delivery-'.$argv[2], 'kind' => 'delivery', 'country' => $c->country, 'region' => $c->region, 'placement' => $c->placement, 'occurred_at' => now()->utc()->toIso8601String()], JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    echo json_encode(['raw' => $raw, 'timestamp' => $timestamp, 'signature' => hash_hmac('sha256', $timestamp.'.'.$raw, $secret)]);
    exit;
}
if (($argv[1] ?? '') !== 'create' || count($argv) !== 4 || strlen($argv[3]) < 20) {
    throw new RuntimeException('Synthetic browser credentials are required.');
}
$f = (require __DIR__.'/commercial-campaigns.php')();
foreach (['admin', 'vendor', 'other', 'customer'] as $role) {
    $f[$role]->forceFill(['name' => 'Synthetic campaign '.$role, 'email' => 'campaign-'.$role.'-'.$argv[2].'@example.test', 'password' => Hash::make($argv[3])])->save();
}
echo json_encode(['fixture_only' => true, 'invoice' => $f['invoice']->id, 'station' => $f['station']->id]);
