<?php

namespace App\Http\Controllers;

use App\Models\AccountPrivacyRequest;
use App\Services\Identity\AccountSecondFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Owner-authorized removal of optional contact data, not full account erasure. */
final class PrivacyContactMinimizationController extends Controller
{
    public function remove(Request $request, AccountPrivacyRequest $privacyCase, AccountSecondFactor $factor)
    {
        abort_unless($privacyCase->user_id === $request->user()->id, 404);
        $input = $request->validate([
            'confirmation' => 'required|string|in:remove_optional_phone',
            'password' => 'required|string|max:128',
            'second_factor_code' => 'sometimes|nullable|string|max:64',
            'user_id' => 'prohibited',
            'email' => 'prohibited',
            'name' => 'prohibited',
        ]);

        return DB::transaction(function () use ($request, $privacyCase, $factor, $input) {
            // Same account lock as phone challenge creation and confirmation.
            $owner = $factor->account($request->user());
            $case = AccountPrivacyRequest::whereKey($privacyCase->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->user_id === $owner->id, 404);
            abort_unless($case->kind === 'erasure' && in_array($case->status, ['pending', 'reviewing', 'blocked_external'], true),
                409, 'An open erasure request is required.');
            if (! Hash::check($input['password'], $owner->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }

            // Retain historical verification outcomes and rate-limit hashes.
            // No external provider calls or claims of external deletion.
            $query = DB::table('account_phone_challenges')->where('user_id', $owner->id);
            $ids = (clone $query)->where(function ($q) {
                $q->whereIn('status', ['pending', 'uncertain'])->orWhereNotNull('phone_ciphertext');
            })->orderBy('id')->limit(251)->lockForUpdate()->pluck('id');
            abort_if($ids->count() > 250, 409, 'Assisted review required; no contact data was changed.');
            $hadPhone = $owner->getRawOriginal('phone_e164') !== null || $owner->phone_verified_at !== null;
            $factor->consume($owner, $input['second_factor_code'] ?? '');
            $at = now();
            $cancelled = (clone $query)->whereIn('id', $ids)->whereIn('status', ['pending', 'uncertain'])
                ->update(['status' => 'removed', 'updated_at' => $at]);
            $cleared = (clone $query)->whereIn('id', $ids)->whereNotNull('phone_ciphertext')
                ->update(['phone_ciphertext' => null, 'updated_at' => $at]);
            if ($hadPhone) {
                $owner->forceFill(['phone_e164' => null, 'phone_verified_at' => null])->save();
            }
            if ($hadPhone || $cancelled || $cleared) {
                DB::table('audit_events')->insert([
                    'actor_id' => $owner->id, 'action' => 'privacy.erasure.optional_contact_minimized',
                    'subject' => (string) $case->id, 'created_at' => $at,
                ]);
            }

            return response()->json([
                'request_id' => $case->id, 'case_status' => $case->status,
                'optional_phone_removed' => true, 'profile_phone_changed' => $hadPhone,
                'live_challenges_cancelled' => $cancelled, 'challenge_payloads_cleared' => $cleared,
                'verification_receipts_retained' => true, 'external_deletion_verified' => false,
                'account_deleted' => false, 'automated_erasure_performed' => false,
                'manual_processing_required' => true,
            ])->header('Cache-Control', 'no-store, private');
        }, 3);
    }
}
