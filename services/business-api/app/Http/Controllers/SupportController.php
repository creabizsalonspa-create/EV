<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    /** Role revocation must invalidate a cached authenticated Admin model. */
    private function currentRole(Request $r, bool $forUpdate = false): ?string
    {
        $actor = DB::table('users')->where('id', $r->user()->id);
        if ($forUpdate) {
            $actor->lockForUpdate();
        }

        return $actor->value('role');
    }

    private function access(Request $r, SupportTicket $t): void
    {
        abort_unless($this->currentRole($r) === 'admin' || $t->user_id === $r->user()->id, 404);
    }

    private function audit(Request $r, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function index(Request $r)
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return SupportTicket::when($this->currentRole($r) !== 'admin', fn ($q) => $q->where('user_id', $r->user()->id))->orderByDesc('id')->paginate(25);
    }

    public function create(Request $r)
    {
        $v = $r->validate([
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
            'idempotency_key' => ['sometimes', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ]);

        return DB::transaction(function () use ($r, $v) {
            $ownerId = $r->user()->id;
            if (isset($v['idempotency_key'])) {
                // A user-row lock serializes same-account ticket creations.
                // Unique (user, request key) is enforced in PostgreSQL/SQLite.
                abort_unless(DB::table('users')->where('id', $ownerId)
                    ->lockForUpdate()->first(), 409);
                $previous = SupportTicket::where('user_id', $ownerId)
                    ->where('idempotency_key', $v['idempotency_key'])->first();
                if ($previous) {
                    $initial = TicketMessage::where('support_ticket_id', $previous->id)
                        ->orderBy('id')->first();
                    abort_unless(
                        hash_equals((string) $previous->subject, $v['subject'])
                            && $initial
                            && (int) $initial->author_id === $ownerId
                            && hash_equals((string) $initial->body, $v['body']),
                        409,
                        'Request key was already used for a different support ticket.'
                    );

                    return response()->json($previous);
                }
            }
            $t = SupportTicket::create([
                'user_id' => $ownerId,
                'subject' => $v['subject'],
                'idempotency_key' => $v['idempotency_key'] ?? null,
            ]);
            TicketMessage::create(['support_ticket_id' => $t->id, 'author_id' => $ownerId,
                'body' => $v['body'], 'internal' => false]);
            $this->audit($r, 'support.created', $t->id);

            return response()->json($t, 201);
        }, 3);
    }

    public function show(Request $r, SupportTicket $ticket)
    {
        $this->access($r, $ticket);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return response()->json(['ticket' => $ticket, 'messages' => TicketMessage::where('support_ticket_id', $ticket->id)->when($this->currentRole($r) !== 'admin', fn ($q) => $q->where('internal', false))->orderBy('id')->paginate(50)]);
    }

    public function reply(Request $r, SupportTicket $ticket)
    {
        $this->access($r, $ticket);
        $v = $r->validate([
            'body' => 'required|string|max:5000',
            'internal' => 'sometimes|boolean',
            // Optional for older clients; new clients retry an uncertain
            // reply with the same cryptographically random request key.
            'idempotency_key' => ['sometimes', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ]);
        abort_if(($v['internal'] ?? false) && $this->currentRole($r) !== 'admin', 403);

        return DB::transaction(function () use ($r, $ticket, $v) {
            // Lock the account before the ticket, serializing authorization
            // with role revocation even when the auth model is stale.
            $role = $this->currentRole($r, true);
            abort_unless($role !== null, 403);
            $t = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_unless($role === 'admin' || $t->user_id === $r->user()->id, 404);
            abort_if(($v['internal'] ?? false) && $role !== 'admin', 403);
            // Ticket lock serializes concurrent same-key requests on
            // PostgreSQL. The composite unique index is a second safeguard.
            // Existing clients without a key preserve their old behavior.
            if (isset($v['idempotency_key'])) {
                $previous = TicketMessage::where('support_ticket_id', $t->id)
                    ->where('author_id', $r->user()->id)
                    ->where('idempotency_key', $v['idempotency_key'])->first();
                if ($previous) {
                    abort_unless(
                        hash_equals((string) $previous->body, $v['body'])
                            && (bool) $previous->internal === (bool) ($v['internal'] ?? false)
                            && $previous->transition_status === null,
                        409,
                        'Request key was already used with different reply content.'
                    );

                    // A replay after resolution/closure is still the original
                    // committed reply, not a new mutation or notification.
                    return response()->json($previous);
                }
            }
            abort_if($t->status === 'closed', 409, 'Closed tickets cannot receive replies.');
            $m = TicketMessage::create([
                'support_ticket_id' => $t->id,
                'author_id' => $r->user()->id,
                'body' => $v['body'],
                'internal' => $v['internal'] ?? false,
                'idempotency_key' => $v['idempotency_key'] ?? null,
            ]);
            if ($role !== 'admin' && $t->status === 'resolved') {
                $t->update(['status' => 'open']);
            }
            if ($r->user()->id !== $t->user_id && (bool) $m->internal === false) {
                InboxNotification::create(['user_id' => $t->user_id, 'kind' => 'support.reply', 'support_ticket_id' => $t->id]);
            }
            $this->audit($r, 'support.replied', $t->id);

            return response()->json($m, 201);
        });
    }

    public function transition(Request $r, SupportTicket $ticket)
    {
        abort_unless($this->currentRole($r) === 'admin', 403);
        $v = $r->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
            'reason' => 'required|string|max:1000',
            'idempotency_key' => ['sometimes', 'string', 'regex:/^[a-f0-9]{32}$/'],
        ]);

        return DB::transaction(function () use ($r, $ticket, $v) {
            abort_unless($this->currentRole($r, true) === 'admin', 403);
            $t = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (isset($v['idempotency_key'])) {
                $old = TicketMessage::where('support_ticket_id', $t->id)
                    ->where('author_id', $r->user()->id)
                    ->where('idempotency_key', $v['idempotency_key'])->first();
                if ($old) {
                    abort_unless($old->transition_status === $v['status']
                        && hash_equals((string) $old->body, $v['reason'])
                        && $t->status === $v['status'], 409,
                        'Request key is already used or the ticket state changed.');

                    return $t;
                }
            }
            $allowed = ['open' => ['in_progress', 'resolved'], 'in_progress' => ['open', 'resolved'], 'resolved' => ['open', 'closed'], 'closed' => ['open']];
            abort_unless(in_array($v['status'], $allowed[$t->status]), 409, 'Invalid support status transition.');
            $t->update(['status' => $v['status']]);
            TicketMessage::create([
                'support_ticket_id' => $t->id,
                'author_id' => $r->user()->id,
                'body' => $v['reason'],
                'internal' => false,
                'transition_status' => $v['status'],
                'idempotency_key' => $v['idempotency_key'] ?? null,
            ]);
            InboxNotification::create(['user_id' => $t->user_id, 'kind' => 'support.status.'.$v['status'], 'support_ticket_id' => $t->id]);
            $this->audit($r, 'support.status.'.$v['status'], $t->id);

            return $t;
        }, 3);
    }

    public function notifications(Request $r)
    {
        $filters = $r->validate([
            'unread_only' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1|max:100000',
            'category' => 'sometimes|in:support,vehicle,charging,fleet,other',
        ]);

        $page = InboxNotification::visibleTo($r->user())
            ->when($r->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->when(isset($filters['category']), function ($q) use ($filters) {
                if ($filters['category'] === 'other') {
                    // This is exactly the complement of the four fixed
                    // category prefixes used by notification summary counts.
                    foreach (['support', 'vehicle', 'charging', 'fleet'] as $category) {
                        $q->where('kind', 'not like', $category.'.%');
                    }
                } else {
                    $q->where('kind', 'like', $filters['category'].'.%');
                }
            })
            ->orderByDesc('id')->paginate(25);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $r, InboxNotification $notification)
    {
        abort_unless(InboxNotification::visibleTo($r->user())
            ->whereKey($notification->id)->exists(), 404);

        return DB::transaction(function () use ($r, $notification) {
            $changed = InboxNotification::visibleTo($r->user())
                ->whereKey($notification->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            if ($changed > 0) {
                DB::table('audit_events')->insert([
                    'actor_id' => $r->user()->id,
                    'action' => 'notifications.read_one',
                    'subject' => (string) $notification->id,
                    'created_at' => now(),
                ]);
            }

            return response()->json(InboxNotification::visibleTo($r->user())
                ->whereKey($notification->id)->firstOrFail())
                ->header('Cache-Control', 'private, no-store');
        });
    }
}
