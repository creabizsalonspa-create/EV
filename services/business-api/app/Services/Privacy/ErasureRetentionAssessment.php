<?php

namespace App\Services\Privacy;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Existence observations only; never a deletion plan, permission or legal retention determination. */
final class ErasureRetentionAssessment
{
    private function observed(callable $probe): string
    {
        try {
            // Isolate missing or unavailable tables using a savepoint on
            // PostgreSQL. Unknown must not appear as an empty dependency.
            return DB::transaction(fn () => $probe() ? 'present' : 'not_observed');
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function inspect(User $owner, AccountPrivacyRequest $case): array
    {
        abort_unless(DB::transactionLevel() > 0 && $case->user_id === $owner->id && $case->kind === 'erasure', 409);
        $id = $owner->id;
        $owned = fn ($table) => DB::table($table)->where('user_id', $id);
        $financial = $this->observed(fn () => DB::table('finance_invoices')->where(function ($q) use ($id) {
            $q->where('user_id', $id)->orWhere('vendor_id', $id);
        })->exists() || $owned('loyalty_ledger')->exists() || $owned('commercial_subscriptions')->exists());
        $charging = $this->observed(fn () => $owned('reservations')->exists() || $owned('vehicles')->exists());
        $business = $this->observed(fn () => DB::table('vendor_companies')->where('owner_id', $id)->exists()
            || $owned('vendor_company_staff')->exists());
        $support = $this->observed(fn () => $owned('support_tickets')->exists()
            || DB::table('ticket_messages')->where('author_id', $id)->exists());
        $privacy = $this->observed(fn () => $owned('account_privacy_requests')->exists() || $owned('account_privacy_exports')->exists());
        $credentials = $this->observed(fn () => $owner->tokens()->exists() || $owned('sessions')->exists());
        $dependencies = [
            'financial_records' => $financial,
            'charging_records' => $charging,
            'business_relationships' => $business,
            'support_records' => $support,
            'privacy_records' => $privacy,
            'active_credentials' => $credentials,
            // Off-platform obligations, backups, jurisdictions, legal holds
            // and external provider data cannot be established by this query.
            'external_and_legal_retention' => 'unknown',
        ];

        return [
            'request_id' => $case->id, 'owner_id' => $owner->id, 'case_status' => $case->status,
            'scope' => 'local_dependency_assessment', 'manual_processing_required' => true,
            'execution_authorized' => false, 'automated_erasure_performed' => false, 'legal_retention_verified' => false,
            'dependencies' => collect($dependencies)->map(fn ($state, $key) => ['key' => $key, 'state' => $state])->values()->all(),
        ];
    }
}
