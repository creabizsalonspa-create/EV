<?php

use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite') {
    throw new RuntimeException('Native fleet fixture requires owned local SQLite.');
}
$run = bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24)).'aA9!';
$result = DB::transaction(function () use ($run, $password) {
    $actors = [];
    foreach (['owner', 'driver', 'foreign'] as $role) {
        $actors[$role] = User::create([
            'name' => 'Synthetic native fleet '.$role,
            'email' => 'native-fleet-'.$role.'-'.$run.'@example.test',
            'password' => Hash::make($password),
        ]);
    }
    $fleets = $invitations = [];
    for ($index = 0; $index < 27; $index++) {
        $fleet = Fleet::create(['owner_id' => $actors['owner']->id, 'name' => 'Synthetic operational fleet '.$index, 'country' => 'AE']);
        $fleets[] = $fleet->id;
        $member = FleetMember::create(['fleet_id' => $fleet->id, 'user_id' => $actors['driver']->id, 'role' => 'driver', 'status' => $index === 0 ? 'active' : 'invited']);
        if ($index > 0) {
            $invitations[] = $member->id;
        }
    }

    return ['owner' => $actors['owner']->email, 'driver' => $actors['driver']->email, 'foreign' => $actors['foreign']->email,
        'fleets' => array_reverse($fleets), 'active_fleet' => $fleets[0], 'invitations' => array_reverse($invitations)];
});
// Private synthetic credentials are captured into a temporary mode0600 file.
echo json_encode($result + ['password' => $password, 'fixture_only' => true, 'financial_mutations' => false], JSON_THROW_ON_ERROR);
