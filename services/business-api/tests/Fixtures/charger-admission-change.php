<?php

use App\Http\Controllers\ChargerAdmissionController;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || count($argv) !== 4 || ! ctype_digit($argv[1]) || ! in_array($argv[2], ['rotate', 'revoke'], true)) {
    throw new RuntimeException('Local fixture operation only.');
}
$charger = VendorCharger::findOrFail($argv[1]);
$company = VendorCompany::findOrFail($charger->company_id);
if (! str_starts_with($company->legal_name, 'Canonical browser ')) {
    throw new RuntimeException('Synthetic fixture required.');
}
$r = Request::create('/', 'POST', ['expected_version' => (int) $argv[3], 'days' => 1]);
$r->setUserResolver(fn () => User::findOrFail($company->owner_id));
$c = app(ChargerAdmissionController::class);
$response = $argv[2] === 'rotate' ? $c->credential($r, $company, $charger) : $c->revoke($r, $company, $charger);
echo $response->getContent();
