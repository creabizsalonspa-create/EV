<?php

namespace App\Services\Privacy;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Preserve any privately staged PR60 intake rows; never drop legacy evidence. */
class LegacyPrivacyIntakeImporter
{
    public function import(): int
    {
        if (! Schema::hasTable('privacy_requests')) {
            return 0;
        }
        $count = 0;
        DB::table('privacy_requests')->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $count += DB::transaction(function () use ($row) {
                    // Orphaned retained reviews have no current account audience;
                    // keep them intact in the legacy archive instead of inventing an owner.
                    if ($row->user_id === null) {
                        return 0;
                    }
                    User::whereKey($row->user_id)->lockForUpdate()->firstOrFail();
                    if (AccountPrivacyRequest::where('legacy_privacy_request_id', $row->id)->exists()) {
                        return 0;
                    }
                    $kind = ['data_access' => 'access', 'account_deletion' => 'erasure'][$row->request_type] ?? null;
                    $status = ['submitted' => 'pending', 'in_review' => 'reviewing', 'closed' => 'closed_review', 'withdrawn' => 'withdrawn'][$row->status] ?? null;
                    if (! $kind || ! $status) {
                        throw new RuntimeException('Legacy privacy state requires protected import review.');
                    }
                    $details = $row->reason === null ? 'Legacy '.$kind.' request; no additional explanation was provided.' : Crypt::decryptString($row->reason);
                    $note = $row->decision_note === null ? null : Crypt::decryptString($row->decision_note);
                    if (AccountPrivacyRequest::where('user_id', $row->user_id)->where('idempotency_key', $row->idempotency_key)->exists()) {
                        throw new RuntimeException('Legacy privacy identity collision requires protected import review.');
                    }
                    $case = AccountPrivacyRequest::create([
                        'user_id' => $row->user_id, 'kind' => $kind, 'status' => $status,
                        'request_details_encrypted' => Crypt::encryptString($details),
                        'review_note_encrypted' => $note === null ? null : Crypt::encryptString($note),
                        'reviewed_by' => $row->reviewer_id, 'reviewed_at' => $row->reviewed_at,
                        'withdrawn_at' => $row->withdrawn_at, 'idempotency_key' => $row->idempotency_key,
                        'legacy_privacy_request_id' => $row->id,
                        'legacy_resolution' => $row->resolution,
                        'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
                    ]);
                    if ($note !== null && $row->reviewer_id !== null) {
                        DB::table('account_privacy_request_reviews')->insert([
                            'privacy_request_id' => $case->id, 'reviewer_id' => $row->reviewer_id,
                            'from_status' => 'reviewing', 'to_status' => $status,
                            'note_encrypted' => Crypt::encryptString($note),
                            'created_at' => $row->reviewed_at ?? $row->updated_at,
                        ]);
                    }

                    return 1;
                });
            }
        });

        return $count;
    }
}
