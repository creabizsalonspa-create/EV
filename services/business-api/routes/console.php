<?php

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
