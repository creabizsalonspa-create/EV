<?php

namespace App\Services\Commercial\Documents;

use App\Models\User;
use App\Services\Commercial\CorporateBilling;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FinancialDocuments
{
    public const TEMPLATE = 'financial-doc-v1';

    public function __construct(private FinancialSources $sources, private FinancialProfiles $profiles, private DocumentRenderer $renderer) {}

    public function disk(): FilesystemAdapter
    {
        // No public disk, filesystem URL, user-supplied path or temporary signed
        // storage link is used. HTTP downloads always recheck application access.
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private/financial-documents'), 'visibility' => 'private', 'throw' => true]);
    }

    public function access(User $actor, int $id): object
    {
        $row = DB::table('financial_documents')->find($id);
        if ($row && $row->source_kind === 'corporate_statement') {
            $this->sources->read($actor, 'corporate_statement', (int) $row->source_id);

            return $row;
        }
        abort_unless($row && ($actor->role === 'admin' || $actor->id === (int) $row->user_id || ($actor->role === 'vendor' && $actor->id === (int) $row->vendor_id)), 404);

        return $row;
    }

    public function generate(User $actor, string $kind, int $id, string $locale): object
    {
        abort_unless(in_array($locale, ['en', 'ar'], true), 422);
        $files = [];
        try {
            return DB::transaction(function () use ($actor, $kind, $id, $locale, &$files) {
                $source = $this->sources->read($actor, $kind, $id, true);
                $template = self::TEMPLATE.(in_array($kind, ['vendor_settlement', 'corporate_statement'], true) ? ':'.$source['state_at_generation'] : '');
                if ($old = DB::table('financial_documents')->where('source_kind', $kind)->where('source_id', $id)->where('locale', $locale)->where('template_version', $template)->first()) {
                    return $old;
                }
                $profile = $this->profiles->forSource($source);
                $sequence = $profile->scope_key.':'.now()->year;
                DB::table('financial_document_sequences')->insertOrIgnore(['sequence_key' => $sequence, 'next_number' => 1]);
                $counter = DB::table('financial_document_sequences')->where('sequence_key', $sequence)->lockForUpdate()->first();
                abort_unless((int) $counter->next_number < 1000000000, 409, 'Document sequence exhausted.');
                DB::table('financial_document_sequences')->where('sequence_key', $sequence)->update(['next_number' => (int) $counter->next_number + 1]);
                // An additive test-document reference, never a replacement tax/invoice number.
                $number = 'CB-TDOC-'.$profile->country.'-'.($profile->vendor_id ? 'V'.$profile->vendor_id : 'P').'-'.now()->year.'-'.str_pad((string) $counter->next_number, 9, '0', STR_PAD_LEFT);
                $issuer = array_intersect_key((array) $profile, array_flip(['id', 'version', 'vendor_id', 'country', 'currency', 'minor_units', 'legal_name', 'legal_name_ar', 'address', 'address_ar', 'tax_registration', 'effective_from', 'created_at']));
                $issuer['legal_review'] = 'UNVERIFIED_ADMIN_DISCLOSURE';
                $snapshot = ['number' => $number, 'locale' => $locale, 'template_version' => $template, 'captured_at' => now()->utc()->toIso8601String(), 'issuer' => $issuer, 'source' => $source, 'legal_status' => 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED'];
                $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $pdf = $this->renderer->pdf($snapshot);
                $csv = $this->renderer->csv($snapshot);
                $prefix = 'document-'.Str::ulid();
                $pdfPath = $prefix.'.pdf.enc';
                $csvPath = $prefix.'.csv.enc';
                foreach ([$pdfPath => $pdf, $csvPath => $csv] as $path => $bytes) {
                    $files[] = $path;
                    $this->disk()->put($path, Crypt::encryptString($bytes));
                }
                $document = DB::table('financial_documents')->insertGetId(['number' => $number, 'source_kind' => $kind, 'source_id' => $id, 'user_id' => $source['user_id'], 'vendor_id' => $source['vendor_id'], 'issuer_profile_id' => $profile->id, 'locale' => $locale, 'template_version' => $template, 'encrypted_snapshot' => Crypt::encryptString($json), 'snapshot_sha256' => hash('sha256', $json), 'pdf_sha256' => hash('sha256', $pdf), 'csv_sha256' => hash('sha256', $csv), 'pdf_path' => $pdfPath, 'csv_path' => $csvPath, 'generated_by' => $actor->id, 'created_at' => now()]);
                DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.private_test_document_generated', 'subject' => (string) $document, 'created_at' => now()]);

                return DB::table('financial_documents')->find($document);
            });
        } catch (Throwable $exception) {
            foreach ($files as $path) {
                $this->disk()->delete($path);
            }
            throw $exception;
        }
    }

    public function metadata(object $row, bool $withSnapshot = false): array
    {
        $v = array_intersect_key((array) $row, array_flip(['id', 'number', 'source_kind', 'source_id', 'issuer_profile_id', 'locale', 'template_version', 'snapshot_sha256', 'pdf_sha256', 'csv_sha256', 'created_at']));
        if ($row->source_kind === 'vendor_settlement') {
            $v['current_source_state'] = DB::table('commercial_settlements')->where('id', $row->source_id)->value('state');
            $v['status_semantics'] = 'IMMUTABLE_STATE_AT_GENERATION_NOT_REAL_BANK_PROOF';
        }
        if ($row->source_kind === 'corporate_statement') {
            $statement = DB::table('corporate_statements')->find($row->source_id);
            $v['current_source_state'] = app(CorporateBilling::class)->statementStatus($statement);
            $v['status_semantics'] = 'IMMUTABLE_STATE_AT_GENERATION_NOT_REAL_PAYMENT_PROOF';
        }
        if ($withSnapshot) {
            try {
                $json = Crypt::decryptString($row->encrypted_snapshot);
                abort_unless(hash_equals($row->snapshot_sha256, hash('sha256', $json)), 503, 'Document snapshot failed integrity verification.');
                $v['snapshot'] = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException $exception) {
                abort(503, 'Document snapshot is unavailable; no replacement was generated.');
            }
        }

        return $v + ['legal_status' => 'TEST_DOCUMENT_NOT_LEGALLY_APPROVED', 'external_provider_verified' => false];
    }

    public function bytes(object $row, string $format): string
    {
        abort_unless(in_array($format, ['pdf', 'csv'], true), 404);
        $path = $row->{$format.'_path'};
        abort_unless($this->disk()->exists($path), 503, 'Private document artifact is unavailable; no replacement was generated.');
        try {
            $bytes = Crypt::decryptString($this->disk()->get($path));
        } catch (DecryptException $exception) {
            abort(503, 'Private document artifact failed integrity verification.');
        }
        abort_unless(hash_equals($row->{$format.'_sha256'}, hash('sha256', $bytes)), 503, 'Private document artifact hash mismatch.');
        abort_unless($format !== 'pdf' || str_starts_with($bytes, '%PDF-'), 503, 'Invalid PDF artifact.');

        return $bytes;
    }
}
