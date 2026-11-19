<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminCommandSecretStorageController;
use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminCommandSecretStorageTest extends TestCase
{
    use RefreshDatabase;

    private function command(string $key): ChargerCommand
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Storage observation fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);

        return ChargerCommand::create(['actor_id' => $vendor->id, 'station_id' => $station->id, 'action' => 'start', 'idempotency_key' => $key, 'payload_hash' => str_repeat('b', 64), 'payload' => ['session_id' => 42, 'id_token' => 'PRIVATE-AUDIT-TOKEN']]);
    }

    public function test_admin_snapshot_is_bounded_private_and_cannot_seal_or_dispatch(): void
    {
        Http::preventStrayRequests();
        $this->command('encrypted');
        $legacy = $this->command('legacy');
        DB::table('charger_commands')->where('id', $legacy->id)->update(['payload' => json_encode(['id_token' => 'PRIVATE-LEGACY-TOKEN'])]);
        $bad = $this->command('unreadable');
        DB::table('charger_commands')->where('id', $bad->id)->update(['payload' => json_encode(['id_token' => ['__creabiz_command_secret' => 1, 'ciphertext' => 'PRIVATE-BAD-CIPHER']])]);
        $shape = $this->command('shape');
        DB::table('charger_commands')->where('id', $shape->id)->update(['payload' => 'false']);
        $empty = $this->command('no-secret');
        $empty->update(['payload' => ['session_id' => 42]]);
        $large = $this->command('oversized');
        DB::table('charger_commands')->where('id', $large->id)->update(['payload' => json_encode(['comment' => str_repeat('x', 65537), 'id_token' => 'PRIVATE-LARGE-TOKEN'])]);
        $before = DB::table('charger_commands')->orderBy('id')->get()->toJson();
        $admin = User::factory()->create(['role' => 'admin']);
        $reply = $this->actingAs($admin)->getJson('/api/admin/command-secret-storage')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('scope', 'bounded_command_secret_storage')
            ->assertJsonPath('rows_examined', 6)->assertJsonPath('encrypted_rows', 1)
            ->assertJsonPath('legacy_rows', 1)->assertJsonPath('unreadable_rows', 2)
            ->assertJsonPath('unknown_shape_rows', 1)->assertJsonPath('no_known_secret_rows', 1)
            ->assertJsonPath('has_more', false)->assertJsonPath('next_after_id', 0)
            ->assertJsonPath('whole_table_verified', false)->assertJsonPath('mutation_performed', false)
            ->assertJsonPath('production_acceptance', false);
        foreach (['PRIVATE-', 'ciphertext', 'idempotency_key', 'payload_hash', 'actor_id', 'station_id'] as $private) {
            $this->assertStringNotContainsString($private, $reply->getContent());
        }
        $this->assertSame($before, DB::table('charger_commands')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('finance_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_pages_are_explicit_monotonic_and_never_claim_table_wide_acceptance(): void
    {
        foreach (range(1, 5) as $i) {
            $this->command('page-'.$i);
        }
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $cursor = 0;
        foreach ([2, 2, 1] as $index => $size) {
            $reply = $this->getJson('/api/admin/command-secret-storage?limit=2&after='.$cursor)->assertOk()
                ->assertJsonPath('rows_examined', $size)->assertJsonPath('encrypted_rows', $size)
                ->assertJsonPath('has_more', $index < 2)->assertJsonPath('whole_table_verified', false);
            $next = $reply->json('next_after_id');
            $index < 2 ? $this->assertGreaterThan($cursor, $next) : $this->assertSame(0, $next);
            $cursor = $next;
        }
    }

    public function test_anonymous_and_non_admin_actors_cannot_inspect_command_storage(): void
    {
        $this->getJson('/api/admin/command-secret-storage')->assertUnauthorized();
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/admin/command-secret-storage')->assertForbidden();
        }
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->withAccessToken($admin->createToken('synthetic-native-boundary')->accessToken);
        $this->actingAs($admin)->getJson('/api/admin/command-secret-storage')->assertForbidden();
    }

    #[TestWith(['role'])]
    #[TestWith(['status'])]
    #[TestWith(['epoch'])]
    #[TestWith(['password'])]
    public function test_current_admin_authority_is_rechecked_before_inspection(string $change): void
    {
        $snapshot = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $snapshot->id)->update(match ($change) {
            'role' => ['role' => 'customer'], 'status' => ['account_status' => 'suspended'],
            'epoch' => ['auth_epoch' => $snapshot->auth_epoch + 1], 'password' => ['password' => 'changed-private-hash'],
        });
        $request = Request::create('/api/admin/command-secret-storage');
        $request->setUserResolver(fn () => $snapshot);
        try {
            app(AdminCommandSecretStorageController::class)->index($request);
            $this->fail('Stale authority must never inspect command storage.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 409]);
        }
    }

    public function test_bad_cursors_and_limits_are_rejected_before_scan(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['limit=0', 'limit=101', 'after=-1', 'after=1.5', 'after[]=1', 'after=9007199254740992'] as $query) {
            $this->getJson('/api/admin/command-secret-storage?'.$query)->assertUnprocessable();
        }
    }
}
