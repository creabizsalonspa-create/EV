<?php

namespace App\Http\Controllers;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AccountPrivacyController extends Controller
{
    public function mine(Request $request)
    {
        $page = AccountPrivacyRequest::query()
            ->where('user_id', $request->user()->id)
            ->select(['id', 'kind', 'status', 'created_at', 'updated_at'])
            ->orderByDesc('id')->paginate(25);

        return response()->json($page)->header('Cache-Control', 'no-store, private');
    }

    /**
     * Captures privacy rights requests. No deletion, export or financial
     * ledger mutation is performed: those require separate authorized work.
     */
    public function submit(Request $request)
    {
        $input = $request->validate([
            'kind' => 'required|in:access,correction,erasure',
            'details' => 'required|string|min:10|max:2000',
        ]);
        $actor = $request->user();

        return DB::transaction(function () use ($actor, $input) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $already = AccountPrivacyRequest::query()
                ->where('user_id', $actor->id)
                ->where('kind', $input['kind'])
                ->whereIn('status', ['pending', 'reviewing', 'blocked_external'])
                ->lockForUpdate()->first(['id']);
            abort_if($already !== null, 409, 'An open request of this kind already exists.');

            $record = AccountPrivacyRequest::create([
                'user_id' => $actor->id,
                'kind' => $input['kind'],
                'status' => 'pending',
                'request_details_encrypted' => Crypt::encryptString(trim($input['details'])),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'privacy.requested.'.$record->kind,
                'subject' => (string) $record->id,
                'created_at' => now(),
            ]);

            return response()->json([
                'id' => $record->id,
                'kind' => $record->kind,
                'status' => $record->status,
                'manual_processing_required' => true,
                'automated_erasure_performed' => false,
            ], 201)->header('Cache-Control', 'no-store, private');
        });
    }

    private function admin(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User
            && $actor->role === 'admin'
            && $actor->account_status === 'active', 403);

        return $actor;
    }

    public function adminIndex(Request $request)
    {
        $this->admin($request);
        $filters = $request->validate([
            'status' => 'sometimes|required|in:pending,reviewing,blocked_external,closed_review',
            'kind' => 'sometimes|required|in:access,correction,erasure',
            'user_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1|max:100000',
        ]);
        $query = AccountPrivacyRequest::query()->select([
            'id', 'user_id', 'kind', 'status', 'request_details_encrypted',
            'reviewed_by', 'reviewed_at', 'created_at',
        ]);
        foreach (['status', 'kind', 'user_id'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        $page = $query->orderByDesc('id')->paginate(25);
        $actors = User::whereIn('id', $page->getCollection()->pluck('user_id'))
            ->get(['id', 'name', 'email', 'account_status'])->keyBy('id');

        return response()->json($page->through(function (AccountPrivacyRequest $case) use ($actors) {
            $owner = $actors->get($case->user_id);
            return [
                'id' => $case->id,
                'kind' => $case->kind,
                'status' => $case->status,
                'details' => Crypt::decryptString($case->request_details_encrypted),
                'owner' => $owner ? [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'account_status' => $owner->account_status,
                ] : null,
                'reviewed_by' => $case->reviewed_by,
                'reviewed_at' => $case->reviewed_at,
                'created_at' => $case->created_at,
                'manual_processing_required' => true,
                'automated_erasure_performed' => false,
            ];
        }))->header('Cache-Control', 'no-store, private');
    }

    public function review(Request $request, AccountPrivacyRequest $privacyCase)
    {
        $actor = $this->admin($request);
        $input = $request->validate([
            'expected_status' => 'required|in:pending,reviewing,blocked_external,closed_review',
            'status' => 'required|in:reviewing,blocked_external,closed_review',
            'review_note' => 'required|string|min:10|max:2000',
        ]);
        abort_if($actor->id === $privacyCase->user_id, 403,
            'A separate administrator must review their own request.');

        return DB::transaction(function () use ($actor, $privacyCase, $input) {
            $case = AccountPrivacyRequest::whereKey($privacyCase->id)
                ->lockForUpdate()->firstOrFail();
            abort_if($case->user_id === $actor->id, 403);
            abort_unless($case->status === $input['expected_status'], 409,
                'Request status changed. Refresh before reviewing.');

            $allowed = [
                'pending' => ['reviewing', 'blocked_external'],
                'reviewing' => ['blocked_external', 'closed_review'],
                'blocked_external' => ['reviewing', 'closed_review'],
                'closed_review' => [],
            ];
            abort_unless(in_array($input['status'], $allowed[$case->status], true),
                409, 'Invalid privacy review transition.');
            $case->update([
                'status' => $input['status'],
                'review_note_encrypted' => Crypt::encryptString(trim($input['review_note'])),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id,
                'action' => 'privacy.review.'.$case->status,
                'subject' => (string) $case->id,
                'created_at' => now(),
            ]);

            return response()->json([
                'id' => $case->id,
                'status' => $case->status,
                'manual_processing_required' => true,
                'automated_erasure_performed' => false,
            ])->header('Cache-Control', 'no-store, private');
        });
    }
}
