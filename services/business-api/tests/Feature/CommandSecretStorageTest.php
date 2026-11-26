<?php

namespace Tests\Feature;

use App\Models\ChargerCommand;
use App\Models\Station;
use App\Models\User;
use App\Services\Charging\CommandSecretFields;
use App\Services\CsmsDispatcher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommandSecretStorageTest extends TestCase
{
    use RefreshDatabase;

    private function command(string $key = 'owned-command'): ChargerCommand
    {
        $vendor = User::factory()->create(['role' => 'vendor']);
        $station = Station::create(['vendor_id' => $vendor->id, 'name' => 'Secret storage fixture', 'country' => 'AE', 'currency' => 'AED', 'timezone' => 'Asia/Dubai', 'latitude' => 25, 'longitude' => 55, 'source' => 'simulator', 'connector_type' => 'CCS2', 'power_kw' => 50]);

        return ChargerCommand::create(['actor_id' => $vendor->id, 'station_id' => $station->id, 'action' => 'start', 'payload' => ['session_id' => 42, 'id_token' => 'SYNTHETIC-OPAQUE-TOKEN', 'nested' => ['Authorization' => 'SYNTHETIC-BEARER']], 'idempotency_key' => $key, 'payload_hash' => str_repeat('a', 64)]);
    }

    public function test_protocol_upload_urls_are_sealed_without_changing_dispatch_values(): void
    {
        $command = $this->command();
        $uri = 'https://diagnostic:SYNTHETIC-UPLOAD-CAPABILITY@example.test/files';
        $payload = ['location' => $uri, 'log' => ['remoteLocation' => $uri], 'mirrors' => [$uri], 'documentation' => 'https://example.test/public'];
        $command->update(['payload' => $payload, 'confirmation' => ['urls' => [$uri]]]);
        $raw = $command->fresh()->getRawOriginal('payload');
        $this->assertStringNotContainsString('SYNTHETIC-UPLOAD', $raw);
        $this->assertSame($payload, $command->fresh()->payload);
        $this->assertSame(['urls' => [$uri]], $command->fresh()->confirmation);
        $public = $command->fresh()->toArray();
        $this->assertSame('[REDACTED]', $public['payload']['location']);
        $this->assertSame('[REDACTED]', $public['payload']['log']['remoteLocation']);
        $this->assertSame('[REDACTED]', $public['payload']['mirrors'][0]);
        $this->assertSame('https://example.test/public', $public['payload']['documentation']);
        $this->assertStringNotContainsString('SYNTHETIC-UPLOAD', $command->fresh()->toJson());
        $command->update(['status' => 'outcome_unknown']);
        $this->assertSame($raw, $command->fresh()->getRawOriginal('payload'));
        $inspection = CommandSecretFields::inspect($raw, $command->getAttributes(), 'payload');
        $this->assertSame(3, $inspection['encrypted_fields']);
    }

    public function test_legacy_upload_uri_is_redacted_and_can_be_sealed_without_transport_changes(): void
    {
        $command = $this->command();
        $uri = 'https://diagnostic:SYNTHETIC-LEGACY@example.test/files';
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => json_encode(['location' => $uri])]);
        $command = $command->fresh();
        $this->assertSame('[REDACTED]', $command->toArray()['payload']['location']);
        $this->assertSame($uri, $command->payload['location']);
        $command->update(['status' => 'outcome_unknown']);
        $this->assertStringNotContainsString('SYNTHETIC-LEGACY', $command->fresh()->getRawOriginal('payload'));
        $this->assertSame($uri, $command->fresh()->payload['location']);
    }

    public function test_upload_uri_envelope_cannot_be_moved_to_another_protocol_field(): void
    {
        $command = $this->command();
        $command->update(['payload' => ['location' => 'https://diagnostic:SYNTHETIC-CAP@example.test/files']]);
        $raw = json_decode($command->getRawOriginal('payload'), true, flags: JSON_THROW_ON_ERROR);
        $raw['remoteLocation'] = $raw['location'];
        unset($raw['location']);
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => json_encode($raw)]);
        $this->expectException(DecryptException::class);
        $command->fresh()->payload;
    }

    public function test_new_secrets_are_encrypted_but_dispatch_values_and_sql_metadata_survive(): void
    {
        $command = $this->command();
        $raw = DB::table('charger_commands')->where('id', $command->id)->value('payload');
        $this->assertStringNotContainsString('SYNTHETIC-OPAQUE-TOKEN', $raw);
        $this->assertStringNotContainsString('SYNTHETIC-BEARER', $raw);
        $this->assertSame('SYNTHETIC-OPAQUE-TOKEN', $command->fresh()->payload['id_token']);
        $this->assertSame('SYNTHETIC-BEARER', $command->fresh()->payload['nested']['Authorization']);
        $this->assertSame(1, DB::table('charger_commands')->where('payload->session_id', 42)->count());
        $this->assertSame('[REDACTED]', $command->fresh()->toArray()['payload']['id_token']);
        $this->assertStringNotContainsString('SYNTHETIC-', $command->fresh()->toJson());
    }

    public function test_structured_protocol_201_reply_secrets_and_nonsecret_unknown_replies_keep_their_shape(): void
    {
        $command = $this->command();
        $reply = [['success' => true, 'idToken' => ['idToken' => 'SYNTHETIC-201', 'type' => 'Central'], 'password' => 'SYNTHETIC-PASSWORD']];
        $command->update(['confirmation' => $reply]);
        $raw = DB::table('charger_commands')->where('id', $command->id)->value('confirmation');
        $this->assertStringNotContainsString('SYNTHETIC-', $raw);
        $this->assertSame($reply, $command->fresh()->confirmation);
        $command->update(['confirmation' => false]);
        $this->assertFalse($command->fresh()->confirmation);
        $command->update(['confirmation' => null]);
        $this->assertNull($command->fresh()->confirmation);
    }

    public function test_status_updates_do_not_reencrypt_or_change_transport_identity(): void
    {
        $command = $this->command();
        $raw = $command->getRawOriginal('payload');
        $command->update(['status' => 'outcome_unknown']);
        $this->assertSame($raw, $command->fresh()->getRawOriginal('payload'));
        $this->assertSame(str_repeat('a', 64), $command->fresh()->payload_hash);
    }

    public function test_ciphertext_cannot_be_transplanted_to_another_command(): void
    {
        $first = $this->command();
        $second = ChargerCommand::create(['actor_id' => $first->actor_id, 'station_id' => $first->station_id, 'action' => 'start', 'payload' => $first->payload, 'idempotency_key' => 'second-command', 'payload_hash' => $first->payload_hash]);
        DB::table('charger_commands')->where('id', $second->id)->update(['payload' => $first->getRawOriginal('payload')]);
        $this->expectException(DecryptException::class);
        $second->fresh()->payload;
    }

    public function test_no_secret_json_bytes_are_not_normalized_by_a_status_update(): void
    {
        $command = $this->command();
        $json = '{ "session_id" : 42, "reason" : "retained source evidence" }';
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => $json]);
        $command = $command->fresh();
        $command->update(['status' => 'outcome_unknown']);
        $this->assertSame($json, $command->fresh()->getRawOriginal('payload'));
    }

    public function test_ciphertext_is_bound_to_its_field_path_and_payload_identity(): void
    {
        $command = $this->command();
        $raw = json_decode($command->getRawOriginal('payload'), true, flags: JSON_THROW_ON_ERROR);
        $raw['nested']['Authorization'] = $raw['id_token'];
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => json_encode($raw, JSON_THROW_ON_ERROR)]);
        $this->expectException(DecryptException::class);
        $command->fresh()->payload;
    }

    public function test_failed_sealing_does_not_silently_skip_or_send_a_corrupted_command(): void
    {
        $command = $this->command();
        $raw = json_decode($command->getRawOriginal('payload'), true, flags: JSON_THROW_ON_ERROR);
        $raw['id_token']['ciphertext'] = 'SYNTHETIC-BAD-CIPHER';
        $raw = json_encode($raw, JSON_THROW_ON_ERROR);
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => $raw]);
        $this->artisan('csms:seal-command-secrets', ['--apply' => true])->assertFailed();
        $this->assertSame($raw, DB::table('charger_commands')->where('id', $command->id)->value('payload'));
        $this->assertSame('pending', DB::table('charger_commands')->where('id', $command->id)->value('status'));
    }

    public function test_corrupt_envelope_never_becomes_a_plaintext_dispatch_token(): void
    {
        $command = $this->command();
        $raw = json_decode($command->getRawOriginal('payload'), true, flags: JSON_THROW_ON_ERROR);
        $raw['id_token'] = ['__creabiz_command_secret' => 1, 'ciphertext' => 'SYNTHETIC-CORRUPTION'];
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => json_encode($raw, JSON_THROW_ON_ERROR)]);
        $this->expectException(DecryptException::class);
        $command->fresh()->payload;
    }

    public function test_unreadable_credentials_fail_before_any_real_dispatch_request_or_outcome_change(): void
    {
        config(['csms.simulator_enabled' => true]);
        Http::preventStrayRequests();
        $command = $this->command();
        $raw = json_decode($command->getRawOriginal('payload'), true, flags: JSON_THROW_ON_ERROR);
        $raw['id_token']['ciphertext'] = 'SYNTHETIC-BAD-CIPHER';
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => json_encode($raw, JSON_THROW_ON_ERROR)]);
        try {
            app(CsmsDispatcher::class)->dispatch($command->id);
            $this->fail('Unreadable credentials must not reach dispatch.');
        } catch (DecryptException) {
            Http::assertNothingSent();
            $this->assertSame('pending', DB::table('charger_commands')->where('id', $command->id)->value('status'));
        }
    }

    public function test_previous_key_read_and_explicit_rekey_preserve_identity_and_remove_old_key_dependency(): void
    {
        $original = Crypt::getFacadeRoot();
        $command = $this->command();
        $old = $command->getRawOriginal('payload');
        $next = new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher'));
        $next->previousKeys([$original->getKey()]);
        try {
            Crypt::swap($next);
            $this->assertSame('SYNTHETIC-OPAQUE-TOKEN', $command->fresh()->payload['id_token']);
            $this->artisan('csms:seal-command-secrets', ['--rekey' => true])->assertSuccessful();
            $this->assertSame($old, $command->fresh()->getRawOriginal('payload'));
            $this->artisan('csms:seal-command-secrets', ['--apply' => true, '--rekey' => true])->assertSuccessful();
            $this->assertNotSame($old, $command->fresh()->getRawOriginal('payload'));
            $next->previousKeys([]);
            $this->assertSame('SYNTHETIC-OPAQUE-TOKEN', $command->fresh()->payload['id_token']);
            $this->assertSame(str_repeat('a', 64), $command->fresh()->payload_hash);
        } finally {
            Crypt::swap($original);
        }
    }

    public function test_public_metadata_needs_no_credential_key_but_dispatch_access_still_fails_closed(): void
    {
        $original = Crypt::getFacadeRoot();
        $command = $this->command();
        try {
            Crypt::swap(new Encrypter(Encrypter::generateKey(config('app.cipher')), config('app.cipher')));
            $fresh = $command->fresh();
            $hidden = $fresh->getHidden();
            $public = $fresh->toArray();
            $this->assertSame('[REDACTED]', $public['payload']['id_token']);
            $this->assertSame($hidden, $fresh->getHidden());
            $this->assertSame(['id', 'payload'], array_keys($fresh->setVisible(['id', 'payload'])->toArray()));
            $this->assertArrayNotHasKey('payload', $fresh->makeHidden('payload')->toArray());
            try {
                $fresh->payload;
                $this->fail('Private operational access must still authenticate ciphertext.');
            } catch (DecryptException) {
                $this->assertTrue(true);
            }
        } finally {
            Crypt::swap($original);
        }
    }

    public function test_legacy_sealing_is_dry_by_default_resumable_and_does_not_dispatch_or_rewrite_history(): void
    {
        $command = $this->command();
        $legacy = json_encode(['session_id' => 42, 'id_token' => 'SYNTHETIC-LEGACY'], JSON_THROW_ON_ERROR);
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => $legacy, 'status' => 'outcome_unknown']);
        $before = $command->fresh();
        $this->assertSame('SYNTHETIC-LEGACY', $before->payload['id_token']);
        $this->artisan('csms:seal-command-secrets', ['--limit' => 1])->assertSuccessful();
        $this->assertSame($legacy, $command->fresh()->getRawOriginal('payload'));
        $this->artisan('csms:seal-command-secrets', ['--apply' => true, '--limit' => 1])->assertSuccessful();
        $after = $command->fresh();
        $this->assertStringNotContainsString('SYNTHETIC-LEGACY', $after->getRawOriginal('payload'));
        $this->assertSame('SYNTHETIC-LEGACY', $after->payload['id_token']);
        foreach (['status', 'payload_hash', 'idempotency_key', 'created_at', 'updated_at'] as $field) {
            $this->assertEquals($before->$field, $after->$field);
        }
        $sealed = $after->getRawOriginal('payload');
        $this->artisan('csms:seal-command-secrets', ['--apply' => true])->assertSuccessful();
        $this->assertSame($sealed, $command->fresh()->getRawOriginal('payload'));
        $this->assertDatabaseCount('charger_commands', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_read_only_deployment_check_refuses_unsealed_rows_and_invalid_modes_cannot_write(): void
    {
        $command = $this->command();
        $legacy = json_encode(['session_id' => 42, 'id_token' => 'SYNTHETIC-LEGACY'], JSON_THROW_ON_ERROR);
        DB::table('charger_commands')->where('id', $command->id)->update(['payload' => $legacy]);
        $this->artisan('csms:seal-command-secrets', ['--check' => true])->assertFailed();
        foreach ([['--limit' => 0], ['--limit' => 1001], ['--after' => '-1'], ['--after' => '9999999999999999999'], ['--check' => true]] as $invalid) {
            $this->artisan('csms:seal-command-secrets', ['--apply' => true, ...$invalid])->assertExitCode(2);
            $this->assertSame($legacy, DB::table('charger_commands')->where('id', $command->id)->value('payload'));
        }
        $this->artisan('csms:seal-command-secrets', ['--apply' => true])->assertSuccessful();
        $this->artisan('csms:seal-command-secrets', ['--check' => true])->assertSuccessful();
    }
}
