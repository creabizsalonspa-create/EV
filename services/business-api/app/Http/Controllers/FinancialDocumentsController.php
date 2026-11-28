<?php

namespace App\Http\Controllers;

use App\Security\CurrentFinanceReader;
use App\Services\Commercial\Documents\FinancialDocuments;
use App\Services\Commercial\Documents\FinancialProfiles;
use App\Services\Commercial\Documents\FinancialSources;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinancialDocumentsController extends Controller
{
    private function profileRow(object $row): array
    {
        $v = (array) $row;
        unset($v['request_key'], $v['fingerprint']);

        return $v + ['legal_review' => 'UNVERIFIED_ADMIN_DISCLOSURE'];
    }

    public function profiles(Request $r)
    {
        return DB::transaction(function () use ($r) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);

            return response()->json(
                DB::table('financial_profile_versions')
                    ->orderByDesc('id')->paginate(25)
                    ->through(fn ($row) => $this->profileRow($row))
            )->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function publish(Request $r, FinancialProfiles $profiles)
    {
        return DB::transaction(function () use ($r, $profiles) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            abort_unless($actor->role === 'admin', 403);
            $text = ['required', 'string', 'regex:/^[^\\x00-\\x1F\\x7F]*$/u'];
            // Validation must follow current Admin authority. A revoked
            // issuer editor cannot learn country/tax field policy by
            // supplying a malformed private form to a stale browser.
            $v = $r->validate([
                'vendor_id' => 'nullable|integer|min:1',
                'country' => ['required', 'regex:/^[A-Z]{2}$/D'],
                'currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
                'minor_units' => 'required|integer|between:0,3',
                'legal_name' => [...$text, 'max:160'],
                'legal_name_ar' => [...$text, 'max:160'],
                'address' => [...$text, 'max:500'],
                'address_ar' => [...$text, 'max:500'],
                'tax_registration' => [
                    'nullable', 'string', 'max:80',
                    'regex:/^[^\\x00-\\x1F\\x7F]*$/u',
                ],
                'effective_from' => 'required|date',
                'idempotency_key' => 'required|string|max:100',
            ]);
            $v['effective_from'] = CarbonImmutable::parse(
                $v['effective_from']
            )->utc()->toDateTimeString();

            return response()->json(
                $this->profileRow($profiles->publish($actor, $v)), 201
            )->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function index(Request $r, FinancialDocuments $documents)
    {
        return DB::transaction(function () use ($r, $documents) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $v = $r->validate([
                'source_kind' => [
                    'sometimes', Rule::in(array_keys(FinancialSources::TABLES)),
                ],
                'source_id' => 'sometimes|integer|min:1',
            ]);
            $query = DB::table('financial_documents');
            if ($actor->role !== 'admin') {
                $query->where(function ($q) use ($actor) {
                    $q->where(function ($owned) use ($actor) {
                        $owned->where('source_kind', '!=', 'corporate_statement')
                            ->where(function ($source) use ($actor) {
                                $source->where('user_id', $actor->id);
                                if ($actor->role === 'vendor') {
                                    $source->orWhere('vendor_id', $actor->id);
                                }
                            });
                    })->orWhere(function ($corporate) use ($actor) {
                        $corporate->where('source_kind', 'corporate_statement')
                            ->whereIn('source_id',
                                DB::table('corporate_statements as s')
                                    ->join('fleets as f', 'f.id', '=', 's.fleet_id')
                                    ->where('f.owner_id', $actor->id)
                                    ->select('s.id'));
                    });
                });
            }
            foreach ($v as $key => $value) {
                $query->where($key, $value);
            }

            return response()->json(
                $query->orderByDesc('id')->paginate(25)
                    ->through(fn ($row) => $documents->metadata($row))
            )->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function generate(Request $r, FinancialDocuments $documents)
    {
        // Verify current identity before parsing private source IDs. The
        // service independently locks and rechecks this same identity
        // inside its OWN encrypted-file/DB transaction. Do not wrap file
        // writes in an outer controller transaction: an unrelated response
        // formatting exception after the inner write could rollback DB
        // but strand private encrypted files outside the filesystem cleanup.
        $actor = DB::transaction(
            fn () => app(CurrentFinanceReader::class)->requireCurrent($r), 3
        );
        $v = $r->validate([
            'source_kind' => [
                'required', Rule::in(array_keys(FinancialSources::TABLES)),
            ],
            'source_id' => 'required|integer|min:1',
            'locale' => 'required|in:en,ar',
        ]);
        $row = $documents->generate(
            $actor, $v['source_kind'], (int) $v['source_id'], $v['locale']
        );

        return response()->json($documents->metadata($row, true), 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, int $document, FinancialDocuments $documents)
    {
        return DB::transaction(function () use ($r, $document, $documents) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $record = $documents->access($actor, $document);

            return response()->json($documents->metadata($record, true))
                ->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function download(Request $r, int $document, string $format, FinancialDocuments $documents)
    {
        return DB::transaction(function () use ($r, $document, $format, $documents) {
            $actor = app(CurrentFinanceReader::class)->requireCurrent($r);
            $row = $documents->access($actor, $document);
            // The downloaded bytes are materialized under the SAME current
            // account lock, so a revoked model cannot read private files
            // after an authorization check but before the disk access.
            $bytes = $documents->bytes($row, $format);

            return response($bytes)
                ->header('Content-Type',
                    $format === 'pdf' ? 'application/pdf' : 'text/csv; charset=UTF-8')
                ->header('Content-Disposition',
                    'attachment; filename="'.$row->number.'-'.$row->locale.'.'.$format.'"')
                ->header('Cache-Control', 'private, no-store, max-age=0')
                ->header('Pragma', 'no-cache')
                ->header('X-Content-Type-Options', 'nosniff')
                ->header('X-Document-SHA256', $row->{$format.'_sha256'});
        }, 3);
    }
}
