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
}
