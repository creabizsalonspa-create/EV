<?php

namespace App\Http\Controllers;

use App\Services\Notifications\EmailNotices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationEmailController extends Controller
{
    public function show(Request $r, EmailNotices $service)
    {
        return DB::transaction(fn () => response()->json($service->status($service->actor($r->user())))->header('Cache-Control', 'private, no-store'));
    }

    public function update(Request $r, EmailNotices $service)
    {
        $v = $r->validate(['enabled' => 'required|boolean', 'confirmed' => 'sometimes|boolean', 'expected_revision' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,18})$/D'],
            'email' => 'prohibited', 'to' => 'prohibited', 'user_id' => 'prohibited']);
        abort_if($r->boolean('enabled') && ! $r->boolean('confirmed'), 422, 'Explicit email notice consent is required.');
        abort_unless((string) (int) $v['expected_revision'] === $v['expected_revision'], 422);

        return response()->json($service->preference($r->user(), $r->boolean('enabled'), (int) $v['expected_revision']))->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $r, EmailNotices $service)
    {
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);

        return DB::transaction(function () use ($r, $service) {
            $user = $service->actor($r->user());
            $page = DB::table('notification_email_outbox')->where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25, ['id', 'state', 'failure_code', 'evidence', 'created_at', 'claimed_at', 'completed_at']);

            return response()->json(['receipts' => $page->toArray(), 'delivery_confirmed' => false, 'automatic_retry' => false])->header('Cache-Control', 'private, no-store');
        });
    }
}
