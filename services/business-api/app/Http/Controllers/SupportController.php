<?php

namespace App\Http\Controllers;

use App\Models\InboxNotification;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupportController extends Controller
{
    private function access(Request $r, SupportTicket $t): void
    {
        abort_unless($r->user()->role === 'admin' || $t->user_id === $r->user()->id, 404);
    }

    private function audit(Request $r, string $action, int $id): void
    {
        DB::table('audit_events')->insert(['actor_id' => $r->user()->id, 'action' => $action, 'subject' => (string) $id, 'created_at' => now()]);
    }

    public function index(Request $r)
    {
        return SupportTicket::when($r->user()->role !== 'admin', fn ($q) => $q->where('user_id', $r->user()->id))->orderByDesc('id')->paginate(25);
    }

    public function create(Request $r)
    {
        $v = $r->validate(['subject' => 'required|string|max:150', 'body' => 'required|string|max:5000']);

        return DB::transaction(function () use ($r, $v) {
            $t = SupportTicket::create(['user_id' => $r->user()->id, 'subject' => $v['subject']]);
            TicketMessage::create(['support_ticket_id' => $t->id, 'author_id' => $r->user()->id, 'body' => $v['body'], 'internal' => false]);
            $this->audit($r, 'support.created', $t->id);

            return response()->json($t, 201);
        });
    }

    public function show(Request $r, SupportTicket $ticket)
    {
        $this->access($r, $ticket);

        return response()->json(['ticket' => $ticket, 'messages' => TicketMessage::where('support_ticket_id', $ticket->id)->when($r->user()->role !== 'admin', fn ($q) => $q->where('internal', false))->orderBy('id')->paginate(50)]);
    }

    public function reply(Request $r, SupportTicket $ticket)
    {
        $this->access($r, $ticket);
        $v = $r->validate(['body' => 'required|string|max:5000', 'internal' => 'sometimes|boolean']);
        abort_if(($v['internal'] ?? false) && $r->user()->role !== 'admin', 403);

        return DB::transaction(function () use ($r, $ticket, $v) {
            $t = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_if($t->status === 'closed', 409, 'Closed tickets cannot receive replies.');
            $m = TicketMessage::create(['support_ticket_id' => $t->id, 'author_id' => $r->user()->id, 'body' => $v['body'], 'internal' => $v['internal'] ?? false]);
            if ($r->user()->role !== 'admin' && $t->status === 'resolved') {
                $t->update(['status' => 'open']);
            }
            if ($r->user()->id !== $t->user_id && ! $m->internal) {
                InboxNotification::create(['user_id' => $t->user_id, 'kind' => 'support.reply', 'support_ticket_id' => $t->id]);
            }
            $this->audit($r, 'support.replied', $t->id);

            return response()->json($m, 201);
        });
    }

    public function transition(Request $r, SupportTicket $ticket)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $v = $r->validate(['status' => 'required|in:open,in_progress,resolved,closed', 'reason' => 'required|string|max:1000']);

        return DB::transaction(function () use ($r, $ticket, $v) {
            $t = SupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $allowed = ['open' => ['in_progress', 'resolved'], 'in_progress' => ['open', 'resolved'], 'resolved' => ['open', 'closed'], 'closed' => ['open']];
            abort_unless(in_array($v['status'], $allowed[$t->status]), 409, 'Invalid support status transition.');
            $t->update(['status' => $v['status']]);
            TicketMessage::create(['support_ticket_id' => $t->id, 'author_id' => $r->user()->id, 'body' => $v['reason'], 'internal' => false]);
            InboxNotification::create(['user_id' => $t->user_id, 'kind' => 'support.status.'.$v['status'], 'support_ticket_id' => $t->id]);
            $this->audit($r, 'support.status.'.$v['status'], $t->id);

            return $t;
        });
    }

    public function notifications(Request $r)
    {
        return InboxNotification::where('user_id', $r->user()->id)->orderByDesc('id')->paginate(25);
    }

    public function read(Request $r, InboxNotification $notification)
    {
        abort_unless($notification->user_id === $r->user()->id, 404);
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }
}
