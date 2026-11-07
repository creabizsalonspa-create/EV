<?php

namespace Tests\Feature;

use App\Services\Commercial\Payments\StripeSandboxProvider;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Safety guard: no real payment-provider credential may trigger an outgoing
 * request in the development/test payment adapter.
 */
class PaymentProviderSecretSafetyTest extends TestCase
{
    public function test_live_or_unsupported_stripe_credentials_fail_before_any_network_request(): void
    {
        Http::preventStrayRequests();

        foreach ([
            null,
            '',
            'sk_live_agent9_not_a_real_secret',
            'rk_test_agent9_wrong_key_scope',
            'sk_test_agent9_not_valid_with_extra-dash',
        ] as $unacceptable) {
            config(['payments.stripe_test_secret' => $unacceptable]);
            try {
                (new StripeSandboxProvider)->readHostedPayment('cs_test_agent9_synthetic');
                $this->fail('Test adapter accepted a disallowed provider key');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
        }

        Http::assertNothingSent();
    }

    public function test_invalid_or_external_hosted_session_references_never_contact_provider(): void
    {
        Http::preventStrayRequests();
        config(['payments.stripe_test_secret' => 'sk_test_agent9synthetic']);

        foreach ([
            '',
            'cs_live_not_a_test_mode_checkout',
            'https://checkout.stripe.com/not-a-session',
            'cs_test_agent9_invalid-dash',
        ] as $invalid) {
            try {
                (new StripeSandboxProvider)->readHostedPayment($invalid);
                $this->fail('Accepted an untrusted provider checkout reference');
            } catch (HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }

        Http::assertNothingSent();
    }
}
