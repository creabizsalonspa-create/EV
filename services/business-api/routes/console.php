<?php

use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
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
