<?php

namespace App\Http\Controllers;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PrivacyRequestController extends Controller
{
    private function privateResponse($value, int $status = 200)
    {
        return response()->json($value, $status)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    private function audit(int $actor, string $action, int $requestId): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor,
            'action' => $action,
            'subject' => (string) $requestId,
            'created_at' => now(),
        ]);
    }

    public function index(Request $request)
    {
        return $this->privateResponse(PrivacyRequest::where('user_id', $request->user()->id)
            ->orderByDesc('id')->paginate(25));
    }

    public function create(Request $request)
    {
        $v = $request->validate([
            'request_type' => 'required|in:data_access,account_deletion',
            'reason' => 'present|nullable|string|max:500',
            'idempotency_key' => 'required|string|min:12|max:100',
        ]);
        $reason = trim($v['reason'] ?? '');
        $reason = $reason === '' ? null : $reason;
        return DB::transaction(function () use ($request, $v, $reason) {
            // Serializes creates for this account even before the first request row exists.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $prior = PrivacyRequest::where('user_id', $request->user()->id)
                ->where('idempotency_key', $v['idempotency_key'])->first();
            if ($prior) {
                abort_unless($prior->request_type === $v['request_type'] && $prior->reason === $reason,
                    409, 'Idempotency key was used for a different privacy request.');
                return $this->privateResponse($prior);
            }
            $new = PrivacyRequest::create([
                'user_id' => $request->user()->id,
                'request_type' => $v['request_type'],
                'reason' => $reason,
                'idempotency_key' => $v['idempotency_key'],
                'status' => 'submitted',
            ]);
            $this->audit($request->user()->id, 'privacy.request.submitted', $new->id);
            return $this->privateResponse($new, 201);
        });
    }

    public function withdraw(Request $request, PrivacyRequest $privacyRequest)
    {
        return DB::transaction(function () use ($request, $privacyRequest) {
            $row = PrivacyRequest::whereKey($privacyRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->user_id === $request->user()->id, 404);
            if ($row->status === 'withdrawn') {
                return $this->privateResponse($row);
            }
            abort_unless(in_array($row->status, ['submitted', 'in_review'], true),
                409, 'Review has already been closed.');
            $row->status = 'withdrawn';
            $row->withdrawn_at = now();
            $row->save();
            $this->audit($request->user()->id, 'privacy.request.withdrawn', $row->id);
            return $this->privateResponse($row);
        });
    }

    public function adminIndex(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $v = $request->validate(['status' => 'sometimes|in:submitted,in_review,closed,withdrawn']);
        $q = PrivacyRequest::with('user:id,name,email');
        if (isset($v['status'])) {
            $q->where('status', $v['status']);
        }
        return $this->privateResponse($q->orderByDesc('id')->paginate(25));
    }

    public function review(Request $request, PrivacyRequest $privacyRequest)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $v = $request->validate([
            'status' => 'required|in:in_review,closed',
            'resolution' => 'required_if:status,closed|nullable|in:referred,declined,review_documented',
            'decision_note' => 'required_if:status,closed|nullable|string|min:15|max:2000',
        ]);
        if ($v['status'] === 'closed') {
            // The note becomes an auditable record of review, not an automated
            // privacy fulfillment. Whitespace alone must not satisfy closure.
            $note = trim((string) ($v['decision_note'] ?? ''));
            if (mb_strlen($note) < 15) {
                throw ValidationException::withMessages([
                    'decision_note' => 'Provide at least 15 meaningful characters documenting the review.',
                ]);
            }
            $v['decision_note'] = $note;
        }
        return DB::transaction(function () use ($request, $privacyRequest, $v) {
            $row = PrivacyRequest::whereKey($privacyRequest->id)->lockForUpdate()->firstOrFail();
            if ($row->status === $v['status']) {
                // A duplicate review can only replay its original outcome.
                abort_unless($row->status === 'in_review' ||
                    ($row->resolution === ($v['resolution'] ?? null)
                    && $row->decision_note === trim((string) ($v['decision_note'] ?? ''))), 409);
                return $this->privateResponse($row);
            }
            abort_unless(
                ($row->status === 'submitted' && $v['status'] === 'in_review') ||
                ($row->status === 'in_review' && $v['status'] === 'closed'),
                409, 'Invalid privacy request transition.'
            );
            $row->status = $v['status'];
            $row->reviewer_id = $request->user()->id;
            $row->reviewed_at = now();
            if ($row->status === 'closed') {
                $row->resolution = $v['resolution'];
                $row->decision_note = trim($v['decision_note']);
            }
            $row->save();
            $this->audit($request->user()->id, 'privacy.request.reviewed', $row->id);
            // Closing this review never deletes records or represents legal fulfillment.
            return $this->privateResponse($row);
        });
    }
}
