<?php

use App\Http\Controllers\VendorOpsController;
use App\Models\Station;
use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorCompanyStaff;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local', 'testing') || config('database.default') !== 'sqlite' || count($argv) !== 4 || ! preg_match('/^[a-f0-9]{8}$/D', $argv[2])) {
    throw new RuntimeException('Isolated local SQLite fixture required.');
}
[$script,$mode,$run,$value] = $argv;
if ($mode === 'prepare') {
    foreach (['owner', 'staff', 'admin'] as $role) {
        $users[$role] = User::factory()->create(['name' => 'Synthetic onboarding '.$role, 'email' => 'onboarding-'.$role.'-'.$run.'@example.test', 'password' => $value, 'role' => $role === 'admin' ? 'admin' : 'customer']);
    }
    Station::create(['vendor_id' => $users['owner']->id, 'name' => 'Native owned catalog '.$run, 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25.2, 'longitude' => 55.3, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);
    echo json_encode(['run' => $run, 'fixture_only' => true]);
    exit;
}
$owner = User::where('email', 'onboarding-owner-'.$run.'@example.test')->firstOrFail();
if ($mode === 'approve') {
    $c = VendorCompany::whereKey((int) $value)->where('owner_id', $owner->id)->firstOrFail();
    $admin = User::where('email', 'onboarding-admin-'.$run.'@example.test')->where('role', 'admin')->firstOrFail();
    $r = Request::create('/synthetic-internal-review', 'POST', ['status' => 'approved', 'reason' => 'Synthetic internal review fixture; no legal KYC acceptance']);
    $r->setUserResolver(fn () => $admin);
    app(VendorOpsController::class)->review($r, $c);
    echo json_encode(['status' => $c->fresh()->status, 'fixture_only' => true]);
    exit;
}
if ($mode === 'revoke') {
    $m = VendorCompanyStaff::findOrFail((int) $value);
    $c = VendorCompany::whereKey($m->company_id)->where('owner_id', $owner->id)->firstOrFail();
    $r = Request::create('/synthetic-owner-revocation', 'DELETE');
    $r->setUserResolver(fn () => $owner);
    app(VendorOpsController::class)->revoke($r, $c, $m);
    echo json_encode(['status' => $m->fresh()->status, 'fixture_only' => true]);
    exit;
}
throw new RuntimeException('Unknown fixture action.');
