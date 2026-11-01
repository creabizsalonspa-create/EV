<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostedCheckoutPageTest extends TestCase
{
    public function test_public_mobile_return_is_inert_private_and_cannot_confirm_money_from_a_query(): void
    {
        Http::preventStrayRequests();
        $response = $this->get('/payments/hosted-return?success=paid&payment_status=captured&hosted_reference=untrusted-value');
        $response->assertOk()->assertSee('Returning from a payment page does not confirm payment.', false)->assertSee('creabizev://payments/hosted-return', false)->assertDontSee('untrusted-value', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->getJson('/api/commercial/hosted-checkouts/by-reference/00000000-0000-4000-8000-000000000001')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_local_simulation_page_is_not_public_payment_confirmation(): void
    {
        config(['payments.provider' => 'stripe_test']);
        $this->get('/payments/hosted-simulation/1')->assertStatus(302);
        $this->get('/payments/hosted-return')->assertOk()->assertDontSee('data-simulation-checkout=', false);
    }
}
