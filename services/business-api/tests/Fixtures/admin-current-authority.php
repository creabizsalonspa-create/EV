<?php

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/canonical-topology.php'; // Existing strict local SQLite/run guard.
$fixture = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$owner->update(['name' => 'Synthetic owner <img src=x> '.$run]);
$company->update(['legal_name' => 'Literal100%_! '.$run, 'country' => 'AE', 'status' => 'approved']);
VendorCompany::create(['owner_id' => $owner->id, 'legal_name' => 'Canadian sibling '.$run, 'country' => 'CA', 'trade_license_number' => 'SYNTHETIC-CA-'.$run, 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'rejected']);
$admin = User::create(['name' => 'Synthetic operator '.$run, 'email' => 'operator-admin-'.$run.'@example.test', 'password' => $password]);
$admin->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();
foreach (['admin.second_factor.verify_denied', 'admin.second_factor.recovery_denied'] as $action) {
    DB::table('audit_events')->insert(['actor_id' => $admin->id, 'action' => $action, 'subject' => 'PRIVATE-DENIAL-SUBJECT-'.$run, 'created_at' => now()]);
}
echo json_encode($fixture + ['admin_id' => $admin->id, 'admin_email' => $admin->email,
    'customer_email' => $customer->email, 'owner_id' => $owner->id, 'owner_name' => $owner->name,
    'company_query' => 'Literal100%_! '.$run, 'private_marker' => 'PRIVATE-DENIAL-SUBJECT-'.$run,
    'fixture_only' => true], JSON_THROW_ON_ERROR);
