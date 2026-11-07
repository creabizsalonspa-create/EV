<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCompany;
use App\Models\VendorOps\VendorKycDocument;
use Illuminate\Http\Request;

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
        $actor = $request->user();
        abort_unless($actor instanceof User
            && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);

        $filter = $request->validate([
            'status' => 'sometimes|required|in:draft,submitted,approved,rejected,changes_requested,suspended',
            'country' => ['sometimes', 'required', 'regex:/^[A-Z]{2}$/D'],
            'page' => 'sometimes|integer|min:1|max:100000',
        ]);
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
        $page = $query->orderByDesc('id')->paginate(25);

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

        return response()->json($page->through(function (VendorCompany $company) use ($owners, $evidence) {
            $owner = $owners->get($company->owner_id);
            $docs = $evidence->get($company->id) ?? collect();

            return [
                'id' => $company->id,
                'legal_name' => $company->legal_name,
                'country' => $company->country,
                'status' => $company->status,
                'created_at' => $company->created_at,
                'reviewed_at' => $company->reviewed_at,
                'license_expires_on' => $company->license_expires_on?->toDateString(),
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
        }))->header('Cache-Control', 'no-store, private');
    }
}
