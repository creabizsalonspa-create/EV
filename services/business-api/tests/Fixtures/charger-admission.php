<?php

use App\Http\Controllers\ChargerAdmissionController;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Http\Request;

$protocol = $argv[3] ?? '';
$pin = $argv[4] ?? null;
if ($pin !== null && ! preg_match('/^[a-f0-9]{64}$/', $pin)) {
    throw new RuntimeException('Fixture certificate fingerprint required.');
}
if (! in_array($protocol, ['1.6', '2.0.1'], true)) {
    throw new RuntimeException('Fixture protocol required.');
}
$argv = array_slice($argv, 0, 3);
ob_start();
require __DIR__.'/canonical-topology.php';
$f = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$company = VendorCompany::findOrFail($f['company_id']);
$charger = VendorCharger::findOrFail($f['charger_id']);
$charger->update(['ocpp_protocol' => $protocol]);
$owner = User::findOrFail($company->owner_id);
$r = Request::create('/', 'POST', ['identity' => 'ev-test-admission-'.$run, 'certificate_sha256' => $pin]);
$r->setUserResolver(fn () => $owner);
$c = app(ChargerAdmissionController::class);
$c->configure($r, $company, $charger);
$r = Request::create('/', 'POST', ['expected_version' => 0, 'days' => 1]);
$r->setUserResolver(fn () => $owner);
$result = $c->credential($r, $company, $charger)->getData(true);
echo json_encode([...$f, 'identity' => $result['profile']['identity'], 'secret' => $result['secret'], 'certificate_sha256' => $result['profile']['certificate_sha256']], JSON_THROW_ON_ERROR);
