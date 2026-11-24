<?php

namespace Tests\Feature;

use App\Models\Fleet;
use App\Models\User;
use App\Services\Commercial\CorporateBilling;
use App\Services\Commercial\Documents\DocumentRenderer;
use App\Services\Commercial\Documents\FinancialDocuments;
use App\Services\Commercial\Documents\FinancialProfiles;
use App\Services\Commercial\Documents\FinancialSources;
use App\Services\Commercial\SettlementService;
use App\Services\Finance\FinanceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FinancialDocumentFixtures;
use Tests\TestCase;

class FinancialDocumentsTest extends TestCase
{
    use FinancialDocumentFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Route::middleware(['web', 'auth'])->prefix('document-test')->group(base_path('routes/financial_documents.php'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app && DB::getSchemaBuilder()->hasTable('financial_documents')) {
                foreach (DB::table('financial_documents')->get() as $row) {
                    app(FinancialDocuments::class)->disk()->delete([$row->pdf_path, $row->csv_path]);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function generate(int $source, string $kind = 'charging_invoice', string $locale = 'en')
    {
        return $this->postJson('/document-test/financial/documents', ['source_kind' => $kind, 'source_id' => $source, 'locale' => $locale]);
    }

    public function test_real_pdf_csv_private_encryption_exact_amounts_and_natural_replay(): void
    {
        $f = $this->documentFixture();
        $this->actingAs($f['customer']);
        $first = $this->generate($f['invoice']->id)->assertCreated()->assertJsonPath('legal_status', 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED')->assertJsonMissingPath('pdf_path')->assertJsonMissingPath('encrypted_snapshot')->json();
        $this->generate($f['invoice']->id)->assertCreated()->assertJsonPath('id', $first['id'])->assertJsonPath('pdf_sha256', $first['pdf_sha256']);
        $this->assertSame($f['invoice']->number, $first['snapshot']['source']['original_number']);
        $this->assertSame(1297, $first['snapshot']['source']['total_minor']);
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertDatabaseCount('finance_journals', 1);
        $this->assertDatabaseCount('finance_payments', 0);
        $base = '/document-test/financial/documents/'.$first['id'];
        $pdf = $this->get($base.'/download/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Document-SHA256', $first['pdf_sha256']);
        $this->assertStringContainsString('attachment;', $pdf->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('%%EOF', substr($pdf->getContent(), -64));
        $this->assertSame($first['pdf_sha256'], hash('sha256', $pdf->getContent()));
        $csv = $this->get($base.'/download/csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('12.97', $csv);
        $row = DB::table('financial_documents')->find($first['id']);
        $this->assertStringNotContainsString($f['customer']->name, $row->encrypted_snapshot);
        $this->assertStringNotContainsString('%PDF-', app(FinancialDocuments::class)->disk()->get($row->pdf_path));
        $this->assertDatabaseHas('financial_document_sequences', ['next_number' => 2]);
    }

    public function test_all_seven_immutable_test_document_sources_require_exact_integer_minor_units(): void
    {
        $method = new \ReflectionMethod(
            FinancialSources::class,
            'exactMoney'
        );
        $this->assertSame(0, $method->invoke(null, '0'));
        $this->assertSame(9_007_199_254_740_991,
            $method->invoke(null, '9007199254740991'));
        $this->assertSame(-9_007_199_254_740_991,
            $method->invoke(null, '-9007199254740991', true));

        foreach ([
            ['9007199254740992', false],
            ['-1', false],
            ['-9007199254740992', true],
            ['999999999999999999999', true],
            ['0.01', false],
            ['1e10', false],
            [100.0, false],
            [-100.0, true],
            [true, false],
            ['not-invoice-money', false],
        ] as [$value, $signed]) {
            try {
                $method->invoke(null, $value, $signed);
                $this->fail('Document unexpectedly accepted inexact money: '.$value);
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('financial_documents', 0);
        $this->assertDatabaseCount('financial_document_sequences', 0);
    }

    public function test_document_consolidation_refuses_float_origin_and_oversized_source_line_sums(): void
    {
        $sum = new \ReflectionMethod(FinancialSources::class, 'sumExact');
        $this->assertSame(100, $sum->invoke(null, [
            ['amount_minor' => '70'],
            ['amount_minor' => 30],
        ], 'amount_minor'));
        $this->assertSame(100, $sum->invoke(null, [
            ['amount_minor' => 150],
            ['amount_minor' => -50],
        ], 'amount_minor', true));

        foreach ([
            [['amount_minor' => 4_503_599_627_370_496],
                ['amount_minor' => 4_503_599_627_370_496]],
            [['amount_minor' => '9007199254740991'],
                ['amount_minor' => 1]],
            [['amount_minor' => 100.0]],
            [['amount_minor' => true]],
        ] as $unsafeLines) {
            try {
                $sum->invoke(null, $unsafeLines, 'amount_minor');
                $this->fail('Unsafe corporate invoice line sum was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }

        try {
            $sum->invoke(null, [
                ['amount_minor' => -9_007_199_254_740_991],
                ['amount_minor' => -1],
            ], 'amount_minor', true);
            $this->fail('Unsafe signed settlement debt sum was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertDatabaseCount('financial_documents', 0);
        $this->assertDatabaseCount('financial_document_sequences', 0);
        $this->assertDatabaseCount('finance_journals', 0);
    }

    public function test_revoked_customer_source_cannot_read_download_or_generate_private_invoice_documents(): void
    {
        $f = $this->documentFixture();
        $customer = $f['customer'];
        $this->actingAs($customer);
        $document = $this->generate($f['invoice']->id)
            ->assertCreated()->json();
        $docId = $document['id'];
        $detail = '/document-test/financial/documents/'.$docId;
        $before = DB::table('financial_documents')->count();
        $sequence = DB::table('financial_document_sequences')->value('next_number');
        $journals = DB::table('finance_journals')->count();
        $persisted = DB::table('users')->where('id', $customer->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        $restore = (array) $persisted;
        $original = $this->get($detail.'/download/pdf')
            ->assertOk()->getContent();

        foreach ([
            ['role' => 'vendor'],
            ['account_status' => 'suspended'],
            ['auth_epoch' => (int) $persisted->auth_epoch + 1],
            ['password' => Hash::make('rotated-private-document-customer')],
        ] as $revocation) {
            DB::table('users')->where('id', $customer->id)->update($revocation);
            // Deliberately keep the original Laravel authenticated model.
            // A cached role/password cannot authorize encrypted documents.
            $this->getJson('/document-test/financial/documents')->assertConflict();
            $this->getJson($detail)->assertConflict();
            $this->get($detail.'/download/pdf')->assertConflict();
            $this->get($detail.'/download/csv')->assertConflict();
            $this->generate($f['invoice']->id)->assertConflict();
            try {
                app(FinancialDocuments::class)->generate(
                    $customer, 'charging_invoice', $f['invoice']->id, 'en'
                );
                $this->fail('Revoked service-level document generation was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            try {
                app(FinancialDocuments::class)->access($customer, $docId);
                $this->fail('Revoked service-level document read was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            DB::table('users')->where('id', $customer->id)->update($restore);
        }

        $this->assertSame($before, DB::table('financial_documents')->count());
        $this->assertSame($sequence,
            DB::table('financial_document_sequences')->value('next_number'));
        $this->assertSame($journals, DB::table('finance_journals')->count());
        $this->assertSame(
            hash('sha256', $original),
            hash('sha256', $this->get($detail.'/download/pdf')->assertOk()->getContent())
        );
        Http::assertNothingSent();
    }

    public function test_revoked_issuer_admin_never_probes_profile_validation_or_publishes_after_role_change(): void
    {
        $f = $this->documentFixture();
        $admin = $f['admin'];
        $this->actingAs($admin);
        $this->getJson('/document-test/financial/profiles')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $bad = ['vendor_id' => -1, 'country' => 'wrong'];
        $profileCount = DB::table('financial_profile_versions')->count();
        $documentCount = DB::table('financial_documents')->count();
        $persisted = DB::table('users')->where('id', $admin->id)
            ->first(['role', 'account_status', 'auth_epoch', 'password']);
        $restore = (array) $persisted;

        $this->postJson('/document-test/financial/profiles', $bad)->assertUnprocessable();
        foreach ([
            ['role' => 'vendor'],
            ['account_status' => 'suspended'],
            ['auth_epoch' => (int) $persisted->auth_epoch + 1],
            ['password' => Hash::make('rotated-private-issuer-admin')],
        ] as $revocation) {
            DB::table('users')->where('id', $admin->id)->update($revocation);
            $this->getJson('/document-test/financial/profiles')->assertConflict();
            $this->postJson('/document-test/financial/profiles', $bad)
                ->assertConflict();
            $this->postJson('/document-test/financial/documents', [
                'source_kind' => 'invalid-source',
            ])->assertConflict();
            try {
                app(FinancialProfiles::class)->publish(
                    $admin, $this->profileValues($f['vendor']->id)
                );
                $this->fail('Revoked service-level issuer publish was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            DB::table('users')->where('id', $admin->id)->update($restore);
        }
        $this->assertSame($profileCount,
            DB::table('financial_profile_versions')->count());
        $this->assertSame($documentCount,
            DB::table('financial_documents')->count());
        $this->assertDatabaseCount('financial_document_sequences', 0);
        Http::assertNothingSent();
    }

    public function test_all_four_immutable_source_types_generate_without_creating_financial_postings(): void
    {
        $f = $this->documentFixture();
        $finance = app(FinanceService::class);
        $finance->pay($f['invoice'], $f['customer']->id, $f['invoice']->total_minor, 'document-payment');
        $credit = $finance->refund($f['invoice'], $f['admin']->id, 100, 'document-credit', 'Synthetic credit note');
        [$subscriptionInvoice, $subscriptionCredit] = $this->subscriptionDocumentFixture($f);
        $before = DB::table('finance_journals')->count();
        $subscriptionBefore = DB::table('commercial_subscription_ledger')->count();
        $this->actingAs($f['customer']);
        foreach (['charging_invoice' => $f['invoice']->id, 'charging_credit' => $credit->id, 'subscription_invoice' => $subscriptionInvoice, 'subscription_credit' => $subscriptionCredit] as $kind => $id) {
            $doc = $this->generate($id, $kind)->assertCreated()->assertJsonPath('snapshot.source.source_kind', $kind)->json();
            $this->assertSame($doc['snapshot']['source']['total_minor'], $doc['snapshot']['source']['subtotal_minor'] + $doc['snapshot']['source']['tax_minor']);
            $this->assertStringStartsWith('%PDF-', $this->get('/document-test/financial/documents/'.$doc['id'].'/download/pdf')->assertOk()->getContent());
        }
        $this->assertDatabaseCount('financial_documents', 4);
        $this->assertSame($before, DB::table('finance_journals')->count());
        $this->assertSame($subscriptionBefore, DB::table('commercial_subscription_ledger')->count());
    }

    public function test_wrong_tenants_cannot_generate_list_read_or_download_and_issuer_controls_are_admin_only(): void
    {
        $f = $this->documentFixture();
        $this->actingAs($f['customer']);
        $doc = $this->generate($f['invoice']->id)->assertCreated()->json('id');
        foreach (['customer', 'vendor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->generate($f['invoice']->id)->assertNotFound();
            $this->getJson('/document-test/financial/documents')->assertOk()->assertJsonCount(0, 'data');
            $this->getJson('/document-test/financial/documents/'.$doc)->assertNotFound();
            $this->getJson('/document-test/financial/documents/'.$doc.'/download/pdf')->assertNotFound();
            $this->getJson('/document-test/financial/profiles')->assertForbidden();
            $this->postJson('/document-test/financial/profiles', $this->profileValues($f['vendor']->id))->assertForbidden();
        }
        $this->actingAs($f['vendor'])->getJson('/document-test/financial/documents/'.$doc)->assertOk();
        $this->actingAs($f['admin'])->getJson('/document-test/financial/documents')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/document-test/financial/documents', ['source_kind' => '../users', 'source_id' => 1, 'locale' => 'en'])->assertUnprocessable();
        $this->getJson('/document-test/financial/documents/'.$doc.'/download/html')->assertNotFound();
    }

    public function test_issuer_versions_replay_effective_dates_and_historical_document_bytes_are_stable(): void
    {
        $f = $this->documentFixture();
        $this->actingAs($f['customer']);
        $doc = $this->generate($f['invoice']->id)->assertCreated()->json();
        $before = $this->get('/document-test/financial/documents/'.$doc['id'].'/download/pdf')->getContent();
        $this->actingAs($f['admin']);
        $body = array_replace($this->profileValues($f['vendor']->id), ['legal_name' => 'Updated disclosed issuer', 'idempotency_key' => 'new-version']);
        $updated = $this->postJson('/document-test/financial/profiles', $body)->assertCreated()->assertJsonPath('version', 2)->assertJsonMissingPath('request_key')->json();
        $this->postJson('/document-test/financial/profiles', $body)->assertCreated()->assertJsonPath('id', $updated['id']);
        $this->postJson('/document-test/financial/profiles', array_replace($body, ['tax_registration' => 'different']))->assertConflict();
        $this->actingAs($f['customer']);
        $this->generate($f['invoice']->id)->assertCreated()->assertJsonPath('issuer_profile_id', $f['profile']->id);
        $this->assertSame($before, $this->get('/document-test/financial/documents/'.$doc['id'].'/download/pdf')->getContent());
        $this->getJson('/document-test/financial/documents/'.$doc['id'])->assertOk()->assertJsonPath('snapshot.issuer.legal_name', 'Crea-Biz Test Issuer');
    }

    public function test_missing_or_ambiguous_issuer_jurisdictions_fail_without_number_allocation_or_artifacts(): void
    {
        $f = $this->documentFixture();
        // A future profile does not apply retroactively to an existing source.
        DB::table('regions')->insert(['country' => 'ZZ', 'currency' => 'AED', 'minor_units' => 2, 'timezone' => 'UTC', 'commerce_enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
        app(FinancialProfiles::class)->publish($f['admin'], $this->profileValues(null, 'ZZ', 'AED', 2, 'another-country'));
        [$invoice] = $this->subscriptionDocumentFixture($f);
        $this->actingAs($f['customer']);
        $this->generate($invoice, 'subscription_invoice')->assertConflict();
        $this->assertDatabaseCount('financial_documents', 0);
        $this->assertDatabaseCount('financial_document_sequences', 0);
        $this->actingAs($f['admin'])->postJson('/document-test/financial/profiles', array_replace($this->profileValues($f['vendor']->id), ['currency' => 'USD', 'idempotency_key' => 'bad-currency']))->assertConflict();
    }

    public function test_escaped_html_and_csv_formula_defense_preserve_literal_disclosures(): void
    {
        $f = $this->documentFixture();
        $f['customer']->name = "\u{202E}=HYPERLINK(\"https://invalid.example\")";
        $f['customer']->save();
        app(FinancialProfiles::class)->publish($f['admin'], array_replace($this->profileValues($f['vendor']->id), ['legal_name' => '<img src="https://invalid.example/secret">', 'idempotency_key' => 'escaped-issuer']));
        $this->actingAs($f['customer']);
        $d = $this->generate($f['invoice']->id)->assertCreated()->json();
        $html = app(DocumentRenderer::class)->html($d['snapshot']);
        $this->assertStringNotContainsString('<img src=', $html);
        $this->assertStringContainsString('&lt;img src=', $html);
        $csv = $this->get('/document-test/financial/documents/'.$d['id'].'/download/csv')->assertOk()->getContent();
        $rows = explode("\n", substr($csv, 3));
        $values = str_getcsv($rows[1], ',', '"', '');
        $this->assertStringStartsWith("'", $values[6]);
        $this->assertStringContainsString('HYPERLINK', $values[6]);
        $literal = new \ReflectionMethod(DocumentRenderer::class, 'csvCell');
        foreach ([
            "\u{00A0}=SUM(1,1)",
            "\u{200B}@hidden",
            "\u{202E}+bidi",
            "\u{FEFF}-bom",
            "\u{2009}=thin-space",
        ] as $formula) {
            $this->assertSame("'".$formula, $literal->invoke(app(DocumentRenderer::class), $formula));
        }
        $this->assertSame('Public disclosure', $literal->invoke(
            app(DocumentRenderer::class), 'Public disclosure'
        ));
        Http::assertNothingSent();
    }

    public function test_english_arabic_documents_are_distinct_unicode_pdf_assets_with_immutable_snapshots(): void
    {
        $f = $this->documentFixture();
        $this->actingAs($f['customer']);
        $en = $this->generate($f['invoice']->id, 'charging_invoice', 'en')->assertCreated()->json();
        $ar = $this->generate($f['invoice']->id, 'charging_invoice', 'ar')->assertCreated()->json();
        $this->assertNotSame($en['number'], $ar['number']);
        $this->assertNotSame($en['pdf_sha256'], $ar['pdf_sha256']);
        $html = app(DocumentRenderer::class)->html($ar['snapshot']);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('كريا بيز', $html);
        foreach ([$en, $ar] as $doc) {
            $pdf = $this->get('/document-test/financial/documents/'.$doc['id'].'/download/pdf')->assertOk()->getContent();
            $this->assertStringContainsString('/ToUnicode', $pdf);
            $this->assertStringContainsString('/FontFile2', $pdf);
            if (getenv('EV_DOCUMENT_CAPTURE_EVIDENCE') === '1') {
                $directory = base_path('../../artifacts/financial-documents');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/synthetic-'.$doc['locale'].'.pdf', $pdf);
            }
        }
        try {
            DB::transaction(fn () => DB::table('financial_documents')->where('id', $en['id'])->update(['number' => 'changed']));
            $this->fail('Document mutation accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('financial_profile_versions')->where('id', $f['profile']->id)->delete());
            $this->fail('Issuer snapshot deletion accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_missing_or_corrupt_private_files_fail_closed_and_never_regenerate_history(): void
    {
        $f = $this->documentFixture();
        $this->actingAs($f['customer']);
        $doc = $this->generate($f['invoice']->id)->assertCreated()->json();
        $row = DB::table('financial_documents')->find($doc['id']);
        $service = app(FinancialDocuments::class);
        $service->disk()->put($row->pdf_path, 'corrupted-encrypted-file');
        $this->getJson('/document-test/financial/documents/'.$doc['id'].'/download/pdf')->assertStatus(503);
        $service->disk()->delete($row->csv_path);
        $this->getJson('/document-test/financial/documents/'.$doc['id'].'/download/csv')->assertStatus(503);
        $this->generate($f['invoice']->id)->assertCreated()->assertJsonPath('id', $doc['id'])->assertJsonPath('pdf_sha256', $doc['pdf_sha256']);
        $this->assertDatabaseCount('financial_documents', 1);
    }

    public function test_vendor_settlement_statements_snapshot_signed_lines_and_state_without_claiming_bank_payment(): void
    {
        $f = $this->documentFixture();
        $finance = app(FinanceService::class);
        $finance->pay($f['invoice'], $f['customer']->id, $f['invoice']->total_minor, 'statement-payment');
        DB::table('commercial_settlement_schedules')->insert(['vendor_id' => $f['vendor']->id, 'currency' => 'AED', 'hold_days' => 0, 'interval_days' => 7, 'minimum_minor' => 1, 'next_due_at' => now()->subMinute(), 'enabled' => true, 'configured_by' => $f['admin']->id, 'created_at' => now(), 'updated_at' => now()]);
        $settlements = app(SettlementService::class);
        $settlement = $settlements->draft($f['vendor']->id, 'AED', $f['admin']->id, 'statement-draft');
        $this->actingAs($f['vendor']);
        $first = $this->generate($settlement->id, 'vendor_settlement')->assertCreated()->assertJsonPath('snapshot.source.state_at_generation', 'pending')->assertJsonPath('current_source_state', 'pending')->json();
        $this->assertSame($f['platformProfile']->id, $first['issuer_profile_id']);
        $this->assertSame((int) $settlement->amount_minor, array_sum(array_column($first['snapshot']['source']['lines'], 'amount_minor')));
        $this->assertStringContainsString('not proof of a real bank transfer', $first['snapshot']['source']['status_notice_en']);
        $this->generate($settlement->id, 'vendor_settlement')->assertCreated()->assertJsonPath('id', $first['id']);
        $this->actingAs($f['customer']);
        $this->generate($settlement->id, 'vendor_settlement')->assertNotFound();
        $this->getJson('/document-test/financial/documents/'.$first['id'])->assertNotFound();
        $settlements->transition($settlement->id, $f['admin']->id, 'approve');
        $this->actingAs($f['vendor']);
        $this->getJson('/document-test/financial/documents/'.$first['id'])->assertOk()->assertJsonPath('current_source_state', 'approved')->assertJsonPath('snapshot.source.state_at_generation', 'pending');
        $second = $this->generate($settlement->id, 'vendor_settlement', 'ar')->assertCreated()->assertJsonPath('snapshot.source.state_at_generation', 'approved')->json();
        $this->assertNotSame($first['number'], $second['number']);
        $settlements->transition($settlement->id, $f['admin']->id, 'simulate-payout');
        $third = $this->generate($settlement->id, 'vendor_settlement')->assertCreated()->assertJsonPath('snapshot.source.state_at_generation', 'simulated_paid')->assertJsonPath('snapshot.source.evidence', 'SIMULATED')->json();
        $this->assertNotSame($first['id'], $third['id']);
        $this->get('/document-test/financial/documents/'.$third['id'].'/download/csv')->assertOk()->assertSee('Line reference', false)->assertSee($f['invoice']->number, false)->assertSee('simulated_paid', false);
        $this->assertSame('-1.23', DocumentRenderer::signedMoney(-123, 2));
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('finance_refunds', 0);
    }

    public function test_corporate_document_permissions_follow_current_company_owner_and_keep_issued_source_snapshots(): void
    {
        $f = $this->documentFixture();
        [$owner, $fleet, $corporate, $statement] = $this->corporateDocumentFixture($f);
        $this->actingAs($f['customer']);
        $this->generate($statement->id, 'corporate_statement')->assertNotFound();
        $this->actingAs($owner);
        $doc = $this->generate($statement->id, 'corporate_statement')->assertCreated()->assertJsonPath('snapshot.source.state_at_generation', 'issued')->assertJsonPath('snapshot.source.lines.0.cost_center_code', 'ENGINEERING')->assertJsonPath('snapshot.source.total_minor', 1297)->json();
        $corporate->simulatePayment($statement->id, $owner->id, 'document-corporate-payment');
        $this->getJson('/document-test/financial/documents/'.$doc['id'])->assertOk()->assertJsonPath('snapshot.source.state_at_generation', 'issued')->assertJsonPath('current_source_state', 'simulated_paid');
        $paid = $this->generate($statement->id, 'corporate_statement', 'ar')->assertCreated()->assertJsonPath('snapshot.source.state_at_generation', 'simulated_paid')->json();
        $this->get('/document-test/financial/documents/'.$paid['id'].'/download/csv')->assertOk()->assertSee('ENGINEERING', false);
        $newOwner = User::factory()->create();
        $fleet->update(['owner_id' => $newOwner->id]);
        $this->getJson('/document-test/financial/documents/'.$doc['id'])->assertNotFound();
        $this->getJson('/document-test/financial/documents/'.$paid['id'].'/download/pdf')->assertNotFound();
        $this->getJson('/document-test/financial/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($newOwner)->getJson('/document-test/financial/documents/'.$doc['id'])->assertOk()->assertJsonPath('snapshot.source.original_number', $statement->number);
        $this->getJson('/document-test/financial/documents')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($f['customer'])->getJson('/document-test/financial/documents/'.$doc['id'])->assertNotFound();
        $this->assertDatabaseCount('finance_payments', 1);
        $this->assertDatabaseCount('corporate_receipts', 1);
    }

    private function corporateDocumentFixture(array $f): array
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['owner_id' => $owner->id, 'name' => 'Document corporation', 'country' => 'AE']);
        DB::table('fleet_members')->insert(['fleet_id' => $fleet->id, 'user_id' => $f['customer']->id, 'status' => 'active', 'role' => 'driver', 'created_at' => now(), 'updated_at' => now()]);
        $center = DB::table('cost_centers')->insertGetId(['fleet_id' => $fleet->id, 'code' => 'ENGINEERING', 'name' => 'Engineering', 'created_at' => now(), 'updated_at' => now()]);
        $corporate = app(CorporateBilling::class);
        $agreement = $corporate->publish($fleet->id, $f['admin']->id, ['currency' => 'AED', 'minor_units' => 2, 'credit_limit_minor' => 100000, 'payment_terms_days' => 30, 'billing_day' => 1, 'effective_from' => now()->subDay()->toDateTimeString(), 'expires_at' => now()->addYear()->toDateTimeString(), 'legal_name' => 'Synthetic company', 'terms' => 'No real credit facility', 'idempotency_key' => 'doc-agreement']);
        $corporate->accept($agreement->id, $owner->id);
        $claim = $corporate->claim($agreement->id, $f['customer']->id, ['invoice_id' => $f['invoice']->id, 'cost_center_id' => $center, 'idempotency_key' => 'doc-claim']);
        $corporate->decide($claim->id, $owner->id, 'approved', 'Business charging');
        $statement = $corporate->issue($agreement->id, $owner->id, ['period' => now()->format('Y-m'), 'claim_ids' => [$claim->id], 'idempotency_key' => 'doc-statement']);

        return [$owner, $fleet, $corporate, $statement];
    }

    public function test_corporate_metadata_and_new_statement_version_preserve_canonical_collection_attention(): void
    {
        $f = $this->documentFixture();
        [$owner, $fleet, $corporate, $statement] = $this->corporateDocumentFixture($f);
        $this->actingAs($owner);
        $issued = $this->generate($statement->id, 'corporate_statement')->assertCreated()->assertJsonPath('current_source_state', 'issued')->json();
        app(FinanceService::class)->pay($f['invoice'], $f['customer']->id, 100, 'outside-corporate-payment');
        $this->getJson('/document-test/financial/documents/'.$issued['id'])->assertOk()->assertJsonPath('snapshot.source.state_at_generation', 'issued')->assertJsonPath('current_source_state', 'collection_attention');
        $attention = $this->generate($statement->id, 'corporate_statement')->assertCreated()->assertJsonPath('snapshot.source.state_at_generation', 'collection_attention')->assertJsonPath('current_source_state', 'collection_attention')->json();
        $this->assertNotSame($issued['id'], $attention['id']);
        $this->assertDatabaseCount('corporate_receipts', 0);
        $this->assertDatabaseCount('finance_payments', 1);
    }

    public function test_populated_document_history_cannot_be_downgraded_outside_disposable_tests(): void
    {
        $f = $this->documentFixture();
        $this->actingAs($f['customer']);
        $this->generate($f['invoice']->id)->assertCreated();
        $migration = require database_path('migrations/2026_10_28_190001_create_financial_documents.php');
        $this->app['env'] = 'local';
        try {
            $migration->down();
            $this->fail('Populated immutable document history was discarded.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve immutable', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertDatabaseCount('financial_documents', 1);
        $this->assertDatabaseCount('financial_profile_versions', 2);
    }

    public function test_zero_and_three_minor_unit_currencies_never_use_float_formatting(): void
    {
        $this->assertSame('1235', DocumentRenderer::money(1235, 0));
        $this->assertSame('1.235', DocumentRenderer::money(1235, 3));
        foreach ([['KW', 'KWD', 3, '1.297'], ['JP', 'JPY', 0, '1297']] as [$country, $currency, $units, $expected]) {
            $f = $this->documentFixture($country, $currency, $units);
            $this->actingAs($f['customer']);
            $doc = $this->generate($f['invoice']->id)->assertCreated()->assertJsonPath('snapshot.source.minor_units', $units)->json();
            $csv = $this->get('/document-test/financial/documents/'.$doc['id'].'/download/csv')->assertOk()->getContent();
            $this->assertStringContainsString($expected, $csv);
        }
    }
}
