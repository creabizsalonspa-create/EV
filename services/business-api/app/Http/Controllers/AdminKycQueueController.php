<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorKycDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminKycQueueController extends Controller
{
    /**
     * Read-only review queue. KYC review decisions continue to use the
     * established VendorOpsController::review endpoint and audit trail.
     * Document bytes remain accessible only through the existing authorized,
     * no-store VendorOpsController::download endpoint.
     */
    public function index(Request $request)
    {
        // Keep the live Admin row locked across the private KYC query,
        // decrypted-review metadata projection and successful-view audit.
        return DB::transaction(fn () => $this->indexCurrent($request));
    }

    private function indexCurrent(Request $request)
    {
        $snapshot = $request->user();
        abort_unless($snapshot instanceof User
            && $snapshot->role === 'admin'
            && $snapshot->account_status === 'active', 403);
        $actor = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->role === 'admin'
            && $actor->account_status === 'active', 403);
        abort_unless((int) $snapshot->auth_epoch === (int) $actor->auth_epoch
            && hash_equals((string) $snapshot->password, (string) $actor->password),
            409, 'Administrator credentials changed. Sign in again.');

        $filter = $request->validate([
            'status' => 'sometimes|required|in:draft,submitted,approved,rejected,changes_requested,suspended',
            'country' => ['sometimes', 'required', 'regex:/^[A-Z]{2}$/D'],
            'page' => 'sometimes|integer|min:1|max:100000',
            'license_health' => 'sometimes|required|in:expired,due_30_days,current,unknown',
        ]);
        // Use the configured local calendar, just as VendorAccess does for
        // license validity. Do not turn UTC midnight into an early expiry.
        // An invalid/missing region configuration is unknown, never "valid".
        $licenseCalendars = [];
        // Empty timezone values are accepted as the SERVER default by date
        // libraries. A missing/invalid country calendar cannot establish
        // business-license validity; accept only explicit IANA identifiers.
        $validTimezones = array_fill_keys(
            \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true
        );
        foreach (DB::table('regions')->get(['country', 'timezone']) as $region) {
            if (! is_string($region->timezone)
                || ($region->timezone !== 'UTC' && ! str_contains($region->timezone, '/'))
                || ! isset($validTimezones[$region->timezone])) {
                continue;
            }
            try {
                $today = CarbonImmutable::now($region->timezone);
            } catch (\Throwable) {
                continue;
            }
            $licenseCalendars[$region->country] = [
                'today' => $today->toDateString(),
                'due' => $today->addDays(30)->toDateString(),
            ];
        }

        // Prioritize cases awaiting a human review. Every other state must be
        // explicitly selected by the reviewer, not silently commingled.
        $status = $filter['status'] ?? 'submitted';
        $query = VendorCompany::query()
            ->select([
                'id', 'owner_id', 'legal_name', 'country', 'status',
                'license_expires_on', 'created_at', 'reviewed_at',
            ])
            ->where('status', $status);
        if (isset($filter['country'])) {
            $query->where('country', $filter['country']);
        }
        if (isset($filter['license_health'])) {
            $health = $filter['license_health'];
            $query->where(function ($scope) use ($health, $licenseCalendars) {
                // A false initial condition keeps every OR branch grouped.
                $scope->whereRaw('1 = 0');
                if ($health === 'unknown') {
                    $scope->orWhereNull('license_expires_on')
                        ->orWhereNotIn('country', array_keys($licenseCalendars));

                    return;
                }
                foreach ($licenseCalendars as $country => $local) {
                    $scope->orWhere(function ($perCountry) use ($country, $health, $local) {
                        $perCountry->where('country', $country);
                        if ($health === 'expired') {
                            $perCountry->where('license_expires_on', '<', $local['today']);
                        } elseif ($health === 'due_30_days') {
                            $perCountry->whereBetween('license_expires_on', [$local['today'], $local['due']]);
                        } else {
                            $perCountry->where('license_expires_on', '>', $local['due']);
                        }
                    });
                }
            });
        }
        $page = $query->orderByDesc('id')->paginate(25)->appends($filter);

        $companies = $page->getCollection();
        $companyIds = $companies->pluck('id');
        $owners = User::query()
            ->whereIn('id', $companies->pluck('owner_id'))
            ->get(['id', 'name', 'email', 'account_status', 'email_verified_at'])
            ->keyBy('id');
        $evidence = VendorKycDocument::query()
            ->whereIn('company_id', $companyIds)
            ->get(['company_id', 'kind'])
            ->groupBy('company_id');

        $view = $page->through(function (VendorCompany $company) use ($owners, $evidence, $licenseCalendars) {
            $owner = $owners->get($company->owner_id);
            $docs = $evidence->get($company->id) ?? collect();
            $local = $licenseCalendars[$company->country] ?? null;
            $expiry = $company->license_expires_on?->toDateString();
            $licenseHealth = ! $local || ! $expiry ? 'unknown'
                : ($expiry < $local['today'] ? 'expired'
                    : ($expiry <= $local['due'] ? 'due_30_days' : 'current'));

            return [
                'id' => $company->id,
                'legal_name' => $company->legal_name,
                'country' => $company->country,
                'status' => $company->status,
                'created_at' => $company->created_at,
                'reviewed_at' => $company->reviewed_at,
                'license_expires_on' => $expiry,
                // Date-based triage only. Not evidence of an authentic license,
                // a completed review, or permission to control a charger.
                'license_health' => $licenseHealth,
                'license_local_date' => $local['today'] ?? null,
                'owner' => $owner ? [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'account_status' => $owner->account_status,
                    'email_verified' => $owner->email_verified_at !== null,
                ] : null,
                // Presence only. These facts are not evidence authenticity or
                // approval readiness. No storage path, filename or bytes.
                'document_count' => $docs->count(),
                'document_kinds' => $docs->pluck('kind')->unique()->sort()->values()->all(),
                'manual_review_required' => true,
                'physical_control_enabled' => false,
            ];
        });

        DB::table('audit_events')->insert([
            'actor_id' => $actor->id,
            'action' => 'admin.private_list.kyc_viewed',
            'subject' => 'status:'.$status.(isset($filter['license_health']) ? ';license:'.$filter['license_health'] : ''),
            'created_at' => now(),
        ]);

        return response()->json($view)->header('Cache-Control', 'no-store, private');
    }
}
