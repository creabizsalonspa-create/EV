<?php

namespace Tests\Feature;

use App\Services\Ocpi\CredentialExchange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiTokenWindowTest extends TestCase
{
    use CreatesSandboxRoaming, RefreshDatabase;

    public function test_token_sender_advertisement_requires_the_same_configured_sandbox_role_and_contract_as_requests(): void
    {
        [, , , $provider] = $this->fixture();
        $url = '/api/roaming-protocol/2.2.1';
        $connection = config('ocpi.connections.roaming');
        $local = config('ocpi.local_credentials');
        $link = DB::table('ocpi_credential_links')->where('provider_id', $provider->id);
        $advertises = function () use ($url): bool {
            $endpoints = $this->withHeaders($this->peer())->getJson($url)->assertOk()->json('data.endpoints');

            return collect($endpoints)->contains(fn ($endpoint) => $endpoint['identifier'] === 'tokens' && $endpoint['role'] === 'SENDER');
        };
        $this->assertTrue($advertises());
        // Versions negotiation must be possible before a current outbound
        // endpoint set is known; configured advertisement is not a grant.
        $provider->update(['version' => null, 'endpoints' => null]);
        $this->assertTrue($advertises());
        $this->withHeaders($this->peer())->getJson($url.'/tokens')->assertStatus(409);
        foreach ([
            ['environment' => 'production'],
            ['incoming_modules' => ['sessions']],
            ['test_commands' => false],
        ] as $change) {
            config(['ocpi.connections.roaming' => array_replace($connection, $change)]);
            $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
            $this->assertFalse($advertises());
        }
        $cpo = $local;
        $cpo['roles'][0]['role'] = 'CPO';
        config(['ocpi.local_credentials' => $cpo,
            'ocpi.connections.roaming' => array_replace($connection, ['party_role' => 'EMSP', 'incoming_modules' => []])]);
        $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->assertFalse($advertises());
        config(['ocpi.local_credentials' => $local, 'ocpi.connections.roaming' => $connection, 'ocpi.test_commands_enabled' => false]);
        $link->update(['configuration_hash' => app(CredentialExchange::class)->configurationHash($provider)]);
        $this->assertFalse($advertises());
        Http::assertNothingSent();
        $this->assertDatabaseCount('ocpi_roaming_orders', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_token_reads_and_authorization_require_the_same_attempted_command(): void
    {
        $fixture = $this->fixture();
        [$id] = $this->start($fixture[1], $fixture[4]);
        $token = $this->writes[0]['body'];
        $url = '/api/roaming-protocol/2.2.1/tokens';
        $single = $url.'/'.$token['country_code'].'/'.$token['party_id'].'/'.$token['uid'];
        $command = DB::table('ocpi_roaming_commands')->where('order_id', $id)->first();
        $this->withHeaders($this->peer())->getJson($single)->assertOk()->assertJsonPath('data.valid', true);
        foreach ([
            ['attempted_at' => null],
            ['state' => 'prepared'],
            ['state' => 'partner_rejected'],
            ['actor_id' => $fixture[2]->id],
        ] as $changed) {
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->update($changed);
            $this->withHeaders($this->peer())->getJson($single)->assertOk()->assertJsonPath('data.valid', false);
            $this->withHeaders($this->peer())->getJson($url)->assertOk()->assertJsonPath('data.0.valid', false);
            $this->withHeaders($this->peer())->postJson($url.'/'.$token['uid'].'/authorize', ['location_id' => 'L1', 'evse_uids' => ['E1']])
                ->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
            $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->authorized_at);
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->update([
                'attempted_at' => $command->attempted_at, 'state' => $command->state, 'actor_id' => $command->actor_id,
            ]);
        }
        $this->withHeaders($this->peer())->getJson($single)->assertOk()->assertJsonPath('data.valid', true);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_token_grant_rejects_corrupted_or_rebound_command_payload_and_negative_receipts(): void
    {
        $fixture = $this->fixture();
        [$id] = $this->start($fixture[1], $fixture[4]);
        $token = $this->writes[0]['body'];
        $url = '/api/roaming-protocol/2.2.1/tokens';
        $single = $url.'/'.$token['country_code'].'/'.$token['party_id'].'/'.$token['uid'];
        $command = DB::table('ocpi_roaming_commands')->where('order_id', $id)->first();
        $payload = json_decode(Crypt::decryptString($command->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $payload['location_id'] = 'FOREIGN';
        $changes = [
            ['intent_hash' => str_repeat('a', 64)],
            ['payload_ciphertext' => 'invalid-ciphertext'],
            ['payload_ciphertext' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                'intent_hash' => hash('sha256', json_encode([$id, 'START_SESSION', $payload], JSON_THROW_ON_ERROR))],
            ['transport_result' => 'REJECTED', 'state' => 'outcome_unknown'],
            ['callback_result' => 'FAILED'],
        ];
        foreach ($changes as $change) {
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->update($change);
            $this->withHeaders($this->peer())->getJson($single)->assertOk()->assertJsonPath('data.valid', false);
            $this->withHeaders($this->peer())->getJson($url)->assertOk()->assertJsonPath('data.0.valid', false);
            $this->withHeaders($this->peer())->postJson($url.'/'.$token['uid'].'/authorize', ['location_id' => 'L1', 'evse_uids' => ['E1']])
                ->assertOk()->assertJsonPath('data.allowed', 'NOT_ALLOWED');
            $this->assertNull(DB::table('ocpi_roaming_orders')->find($id)->authorized_at);
            DB::table('ocpi_roaming_commands')->where('id', $command->id)->update([
                'intent_hash' => $command->intent_hash, 'payload_ciphertext' => $command->payload_ciphertext,
                'transport_result' => $command->transport_result, 'state' => $command->state,
                'callback_result' => $command->callback_result,
            ]);
        }
        $this->withHeaders($this->peer())->getJson($single)->assertOk()->assertJsonPath('data.valid', true);
        $this->withHeaders($this->peer())->postJson($url.'/'.$token['uid'].'/authorize', ['location_id' => 'L1', 'evse_uids' => ['E1']])
            ->assertOk()->assertJsonPath('data.allowed', 'ALLOWED');
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_callback_rejects_a_command_changed_after_initial_lookup(): void
    {
        $fixture = $this->fixture();
        [$id] = $this->start($fixture[1], $fixture[4]);
        $claim = DB::table('ocpi_roaming_commands')->where('order_id', $id)->first();
        $armed = true;
        DB::listen(function ($query) use ($claim, &$armed) {
            if ($armed && str_contains(strtolower($query->sql), 'join "ocpi_roaming_orders"')) {
                $armed = false;
                DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['attempted_at' => null]);
            }
        });
        $path = '/api/roaming-protocol/2.2.1/commands/'.$claim->callback_key;
        $this->withHeaders($this->peer())->postJson($path, ['result' => 'ACCEPTED'])->assertConflict();
        $this->assertFalse($armed);
        $this->assertNull(DB::table('ocpi_roaming_commands')->find($claim->id)->callback_hash);
        DB::table('ocpi_roaming_commands')->where('id', $claim->id)->update(['attempted_at' => $claim->attempted_at]);
        $this->withHeaders($this->peer())->postJson($path, ['result' => 'ACCEPTED'])->assertOk();
        $this->assertSame('ACCEPTED', DB::table('ocpi_roaming_commands')->find($claim->id)->callback_result);
        $this->assertCount(2, $this->writes);
        $this->assertDatabaseCount('charging_sessions', 0);
    }

    public function test_token_sender_windows_normalize_offsets_and_keep_exclusive_end_boundary(): void
    {
        $fixture = $this->fixture();
        $this->start($fixture[1], $fixture[4]);
        $order = DB::table('ocpi_roaming_orders')->firstOrFail();

        // Preserve fractional source update precision, independently of the
        // test runner's current wall clock and original order creation time.
        $at = CarbonImmutable::instance(now()->utc())->subMinute()->startOfSecond()->addMicroseconds(123456);
        DB::table('ocpi_roaming_orders')->where('id', $order->id)->update([
            'updated_at' => $at->format('Y-m-d H:i:s.u'),
        ]);

        $offset = fn (CarbonImmutable $at): string => $at->setTimezone('+02:00')->format('Y-m-d\TH:i:s.uP');
        $read = fn (array $filters) => $this->withHeaders($this->peer())->getJson(
            '/api/roaming-protocol/2.2.1/tokens?'.http_build_query($filters)
        );
        $originalWrites = count($this->writes);

        $read(['date_from' => $offset($at), 'date_to' => $offset($at->addMicrosecond())])
            ->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.valid', true);
        $read(['date_from' => $at->format('Y-m-d\TH:i:s.u\Z')])
            ->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonCount(1, 'data');
        $read(['date_to' => $offset($at->addMicrosecond())])
            ->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonCount(1, 'data');
        $read(['date_to' => $offset($at)])
            ->assertOk()->assertHeader('X-Total-Count', '0')->assertJsonCount(0, 'data');
        $read(['date_from' => $offset($at->addMicrosecond())])
            ->assertOk()->assertHeader('X-Total-Count', '0')->assertJsonCount(0, 'data');
        $read(['date_from' => $offset($at), 'date_to' => $offset($at->subMicrosecond())])
            ->assertStatus(422);
        $this->assertCount($originalWrites, $this->writes, 'Token pagination must never dispatch a roaming command.');
    }

    public function test_token_sender_preserves_exact_second_bounds_for_unfractioned_database_rows(): void
    {
        $fixture = $this->fixture();
        $this->start($fixture[1], $fixture[4]);
        $second = now()->utc()->subMinute()->startOfSecond();
        DB::table('ocpi_roaming_orders')->update(['updated_at' => $second->format('Y-m-d H:i:s')]);
        $at = $second->format('Y-m-d\\TH:i:s\\Z');
        $later = $second->copy()->addMicrosecond()->format('Y-m-d\\TH:i:s.u\\Z');
        $originalWrites = count($this->writes);
        $read = fn (array $filters) => $this->withHeaders($this->peer())->getJson(
            '/api/roaming-protocol/2.2.1/tokens?'.http_build_query($filters)
        );

        // A whole-second row equals date_from and must be excluded by date_to.
        $read(['date_from' => $at])->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonCount(1, 'data');
        $read(['date_to' => $at])->assertOk()->assertHeader('X-Total-Count', '0')->assertJsonCount(0, 'data');
        $read(['date_from' => $at, 'date_to' => $later])
            ->assertOk()->assertHeader('X-Total-Count', '1')->assertJsonCount(1, 'data');
        $read(['date_from' => $later])->assertOk()->assertHeader('X-Total-Count', '0')->assertJsonCount(0, 'data');
        $this->assertCount($originalWrites, $this->writes, 'Date-window reads must not dispatch any OCPI commands.');
    }

    public function test_token_sender_rejects_relative_rolled_or_partial_date_bounds_without_broadening_a_source_read(): void
    {
        $fixture = $this->fixture();
        $this->start($fixture[1], $fixture[4]);
        $writes = count($this->writes);
        $read = fn (array $filters) => $this->withHeaders($this->peer())->getJson(
            '/api/roaming-protocol/2.2.1/tokens?'.http_build_query($filters)
        );

        foreach ([
            'tomorrow',
            '2026-02-30T08:00:00Z',
            '2026-11-16 08:00:00',
            '2026-11-16T08:00',
            '2026-11-16T08:00:00+14:30',
            '2026-11-16T08:00:00+12:90',
            '2026-11-16T08:00:00.1234567Z',
            '2026-11-16T08:00:00Ztrailing',
            '',
        ] as $invalid) {
            $read(['date_from' => $invalid])->assertStatus(422);
            $read(['date_to' => $invalid])->assertStatus(422);
        }
        $read(['date_from' => ['unexpected' => 'array']])->assertStatus(422);
        $read(['date_to' => ['unexpected' => 'array']])->assertStatus(422);
        $this->assertCount($writes, $this->writes,
            'Malformed token windows must not dispatch new partner commands');
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_ocpi_protocol_replies_echo_safe_message_ids_across_token_credentials_and_records(): void
    {
        $this->fixture();
        $ids = ['X-Request-ID' => 'agent9-synthetic-request', 'X-Correlation-ID' => 'agent9-synthetic-correlation'];
        $headers = array_merge($this->peer(), $ids);

        foreach ([
            '/api/roaming-protocol/versions',
            '/api/roaming-protocol/2.2.1/tokens?limit=1',
        ] as $url) {
            $this->withHeaders($headers)->getJson($url)
                ->assertOk()
                ->assertHeader('X-Request-ID', $ids['X-Request-ID'])
                ->assertHeader('X-Correlation-ID', $ids['X-Correlation-ID']);
        }

        // The same tracing contract must survive a peer-scoped read error.
        $this->withHeaders($headers)->getJson('/api/roaming-protocol/2.2.1/sessions/NL/ABC/missing')
            ->assertNotFound()
            ->assertHeader('X-Request-ID', $ids['X-Request-ID'])
            ->assertHeader('X-Correlation-ID', $ids['X-Correlation-ID']);

        $this->withHeaders(array_merge($headers, ['Authorization' => 'Token '.base64_encode('wrong-peer')]))
            ->getJson('/api/roaming-protocol/2.2.1/tokens?limit=1')
            ->assertUnauthorized()
            ->assertHeader('X-Request-ID', $ids['X-Request-ID'])
            ->assertHeader('X-Correlation-ID', $ids['X-Correlation-ID']);
        // Never reflect unbounded or whitespace-containing inbound identifiers.
        $unsafe = $this->withHeaders(array_merge($headers, [
            'X-Request-ID' => str_repeat('x', 129),
            'X-Correlation-ID' => 'invalid space',
        ]))->getJson('/api/roaming-protocol/versions')->assertOk();
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/i', (string) $unsafe->headers->get('X-Request-ID'));
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/i', (string) $unsafe->headers->get('X-Correlation-ID'));
        $this->assertCount(0, $this->writes, 'Read-only OCPI tracing must never send a command.');
    }
}
