<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesSandboxRoaming;
use Tests\TestCase;

class OcpiTokenWindowTest extends TestCase
{
    use CreatesSandboxRoaming, RefreshDatabase;

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
        $this->assertCount(0, $this->writes, 'Read-only OCPI tracing must never send a command.');
    }
}
