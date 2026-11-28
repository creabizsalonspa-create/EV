<?php

namespace Tests\Feature;

use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagnosticRetentionSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_only_expiry_preserves_a_later_file_window_and_retained_receipt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-28T12:00:00.123456Z'));
        $owner = User::factory()->create(['role' => 'vendor']);
        $station = Station::create([
            'vendor_id' => $owner->id, 'name' => 'Retention fixture',
            'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai',
            'latitude' => 25, 'longitude' => 55, 'source' => 'simulator',
            'connector_type' => 'CCS2', 'power_kw' => 50,
        ]);
        $binding = DB::table('csms_bindings')->insertGetId([
            'station_id' => $station->id, 'identifier' => 'ev-test-retention',
            'protocol' => '1.6', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $digest = hash('sha256', 'synthetic diagnostic file');
        $id = DB::table('charger_diagnostic_logs')->insertGetId([
            'binding_id' => $binding, 'actor_id' => $owner->id,
            'request_key' => (string) Str::uuid(), 'intent_hash' => str_repeat('a', 64),
            'source_generation' => str_repeat('b', 64), 'protocol' => '1.6',
            'reason_ciphertext' => 'retained-reason',
            'payload_ciphertext' => 'expired-request',
            'upload_token_digest' => null,
            'dispatch_state' => 'transport_submitted',
            'expires_at' => '2026-11-28 12:00:00.123456',
            'file_expires_at' => '2026-11-28 12:00:00.123457',
            'file_ciphertext' => 'file-still-within-retention',
            'file_sha256' => $digest,
            'file_size' => 25,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        $this->artisan('csms:expire-diagnostic-files --limit=1')
            ->expectsOutputToContain('Expired diagnostic records processed: 1.')
            ->assertSuccessful();
        $receipt = DB::table('charger_diagnostic_logs')->find($id);
        $this->assertNull($receipt->payload_ciphertext);
        $this->assertSame('file-still-within-retention', $receipt->file_ciphertext);
        $this->assertNull($receipt->content_removed_at);

        $this->travelTo(CarbonImmutable::parse('2026-11-28T12:00:01Z'));
        $this->artisan('csms:expire-diagnostic-files --limit=1')
            ->expectsOutputToContain('Expired diagnostic records processed: 1.')
            ->assertSuccessful();
        $receipt = DB::table('charger_diagnostic_logs')->find($id);
        $this->assertNull($receipt->file_ciphertext);
        $this->assertNotNull($receipt->content_removed_at);
        $this->assertSame($digest, $receipt->file_sha256);
        $this->assertSame('retained-reason', $receipt->reason_ciphertext);

        $this->travelTo(CarbonImmutable::parse('2026-11-28T12:01:00Z'));
        $this->artisan('csms:expire-diagnostic-files --limit=1')
            ->expectsOutputToContain('Expired diagnostic records processed: 0.')
            ->assertSuccessful();
        $this->assertEquals($receipt, DB::table('charger_diagnostic_logs')->find($id));
    }
}
