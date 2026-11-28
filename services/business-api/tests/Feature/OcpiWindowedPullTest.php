<?php

namespace Tests\Feature;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Ocpi\OcpiException;
use App\Services\Ocpi\PartnerClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OcpiWindowedPullTest extends TestCase
{
    use RefreshDatabase;

    private function provider(string $url = 'https://partner.example/locations'): OcpiProvider
    {
        Http::preventStrayRequests();
        config(['ocpi.connections.windowed-read-fixture' => [
            'origin' => 'https://partner.example',
            'authorization_token' => base64_encode('NOT-A-LIVE-PARTNER-CREDENTIAL'),
            'environment' => 'sandbox',
        ]]);

        return OcpiProvider::create([
            'owner_id' => User::factory()->create(['role' => 'vendor'])->id,
            'name' => 'Read-only source window fixture',
            'connection_key' => 'windowed-read-fixture',
            'authorized' => true,
            'version' => '2.2.1',
            'endpoints' => ['locations' => $url],
        ]);
    }

    private function envelope(array $records): array
    {
        return ['status_code' => 1000, 'timestamp' => now()->toISOString(), 'data' => $records];
    }

    public function test_date_windows_are_utc_normalized_and_preserve_existing_read_only_limits(): void
    {
        $provider = $this->provider();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response($this->envelope([['id' => 'window-fixture', 'last_updated' => '2025-03-10T08:15:00Z']]));
        });

        $records = app(PartnerClient::class)->pull($provider, 'locations', [
            'date_from' => '2025-03-10T10:00:00+02:00',
            'date_to' => '2025-03-10T10:30:00+02:00',
        ]);
        $this->assertCount(1, $records);
        $this->assertSame('window-fixture', $records[0]['id']);
        $this->assertCount(1, $seen);
        parse_str((string) parse_url($seen[0], PHP_URL_QUERY), $query);
        $this->assertSame('2025-03-10T08:00:00.000000Z', $query['date_from']);
        $this->assertSame('2025-03-10T08:30:00.000000Z', $query['date_to']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_implicit_ocpi_utc_date_bounds_are_canonicalized_before_outbound_get(): void
    {
        $provider = $this->provider();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response($this->envelope([]));
        });
        $this->assertSame([], app(PartnerClient::class)->pull($provider, 'locations', [
            'date_from' => '2025-03-10T08:00:00',
            'date_to' => '2025-03-10T08:30:00',
        ]));
        $this->assertCount(1, $seen);
        parse_str((string) parse_url($seen[0], PHP_URL_QUERY), $query);
        $this->assertSame('2025-03-10T08:00:00.000000Z', $query['date_from']);
        $this->assertSame('2025-03-10T08:30:00.000000Z', $query['date_to']);
        Http::assertSentCount(1);
    }

    public function test_same_window_next_link_keeps_both_pages_without_retrying(): void
    {
        $provider = $this->provider();
        $count = 0;
        Http::fake(function ($request) use (&$count) {
            $count++;
            if ($count === 1) {
                return Http::response($this->envelope([['id' => 'one', 'last_updated' => '2025-03-10T08:00:00']]), 200, [
                    'Link' => '<'.$request->url().'&page=2>; rel="next"',
                ]);
            }

            return Http::response($this->envelope([['id' => 'two', 'last_updated' => '2025-03-10T08:29:59.999999Z']]));
        });

        $records = app(PartnerClient::class)->pull($provider, 'locations', [
            'date_from' => '2025-03-10T08:00:00Z',
            'date_to' => '2025-03-10T08:30:00Z',
        ]);
        $this->assertSame(['one', 'two'], array_column($records, 'id'));
        Http::assertSentCount(2);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_partner_next_link_cannot_drop_change_or_duplicate_explicit_source_bounds(): void
    {
        $provider = $this->provider();
        $mode = 'drop';
        Http::fake(function ($request) use (&$mode) {
            $base = $request->url();
            $link = match ($mode) {
                'drop' => 'https://partner.example/locations?page=2',
                'change' => str_replace('08%3A30', '09%3A30', $base).'&page=2',
                'duplicate' => $base.'&date_from=2025-03-10T08%3A00%3A00.000000Z&page=2',
            };

            return Http::response($this->envelope([['id' => 'only-first-page', 'last_updated' => '2025-03-10T08:15:00Z']]), 200, [
                'Link' => '<'.$link.'>; rel="next"',
            ]);
        });
        foreach (['drop', 'change', 'duplicate'] as $mode) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations', [
                    'date_from' => '2025-03-10T08:00:00Z',
                    'date_to' => '2025-03-10T08:30:00Z',
                ]);
                $this->fail('Source-window pagination escaped its requested bounds.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('window', $error->getMessage());
            }
        }
        Http::assertSentCount(3);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_negotiated_query_scope_and_continuation_controls_are_not_interchangeable(): void
    {
        $provider = $this->provider('https://partner.example/locations?country=NL&limit=25');
        $mode = 'valid';
        Http::fake(function ($request) use (&$mode) {
            if (str_contains($request->url(), 'offset=25')) {
                return Http::response($this->envelope([['id' => 'second']]));
            }
            $link = match ($mode) {
                'valid' => '?limit=25&offset=25&country=NL',
                'missing' => '?offset=25&limit=25',
                'changed' => '?country=AE&offset=25',
                'duplicate' => '?country=NL&country=AE&offset=25',
                'case_alias' => '?country=NL&COUNTRY=AE&offset=25',
                'new_scope' => '?country=NL&party_id=OTHER&offset=25',
                'bracket' => '?country=NL&country%5B%5D=AE&offset=25',
                'separator' => '?country=NL;country=AE&offset=25',
            };

            return Http::response($this->envelope([['id' => 'first']]), 200, ['Link' => '<'.$link.'>; rel="next"']);
        });
        $this->assertSame(['first', 'second'], array_column(app(PartnerClient::class)->pull($provider, 'locations'), 'id'));
        foreach (['missing', 'changed', 'duplicate', 'case_alias', 'new_scope', 'bracket', 'separator'] as $mode) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations');
                $this->fail('An ambiguous or altered negotiated query scope was accepted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('pagination', $error->getMessage());
            }
        }
        Http::assertSentCount(9);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_explicit_window_rejects_aliases_and_new_bounds_before_the_second_get(): void
    {
        $provider = $this->provider();
        $suffix = '';
        Http::fake(function ($request) use (&$suffix) {
            return Http::response($this->envelope([]), 200, ['Link' => '<'.$request->url().$suffix.'>; rel="next"']);
        });
        foreach ([
            '&date%20from=2025-03-09T00%3A00%3A00Z',
            '&date.from=2025-03-09T00%3A00%3A00Z',
            '&date_from%5B%5D=2025-03-09T00%3A00%3A00Z',
            '&DATE_FROM=2025-03-09T00%3A00%3A00Z',
            '&date_to=2025-03-11T00%3A00%3A00Z',
        ] as $suffix) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations', ['date_from' => '2025-03-10T08:00:00Z']);
                $this->fail('A continuation changed the explicit one-sided source window.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('window', $error->getMessage());
            }
        }
        Http::assertSentCount(5);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_link_parameter_order_and_multiple_relations_do_not_silently_truncate_source_pages(): void
    {
        $provider = $this->provider('https://partner.example/locations?country=NL');
        $header = '';
        Http::fake(function ($request) use (&$header) {
            if (str_contains($request->url(), 'offset=25')) {
                return Http::response($this->envelope([['id' => 'second']]));
            }

            return Http::response($this->envelope([['id' => 'first']]), 200, ['Link' => $header]);
        });
        foreach ([
            '<?country=NL&offset=25>; type="application/json"; rel="next"',
            '<?country=NL&offset=25>; title="records, next page"; rel="alternate next"',
            '<?country=NL&offset=0>; rel=prev, <?country=NL&offset=25>; REL="NEXT"',
        ] as $header) {
            $this->assertSame(['first', 'second'], array_column(app(PartnerClient::class)->pull($provider, 'locations'), 'id'));
        }
        Http::assertSentCount(6);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_malformed_or_ambiguous_link_cannot_be_treated_as_complete_source(): void
    {
        $provider = $this->provider();
        $header = '';
        Http::fake(function () use (&$header) {
            return Http::response($this->envelope([['id' => 'first']]), 200, ['Link' => $header]);
        });
        foreach ([
            '<?offset=1>; type="application/json"; rel=next, <?offset=2>; rel=next',
            '<?offset=1>; rel=next; rel=prev',
            '<?offset=1>; anchor="https://other.example/locations"; rel=next',
            '<?offset=1>; title="unterminated; rel=next',
            '<?offset=1>; rel=next,',
            '<?offset=1>; title="missing relation"',
            '<?offset=1>; rel',
            '<?offset=1>; rel=""',
        ] as $header) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations');
                $this->fail('A malformed or ambiguous Link was accepted as a complete source batch.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('pagination', $error->getMessage());
            }
        }
        Http::assertSentCount(8);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_duplicate_partner_next_links_fail_closed_before_any_second_get(): void
    {
        $provider = $this->provider();
        Http::fake(function ($request) {
            $first = $request->url().'&page=2';
            $second = $request->url().'&page=3';

            return Http::response($this->envelope([['id' => 'candidate-only', 'last_updated' => '2025-03-10T08:15:00Z']]), 200, [
                'Link' => '<'.$first.'>; rel="next", <'.$second.'>; rel="next"',
            ]);
        });
        try {
            app(PartnerClient::class)->pull($provider, 'locations', [
                'date_from' => '2025-03-10T08:00:00Z',
                'date_to' => '2025-03-10T08:30:00Z',
            ]);
            $this->fail('A source reply with competing next-page links was accepted.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('Ambiguous partner pagination links', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_partner_out_of_range_or_unknown_timestamps_fail_before_partial_source_acceptance(): void
    {
        $provider = $this->provider();
        $value = '2025-03-10T07:59:59Z';
        Http::fake(function () use (&$value) {
            return Http::response($this->envelope([[
                'id' => 'unexpected-source',
                'last_updated' => $value,
            ]]));
        });
        foreach ([
            '2025-03-10T07:59:59Z', // before inclusive lower
            '2025-03-10T08:30:00Z', // exactly exclusive upper
            '2025-03-10T08:30:01Z', // above upper
            '2025-02-30T08:15:00Z', // invalid calendar
            'yesterday', // not an OCPI DateTime
            null, // missing trusted source timestamp
        ] as $value) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations', [
                    'date_from' => '2025-03-10T08:00:00',
                    'date_to' => '2025-03-10T08:30:00Z',
                ]);
                $this->fail('Partner source outside explicit date window was trusted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('window', $error->getMessage());
            }
        }
        Http::assertSentCount(6);
        $this->assertDatabaseCount('ocpi_records', 0);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_invalid_read_window_is_rejected_without_any_partner_request(): void
    {
        $provider = $this->provider();
        Http::fake();
        foreach ([
            ['date_from' => '2025-03-10 08:00:00'],
            ['date_from' => 'yesterday'],
            ['date_from' => '2025-02-30T08:00:00Z'],
            // PHP DateTimeImmutable accepts and silently normalizes these
            // impossible numeric offsets without reporting parser warnings.
            // Reject before constructing a source URL or querying a partner.
            ['date_from' => '2025-03-10T08:00:00+99:99'],
            ['date_to' => '2025-03-10T08:00:00+14:30'],
            ['date_to' => '2025-03-10T08:00:00-24:00'],
            ['date_from' => '2025-03-10T08:00:00+00:61'],
            ['date_to' => '2025-03-10T08:00:00.1234567Z'],
            ['date_from' => '2025-03-10T08:30:00Z', 'date_to' => '2025-03-10T08:30:00Z'],
            ['date_from' => '2025-03-10T09:00:00Z', 'date_to' => '2025-03-10T08:30:00Z'],
            ['date_from' => ['invalid']],
            ['offset' => 5],
        ] as $invalid) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations', $invalid);
                $this->fail('Invalid or ambiguous OCPI source date window was accepted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('window', $error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_read_reply_rejects_non_ocpi_envelope_dates_before_accepting_any_source_data(): void
    {
        $provider = $this->provider();
        $timestamp = 'yesterday';
        Http::fake(function () use (&$timestamp) {
            return Http::response([
                'status_code' => 1000,
                'timestamp' => $timestamp,
                'data' => [['id' => 'must-not-accept']],
            ]);
        });
        foreach (['yesterday', '2025-02-30T08:00:00Z', '2025-03-10 08:00:00', '2025-03-10T08:00:00+00:00'] as $timestamp) {
            try {
                app(PartnerClient::class)->pull($provider, 'locations');
                $this->fail('Invalid OCPI response envelope timestamp was trusted.');
            } catch (OcpiException $error) {
                $this->assertStringContainsString('malformed', $error->getMessage());
            }
        }
        Http::assertSentCount(4);
        $this->assertDatabaseCount('ocpi_records', 0);
    }

    public function test_uncertain_sandbox_command_response_with_bad_envelope_date_is_never_replayed(): void
    {
        $provider = $this->provider();
        config(['ocpi.test_commands_enabled' => true, 'ocpi.connections.windowed-read-fixture.test_commands' => true]);
        $provider->update(['endpoints' => ['commands_receiver' => 'https://partner.example/commands']]);
        Http::fake(fn () => Http::response([
            'status_code' => 1000,
            'timestamp' => 'yesterday',
            'data' => ['result' => 'ACCEPTED', 'timeout' => 30],
        ]));
        try {
            app(PartnerClient::class)->write(
                $provider->fresh(), 'commands_receiver', 'POST', ['RESERVE_NOW'], ['reservation_id' => 'synthetic-only']
            );
            $this->fail('An invalid response timestamp certified a partner command.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('requires reconciliation', $error->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseCount('charger_commands', 0);
    }

    public function test_negotiated_endpoint_with_existing_date_window_cannot_be_overwritten(): void
    {
        $provider = $this->provider('https://partner.example/locations?date_from=2024-01-01T00%3A00%3A00Z');
        Http::fake();
        try {
            app(PartnerClient::class)->pull($provider, 'locations', ['date_from' => '2025-03-10T08:00:00Z']);
            $this->fail('An existing negotiated source bound was silently replaced.');
        } catch (OcpiException $error) {
            $this->assertStringContainsString('window', $error->getMessage());
        }
        Http::assertNothingSent();
    }
}
