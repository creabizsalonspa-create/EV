<?php

namespace App\Http\Controllers;

use App\Models\AccountPrivacyRequest;
use App\Services\Identity\AccountSecondFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Optional owner security step; never executes account erasure or alters source records. */
final class PrivacyErasureSecurityController extends Controller
{
    public function secure(Request $request, AccountPrivacyRequest $privacyCase, AccountSecondFactor $factor)
    {
        abort_unless($privacyCase->user_id === $request->user()->id, 404);
        $input = $request->validate([
            'confirmation' => 'required|string|in:discard_copies_and_revoke_sessions',
            'password' => 'required|string|max:128',
            'second_factor_code' => 'sometimes|nullable|string|max:64',
            'user_id' => 'prohibited',
        ]);

        $receipt = DB::transaction(function () use ($request, $privacyCase, $factor, $input) {
            $owner = $factor->account($request->user());
            $case = AccountPrivacyRequest::whereKey($privacyCase->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->user_id === $owner->id, 404);
            abort_unless($case->kind === 'erasure' && in_array($case->status, ['pending', 'reviewing', 'blocked_external'], true),
                409, 'An open erasure request is required.');
            if (! Hash::check($input['password'], $owner->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }

            // Only the owner's derived copy payloads may be discarded. Keep
            // all source data, digests, review records and financial history.
            // The user lock serializes copy preparation, read and revocation.
            $copies = DB::table('account_privacy_exports')->where('user_id', $owner->id)
                ->whereNotNull('payload_encrypted')->orderBy('id')->limit(251)
                ->lockForUpdate()->get(['id', 'privacy_request_id', 'purged_at', 'revoked_at']);
            abort_if($copies->count() > 250, 409, 'Assisted review required. No security step was applied.');
            $caseIds = $copies->pluck('privacy_request_id');
            $valid = AccountPrivacyRequest::whereIn('id', $caseIds)->where('user_id', $owner->id)
                ->where('kind', 'access')->where('status', 'closed_review')->orderBy('id')->lockForUpdate()->pluck('id');
            abort_unless($valid->count() === $copies->count(), 409, 'Private copy associations require assisted review.');
            abort_if($copies->contains(fn ($copy) => $copy->purged_at !== null || $copy->revoked_at !== null),
                409, 'Private copy state requires assisted review.');
            $factor->consume($owner, $input['second_factor_code'] ?? '');

            $at = now();
            $discarded = DB::table('account_privacy_exports')->where('user_id', $owner->id)
                ->whereIn('id', $copies->pluck('id'))->whereNotNull('payload_encrypted')
                ->whereNull('purged_at')->whereNull('revoked_at')
                ->update(['payload_encrypted' => null, 'purged_at' => $at, 'revoked_at' => $at]);
            abort_unless($discarded === $copies->count(), 409, 'Private copy state changed; refresh.');
            foreach ($copies as $copy) {
                DB::table('audit_events')->insert([
                    'actor_id' => $owner->id, 'action' => 'privacy.copy.discarded',
                    'subject' => (string) $copy->privacy_request_id, 'created_at' => $at,
                ]);
            }
            $factor->rotateAuthority($owner);
            DB::table('audit_events')->insert([
                'actor_id' => $owner->id, 'action' => 'privacy.erasure.security_prepared',
                'subject' => (string) $case->id, 'created_at' => $at,
            ]);

            return [
                'request_id' => $case->id, 'case_status' => $case->status,
                'derived_copies_discarded' => $discarded, 'sessions_revoked' => true,
                'sign_in_required' => true, 'automated_erasure_performed' => false,
                'source_records_deleted' => false, 'manual_processing_required' => true,
            ];
        }, 3);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json($receipt)->header('Cache-Control', 'no-store, private');
    }
}
