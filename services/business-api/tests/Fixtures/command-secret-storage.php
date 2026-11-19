<?php

use App\Models\ChargerCommand;
use App\Models\Charging\SessionLink;
use App\Models\Station;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || DB::connection()->getDriverName() !== 'sqlite' || ! ctype_digit($argv[1] ?? '')) {
    throw new RuntimeException('Owned local SQLite command fixture required.');
}
$command = ChargerCommand::findOrFail((int) $argv[1]);
$station = Station::findOrFail($command->station_id);
if ($command->action !== 'start' || $station->source !== 'simulator' || ! str_starts_with($station->name, 'Business protocol ev-test-business-')) {
    throw new RuntimeException('Synthetic business command required.');
}
$raw = json_decode($command->getRawOriginal('payload'), true, flags: JSON_THROW_ON_ERROR);
$plain = $command->payload;
$token = $plain['id_token'] ?? null;
if (! is_array($raw['id_token'] ?? null) || ($raw['id_token']['__creabiz_command_secret'] ?? null) !== 1 || ! is_string($raw['id_token']['ciphertext'] ?? null) || ! is_string($token) || ! DB::table('charger_commands')->where('id', $command->id)->where('payload->session_id', $plain['session_id'] ?? null)->exists() || ($command->toArray()['payload']['id_token'] ?? null) !== '[REDACTED]') {
    throw new RuntimeException('Command secret storage contract failed.');
}
$digest = SessionLink::where('start_command_id', $command->id)->value('remote_token_digest');
$bound = is_string($digest) && hash_equals($digest, hash('sha256', $token));
if ($digest !== null && ! $bound) {
    throw new RuntimeException('Canonical command token binding changed.');
}
echo json_encode(['fixture_only' => true, 'token_encrypted' => true, 'metadata_queryable' => true, 'public_token_redacted' => true, 'remote_binding_verified' => $bound], JSON_THROW_ON_ERROR);
