<?php

use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\SourceConsumer;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('platform:role {email} {role}', function () {
    $role = $this->argument('role');
    if (! in_array($role, ['customer', 'vendor', 'admin'])) {
        $this->error('Invalid role');

        return 1;
    }
    $u = User::where('email', $this->argument('email'))->firstOrFail();
    $u->role = $role;
    $u->save();
    $this->info('Role updated for existing user.');
})->purpose('Operator-only role provisioning; no public role elevation endpoint');

Artisan::command('csms:dispatch {id}', function () {
    $c = app(CsmsDispatcher::class)->dispatch((int) $this->argument('id'));
    $this->info('Command state: '.$c->status);

    return $c->status === 'submitted' ? 0 : 1;
})->purpose('Dispatch one simulator-only outbox command; never auto-retry uncertain outcomes');

Artisan::command('platform:charging-fixture {customer} {station}', function () {
    abort_unless(app()->environment(['local', 'testing']), 403, 'Test fixture creation is development-only.');
    $u = User::findOrFail((int) $this->argument('customer'));
    $s = Station::findOrFail((int) $this->argument('station'));
    abort_unless($u->role === 'customer' && $s->source === 'simulator', 409);
    $slot = now()->utc()->startOfHour()->addMinutes(now()->utc()->minute >= 30 ? 30 : 0);
    $r = Reservation::firstOrCreate(['user_id' => $u->id, 'idempotency_key' => 'protocol-fixture:'.$s->id.':'.$slot->timestamp], ['station_id' => $s->id, 'starts_at' => $slot, 'status' => 'confirmed']);
    $this->line(json_encode(['reservation_id' => $r->id, 'fixture_only' => true]));
})->purpose('Create a current-slot synthetic reservation for local protocol QA, never production');

Artisan::command('csms:consume {--once} {--link=} {--interval=2}', function () {
    app(SessionBridge::class)->enabled();
    if (strlen((string) config('csms.graphql_secret')) < 32 || strlen((string) config('csms.event_secret')) < 32) {
        $this->error('Configure authenticated source and inbox keys before starting.');

        return 1;
    }
    $interval = filter_var($this->option('interval'), FILTER_VALIDATE_INT);
    $id = $this->option('link');
    if ($interval === false || $interval < 1 || $interval > 60 || ($id !== null && (! ctype_digit($id) || (int) $id < 1))) {
        $this->error('Use interval 1..60 and a positive link ID.');

        return 1;
    }
    $lock = fopen(storage_path('framework/csms-consumer.lock'), 'c');
    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
        $this->error('A local source consumer already owns the lock.');

        return 1;
    }
    try {
        do {
            $failed = 0;
            $links = SessionLink::whereNull('ended_at')->when($id, fn ($q) => $q->whereKey((int) $id));
            foreach ($links->lazyById(100) as $link) {
                try {
                    $count = app(SourceConsumer::class)->consume($link);
                    if ($count) {
                        $this->line(json_encode(['link_id' => $link->id, 'events' => $count, 'evidence' => 'SIMULATED_CSMS']));
                    }
                } catch (Throwable $e) {
                    $failed++;
                    // Never log credentials, source payloads or transport bodies.
                    $this->error('Source link '.$link->id.' requires retry/reconciliation ('.$e::class.').');
                }
            }
            if ($this->option('once')) {
                return $failed ? 1 : 0;
            }
            sleep($interval);
        } while (true);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
})->purpose('Continuously forward authenticated simulator observations; never dispatch commands');
