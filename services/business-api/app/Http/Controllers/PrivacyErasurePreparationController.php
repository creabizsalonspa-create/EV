<?php

namespace App\Http\Controllers;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Security\AdminTransactionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Two independent reviewers may prepare assisted review, never authorize deletion. */
final class PrivacyErasurePreparationController extends Controller
{
    private function context(Request $request, AccountPrivacyRequest $snapshot): array
    {
        $actor = AdminTransactionAuthority::requireCurrent($request->user());
        abort_if($snapshot->user_id === $actor->id, 403);
        $owner = User::whereKey($snapshot->user_id)->lockForUpdate()->firstOrFail();
        $case = AccountPrivacyRequest::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($case->user_id === $owner->id && $case->user_id !== $actor->id
            && in_array($owner->role, ['customer', 'vendor'], true)
            && $case->kind === 'erasure' && $case->status === 'reviewing', 409);
        $revision = DB::table('account_privacy_request_reviews')->where('privacy_request_id', $case->id)->orderByDesc('id')->first();
        abort_unless($revision && $revision->to_status === 'reviewing', 409);
        $identity = ['privacy_request_id' => $case->id, 'review_revision' => $revision->id,
            'owner_auth_epoch' => $owner->auth_epoch];

        return [$actor, $identity];
    }

    private function scope(array $identity): string
    {
        return hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function projection(array $identity): array
    {
        $plan = DB::table('privacy_erasure_preparations')->where($identity)->first();
        $approval = $plan ? DB::table('privacy_erasure_preparation_approvals')->where('preparation_id', $plan->id)->first() : null;

        return [
            'request_id' => (int) $identity['privacy_request_id'],
            'review_revision' => (string) $identity['review_revision'],
            'review_scope' => $this->scope($identity),
            'preparation_id' => $plan ? (string) $plan->id : null,
            'stage' => $approval ? 'assisted_review_prepared' : ($plan ? 'awaiting_independent_review' : 'not_prepared'),
            'disposition' => 'retain_pending_legal_and_external_review',
            'proposed_by' => $plan ? (int) $plan->reviewer_id : null,
            'confirmed_by' => $approval ? (int) $approval->reviewer_id : null,
            'execution_authorized' => false, 'legal_retention_verified' => false,
            'automated_erasure_performed' => false,
        ];
    }

    public function show(Request $request, AccountPrivacyRequest $privacyCase)
    {
        return DB::transaction(function () use ($request, $privacyCase) {
            [$actor, $identity] = $this->context($request, $privacyCase);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id, 'action' => 'privacy.erasure.preparation_viewed',
                'subject' => (string) $privacyCase->id, 'created_at' => now(),
            ]);

            return response()->json($this->projection($identity))->header('Cache-Control', 'no-store, private');
        }, 3);
    }

    public function record(Request $request, AccountPrivacyRequest $privacyCase)
    {
        if (is_string($request->input('reason'))) {
            $request->merge(['reason' => trim($request->input('reason'))]);
        }
        $input = $request->validate([
            'action' => 'required|in:propose,confirm',
            'review_revision' => ['required', 'string', 'regex:/^[1-9][0-9]{0,17}$/D'],
            'review_scope' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'preparation_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,17}$/D'],
            'password' => 'required|string|max:128',
            'reason' => 'required|string|min:10|max:2000',
            'execution_authorized' => 'prohibited', 'legal_retention_verified' => 'prohibited',
        ]);

        return DB::transaction(function () use ($request, $privacyCase, $input) {
            [$actor, $identity] = $this->context($request, $privacyCase);
            if (! Hash::check($input['password'], $actor->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }
            abort_unless((string) $identity['review_revision'] === $input['review_revision']
                && hash_equals($this->scope($identity), $input['review_scope']), 409, 'Privacy review changed; refresh.');
            $plan = DB::table('privacy_erasure_preparations')->where($identity)->lockForUpdate()->first();
            $action = $input['action'];
            if ($action === 'propose') {
                abort_if(isset($input['preparation_id']), 422);
                if ($plan) {
                    abort_unless((int) $plan->reviewer_id === $actor->id
                        && Crypt::decryptString($plan->reason_encrypted) === $input['reason'], 409);
                } else {
                    DB::table('privacy_erasure_preparations')->insert($identity + [
                        'reviewer_id' => $actor->id, 'reason_encrypted' => Crypt::encryptString($input['reason']),
                        'created_at' => now(),
                    ]);
                    $this->audit($actor, $privacyCase, 'proposed');
                }
            } else {
                abort_unless($plan && (string) $plan->id === ($input['preparation_id'] ?? null), 409);
                abort_if((int) $plan->reviewer_id === $actor->id, 403, 'Independent reviewer required.');
                $approval = DB::table('privacy_erasure_preparation_approvals')->where('preparation_id', $plan->id)->first();
                if ($approval) {
                    abort_unless((int) $approval->reviewer_id === $actor->id
                        && Crypt::decryptString($approval->reason_encrypted) === $input['reason'], 409);
                } else {
                    DB::table('privacy_erasure_preparation_approvals')->insert([
                        'preparation_id' => $plan->id, 'reviewer_id' => $actor->id,
                        'reason_encrypted' => Crypt::encryptString($input['reason']), 'created_at' => now(),
                    ]);
                    $this->audit($actor, $privacyCase, 'confirmed');
                }
            }

            return response()->json($this->projection($identity))->header('Cache-Control', 'no-store, private');
        }, 3);
    }

    private function audit(User $actor, AccountPrivacyRequest $case, string $action): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor->id, 'action' => 'privacy.erasure.preparation_'.$action,
            'subject' => (string) $case->id, 'created_at' => now(),
        ]);
    }
}
