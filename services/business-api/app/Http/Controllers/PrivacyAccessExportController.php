<?php

namespace App\Http\Controllers;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use App\Services\Identity\AccountSecondFactor;
use App\Services\Privacy\AccountDataExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PrivacyAccessExportController extends Controller
{
    private function audit(int $actor, string $action, int $case): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'privacy.copy.'.$action, 'subject' => (string) $case, 'created_at' => now()]);
    }

    public function prepare(Request $r, AccountPrivacyRequest $privacyCase, AccountDataExport $exports)
    {
        abort_unless($r->user()->role === 'admin' && $r->user()->account_status === 'active', 403);
        abort_if($privacyCase->user_id === $r->user()->id, 403, 'A separate administrator must fulfill this request.');
        $v = $r->validate(['password' => 'required|string|max:128', 'request_key' => 'required|string|min:12|max:100|regex:/^[A-Za-z0-9_-]+$/D']);

        return DB::transaction(function () use ($r, $privacyCase, $exports, $v) {
            $admin = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($admin->role === 'admin' && $admin->account_status === 'active' && (int) $admin->auth_epoch === (int) $r->user()->auth_epoch, 403);
            if (! Hash::check($v['password'], $admin->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }
            $owner = User::whereKey($privacyCase->user_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($owner->role, ['customer', 'vendor'], true) && $owner->account_status === 'active', 409, 'Assisted fulfillment is required for this account.');
            $case = AccountPrivacyRequest::whereKey($privacyCase->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->kind === 'access', 409, 'Only access requests support a personal copy.');
            $prior = DB::table('account_privacy_exports')->where('privacy_request_id', $case->id)->first();
            if ($prior) {
                abort_unless((int) $prior->prepared_by === $admin->id && hash_equals($prior->request_key, $v['request_key']), 409, 'A copy already exists; refresh the request.');

                return response()->json(['request_id' => $case->id, 'copy' => $exports->metadata($case->id)])->header('Cache-Control', 'no-store, private');
            }
            abort_unless($case->status === 'reviewing', 409, 'Review the access request before preparing its copy.');
            $body = $exports->snapshot($owner, $case);
            DB::table('account_privacy_exports')->insert(['privacy_request_id' => $case->id, 'user_id' => $owner->id, 'prepared_by' => $admin->id, 'request_key' => $v['request_key'], 'payload_encrypted' => Crypt::encryptString($body), 'sha256' => hash('sha256', $body), 'byte_length' => strlen($body), 'expires_at' => now()->addDay(), 'created_at' => now()]);
            $note = Crypt::encryptString('A bounded self-service personal account copy was prepared for 24 hours. Excluded records need assisted review. No erasure or financial mutation was performed.');
            DB::table('account_privacy_request_reviews')->insert(['privacy_request_id' => $case->id, 'reviewer_id' => $admin->id, 'from_status' => 'reviewing', 'to_status' => 'closed_review', 'note_encrypted' => $note, 'created_at' => now()]);
            $case->update(['status' => 'closed_review', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note_encrypted' => $note]);
            $this->audit($admin->id, 'prepared', $case->id);

            return response()->json(['request_id' => $case->id, 'copy' => $exports->metadata($case->id)], 201)->header('Cache-Control', 'no-store, private');
        }, 3);
    }

    public function read(Request $r, AccountPrivacyRequest $privacyCase, AccountSecondFactor $factor)
    {
        abort_unless($privacyCase->user_id === $r->user()->id, 404);
        $v = $r->validate(['password' => 'required|string|max:128', 'second_factor_code' => 'sometimes|nullable|string|max:64']);

        return DB::transaction(function () use ($r, $privacyCase, $factor, $v) {
            $owner = $factor->account($r->user());
            if (! Hash::check($v['password'], $owner->password)) {
                throw ValidationException::withMessages(['password' => 'Current password is required.']);
            }
            $case = AccountPrivacyRequest::whereKey($privacyCase->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->user_id === $owner->id, 404);
            $copy = DB::table('account_privacy_exports')->where('privacy_request_id', $case->id)->lockForUpdate()->first();
            abort_unless($copy && (int) $copy->user_id === $owner->id && $case->kind === 'access' && $case->status === 'closed_review', 404);
            abort_if($copy->purged_at || $copy->revoked_at || ! $copy->payload_encrypted || $copy->expires_at <= now()->toDateTimeString(), 410, 'This private copy has expired or been discarded. Submit a new access request.');
            $factor->consume($owner, $v['second_factor_code'] ?? '');
            $body = Crypt::decryptString($copy->payload_encrypted);
            abort_unless(strlen($body) === (int) $copy->byte_length && hash_equals($copy->sha256, hash('sha256', $body)), 409, 'Private copy integrity failed.');
            $this->audit($owner->id, 'viewed', $case->id);

            return response($body)->header('Content-Type', 'application/json; charset=UTF-8')->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
        }, 3);
    }

    public function revoke(Request $r, AccountPrivacyRequest $privacyCase)
    {
        abort_unless($privacyCase->user_id === $r->user()->id, 404);

        return DB::transaction(function () use ($r, $privacyCase) {
            $owner = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($owner->account_status === 'active' && (int) $owner->auth_epoch === (int) $r->user()->auth_epoch, 403);
            $copy = DB::table('account_privacy_exports')->where('privacy_request_id', $privacyCase->id)->where('user_id', $owner->id)->lockForUpdate()->first();
            abort_unless($copy, 404);
            if (! $copy->revoked_at) {
                DB::table('account_privacy_exports')->where('id', $copy->id)->update(['payload_encrypted' => null, 'purged_at' => now(), 'revoked_at' => now()]);
                $this->audit($owner->id, 'discarded', $privacyCase->id);
            }

            return response()->json(['request_id' => $privacyCase->id, 'discarded' => true])->header('Cache-Control', 'no-store, private');
        }, 3);
    }
}
