<?php

use App\Http\Controllers\ChargerDiagnosticsController;
use App\Models\Charging\SessionLink;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\FaultMonitor;
use App\Services\Charging\FaultReminderEscalator;
use App\Services\Charging\SessionBridge;
use App\Services\Charging\SourceConsumer;
use App\Services\CsmsDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

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
            $links = SessionLink::whereNull('ended_at')->whereNull('closed_without_dispatch_at')->when($id, fn ($q) => $q->whereKey((int) $id));
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

Artisan::command('csms:monitor-faults {--limit=25} {--after=0} {--binding=} {--before=}', function () {
    if (app()->environment('production') || ! config('csms.session_bridge_enabled')) {
        $this->error('Only the configured local simulator source monitor is enabled.');

        return 1;
    }
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $bindingId = $this->option('binding');
    $before = $this->option('before');
    if ($limit === false || $after === false || ($bindingId !== null && (! ctype_digit($bindingId) || (int) $bindingId < 1)) || ($before !== null && ($bindingId === null || ! ctype_digit($before) || (int) $before < 1 || (int) $before > 2147483647))) {
        $this->error('Use limit1–100, after>=0, and a positive binding for bounded before-cursor catch-up.');

        return 1;
    }
    $bindings = DB::table('csms_bindings')->whereNotNull('vendor_charger_id')->where('id', '>', $after)->when($bindingId, fn ($q) => $q->where('id', (int) $bindingId))->orderBy('id')->limit($limit)->get();
    $failed = 0;
    foreach ($bindings as $binding) {
        try {
            $charger = VendorCharger::findOrFail($binding->vendor_charger_id);
            $company = VendorCompany::findOrFail($charger->company_id);
            $owner = User::findOrFail($company->owner_id);
            $request = Request::create('/internal/fault-monitor', 'POST', $before === null ? [] : ['before' => (int) $before]);
            $request->setUserResolver(fn () => $owner);
            $result = app(ChargerDiagnosticsController::class)->inspect($request, $company, $charger, app(FaultMonitor::class))->getData(true);
            $this->line(json_encode(['binding_id' => $binding->id, 'alerts_created' => $result['alerts_created'], 'next_before' => $result['next_before'], 'commands_sent' => false, 'evidence' => 'SIMULATED_SOURCE']));
        } catch (Throwable $e) {
            $failed++;
            $guard = collect($e->getTrace())->first(fn ($frame) => str_ends_with($frame['file'] ?? '', '/ChargerDiagnosticsController.php'));
            $this->error('Binding '.$binding->id.' requires source/access reconciliation ('.$e::class.' guard '.($guard['line'] ?? 0).').');
        }
    }

    return $failed ? 1 : 0;
})->purpose('Bounded durable simulator fault observation; no hardware or capacity commands');


Artisan::command('notifications:remind-faults {--limit=50} {--after=0} {--older-than=15}', function () {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $after = filter_var($this->option('after'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]);
    $age = filter_var($this->option('older-than'), FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 5, 'max_range' => 1440]]);
    if ($limit === false || $after === false || $age === false) {
        $this->error('Use limit 1..100, after >=0 and older-than 5..1440 minutes.');

        return 1;
    }
    try {
        $result = app(FaultReminderEscalator::class)->scan($limit, $after, $age);
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
    } catch (Throwable $error) {
        // Do not expose private alert records, secrets or raw source messages.
        $this->error('Simulator fault reminder scan failed ('.$error::class.').');

        return 1;
    }

    return 0;
})->purpose('Bounded, owner-only, simulator in-app fault reminders; never hardware/external delivery');

Schedule::command('csms:expire-diagnostic-files --limit=100')->everyTenMinutes()->withoutOverlapping();
Schedule::command('notifications:remind-faults --limit=50 --older-than=15')
    ->everyTenMinutes()->withoutOverlapping();
