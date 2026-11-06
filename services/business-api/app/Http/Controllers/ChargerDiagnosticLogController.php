<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\Charging\ConfigurationProbe;
use App\Services\Charging\DiagnosticLogSource;
use App\Services\Charging\FaultMonitor;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChargerDiagnosticLogController extends Controller
{
    private function scope(Request $r, VendorCompany $company, VendorCharger $charger, bool $write = false): object
    {
        $actor = User::findOrFail($r->user()->id);
        $r->setUserResolver(fn () => $actor);

        return app(ChargerResetController::class)->scope($r, $company->fresh(), $charger->fresh(), $write);
    }

    private function record(object $b, int $id): object
    {
        $row = DB::table('charger_diagnostic_logs')->where('id', $id)->where('binding_id', $b->id)->first();
        abort_unless($row && hash_equals($row->source_generation, FaultMonitor::generation($b)), 404);

        return $row;
    }

    private function repair(object $b, int $id): void
    {
        abort_unless(DB::table('charging_repair_orders as w')->join('charging_fault_alerts as a', 'a.id', '=', 'w.alert_id')->join('charging_status_observations as o', 'o.id', '=', 'a.observation_id')->where('w.id', $id)->where('o.source_generation', FaultMonitor::generation($b))->exists(), 404);
    }

    private function present(object $row, bool $private): array
    {
        return ['id' => $row->id, 'repair_order_id' => $row->repair_order_id, 'protocol' => $row->protocol, 'dispatch_state' => $row->dispatch_state, 'created_at' => $row->created_at, 'expires_at' => $row->expires_at, 'expired' => now()->gte(CarbonImmutable::parse($row->expires_at)), 'cancelled_at' => $row->cancelled_at, 'device_status' => $row->device_status, 'accepted_at' => $row->accepted_at, 'source_status_at' => $row->source_status_at, 'file_received_at' => $row->file_received_at, 'file_expires_at' => $row->file_expires_at, 'file_size' => $row->file_size, 'file_sha256' => $row->file_sha256, 'content_removed_at' => $row->content_removed_at, 'can_download' => $private && $row->accepted_at !== null && $row->source_request_id !== null && $row->source_reply_id !== null && $row->source_reply_digest !== null && $row->cancelled_at === null && $row->file_ciphertext !== null && now()->lt(CarbonImmutable::parse($row->file_expires_at)), 'evidence' => 'SIMULATED_SOURCE', 'physical_device_verified' => false];
    }

    private function json(mixed $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function privileged(Request $r, VendorCompany $company): bool
    {
        return $r->user()->role === 'admin' || $r->user()->id === $company->owner_id;
    }

    public function index(Request $r, VendorCompany $company, VendorCharger $charger)
    {
        $filter = $r->validate(['page' => 'sometimes|integer|min:1|max:100000', 'repair_order_id' => 'sometimes|integer|min:1']);
        $b = $this->scope($r, $company, $charger);
        $private = $this->privileged($r, $company);
        if (isset($filter['repair_order_id'])) {
            $this->repair($b, $filter['repair_order_id']);
        }
        $page = DB::table('charger_diagnostic_logs')->where('binding_id', $b->id)->where('source_generation', FaultMonitor::generation($b))->when(isset($filter['repair_order_id']), fn ($q) => $q->where('repair_order_id', $filter['repair_order_id']))->orderByDesc('id')->paginate(25)->through(fn ($row) => $this->present($row, $private))->toArray();
        $enabled = false;
        try {
            app(DiagnosticLogSource::class)->enabled();
            app(VendorAccess::class)->operational($company->fresh());
            $enabled = true;
        } catch (Throwable) {
        }

        return $this->json($page + ['can_request' => $private && $enabled, 'can_manage' => $private, 'maximum_file_bytes' => DiagnosticLogSource::LIMIT, 'upload_lifetime_seconds' => 900, 'file_retention_seconds' => 86400, 'evidence' => 'SIMULATED_SOURCE', 'charging_capacity_changed' => false]);
    }

    public function create(Request $r, VendorCompany $company, VendorCharger $charger, DiagnosticLogSource $source, ConfigurationProbe $probe)
    {
        $source->enabled();
        $b = $this->scope($r, $company, $charger, true);
        $v = $r->validate(['request_key' => 'required|uuid', 'reason' => 'required|string|min:10|max:500', 'repair_order_id' => 'sometimes|nullable|integer|min:1']);
        $hash = hash('sha256', json_encode([FaultMonitor::generation($b), $v['reason'], $v['repair_order_id'] ?? null], JSON_THROW_ON_ERROR));
        $op = DB::transaction(function () use ($r, $company, $charger, $b, $v, $hash, $source) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $current = $this->scope($r, $company, $charger, true);
            $locked = DB::table('csms_bindings')->where('id', $current->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(FaultMonitor::generation($b), FaultMonitor::generation($locked)), 409);
            app(VendorAccess::class)->operational($company->fresh());
            $old = DB::table('charger_diagnostic_logs')->where('actor_id', $r->user()->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->intent_hash, $hash), 409);

                return ['row' => $old, 'created' => false];
            }
            abort_if(DB::table('charger_diagnostic_logs')->where('binding_id', $b->id)->whereNull('cancelled_at')->where('expires_at', '>', now())->whereNull('source_status_at')->whereNotIn('dispatch_state', ['not_dispatched', 'device_rejected'])->exists(), 409, 'One diagnostic request may remain unresolved per device.');
            abort_if(DB::table('charger_diagnostic_logs')->where('binding_id', $b->id)->where('created_at', '>=', now()->subDay())->count() >= 20, 429, 'Daily diagnostic request budget reached.');
            if (isset($v['repair_order_id'])) {
                $this->repair($locked, $v['repair_order_id']);
            }
            $cap = bin2hex(random_bytes(32));
            $id = DB::table('charger_diagnostic_logs')->insertGetId(['binding_id' => $b->id, 'actor_id' => $r->user()->id, 'repair_order_id' => $v['repair_order_id'] ?? null, 'request_key' => $v['request_key'], 'intent_hash' => $hash, 'source_generation' => FaultMonitor::generation($b), 'protocol' => $b->protocol, 'reason_ciphertext' => Crypt::encryptString($v['reason']), 'upload_token_digest' => hash('sha256', $cap), 'dispatch_state' => 'requested', 'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);
            abort_if($id > 2147483647, 409);
            DB::table('charger_diagnostic_logs')->where('id', $id)->update(['payload_ciphertext' => Crypt::encryptString(json_encode($source->payload($b->protocol, $id, $cap), JSON_THROW_ON_ERROR))]);
            app(VendorAccess::class)->audit($r->user(), 'diagnostic_log.requested', $id);

            return ['row' => DB::table('charger_diagnostic_logs')->find($id), 'created' => true];
        }, 3);
        $row = $op['row'];
        if ($op['created'] && DB::table('charger_diagnostic_logs')->where('id', $row->id)->where('dispatch_state', 'requested')->update(['dispatch_state' => 'dispatching', 'attempted_at' => now()->utc()->format('Y-m-d H:i:s.u'), 'updated_at' => now()])) {
            $sent = false;
            $state = 'not_dispatched';
            try {
                $probe->station($b);
                $token = $probe->serviceToken();
                $current = $this->scope($r, $company, $charger, true);
                app(VendorAccess::class)->operational($company->fresh());
                $source->enabled();
                abort_unless(hash_equals(FaultMonitor::generation($b), FaultMonitor::generation($current)), 409);
                $fresh = $this->record($current, $row->id);
                abort_unless($fresh->cancelled_at === null && now()->lt(CarbonImmutable::parse($fresh->expires_at)), 409);
                $payload = json_decode(Crypt::decryptString($fresh->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
                $sent = true;
                $state = $source->submit($current, $token, $payload) ? 'transport_submitted' : 'outcome_unknown';
            } catch (Throwable) {
                $state = $sent ? 'outcome_unknown' : 'not_dispatched';
            }
            DB::table('charger_diagnostic_logs')->where('id', $row->id)->where('dispatch_state', 'dispatching')->update(['dispatch_state' => $state, 'updated_at' => now()]);
        }
        $this->scope($r, $company, $charger, true);

        return $this->json($this->present($this->record($b, $row->id), true), $op['created'] ? 201 : 200);
    }

    public function upload(Request $r, int $operation, DiagnosticLogSource $source)
    {
        $source->enabled();
        $token = $r->getPassword();
        abort_unless($r->getUser() === 'diagnostic' && is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        abort_unless(in_array(strtolower(explode(';', (string) $r->header('Content-Type'))[0]), ['application/octet-stream', 'text/plain'], true), 415);
        $size = $r->header('Content-Length');
        abort_unless(is_string($size) && ctype_digit($size) && (int) $size >= 1 && (int) $size <= DiagnosticLogSource::LIMIT, 413);
        $row = DB::table('charger_diagnostic_logs')->where('id', $operation)->where('upload_token_digest', hash('sha256', $token))->whereNull('cancelled_at')->where('expires_at', '>', now())->whereNotIn('dispatch_state', ['not_dispatched', 'requested', 'device_rejected'])->first();
        abort_unless($row, 404);
        $input = $r->getContent(true);
        $bytes = stream_get_contents($input, DiagnosticLogSource::LIMIT + 1);
        abort_unless(is_string($bytes) && strlen($bytes) === (int) $size && strlen($bytes) <= DiagnosticLogSource::LIMIT, 413);

        return DB::transaction(function () use ($r, $row, $bytes) {
            $charger = VendorCharger::whereKey(DB::table('csms_bindings')->where('id', $row->binding_id)->value('vendor_charger_id'))->firstOrFail();
            $company = VendorCompany::whereKey($charger->company_id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($row->actor_id)->lockForUpdate()->firstOrFail();
            $r->setUserResolver(fn () => $actor);
            $b = $this->scope($r, $company, $charger, true);
            $lockedBinding = DB::table('csms_bindings')->where('id', $b->id)->lockForUpdate()->firstOrFail();
            $op = DB::table('charger_diagnostic_logs')->where('id', $row->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($op->source_generation, FaultMonitor::generation($lockedBinding)) && $op->cancelled_at === null && now()->lt(CarbonImmutable::parse($op->expires_at)) && $op->upload_token_digest === $row->upload_token_digest && ! in_array($op->dispatch_state, ['not_dispatched', 'requested', 'device_rejected'], true), 404);
            $hash = hash('sha256', $bytes);
            if ($op->file_sha256 !== null) {
                abort_unless(hash_equals($op->file_sha256, $hash) && $op->file_size === strlen($bytes), 409);

                return $this->json(['received' => true, 'replayed' => true]);
            }
            DB::table('charger_diagnostic_logs')->where('id', $op->id)->update(['file_sha256' => $hash, 'file_size' => strlen($bytes), 'file_ciphertext' => Crypt::encryptString($bytes), 'file_received_at' => now(), 'file_expires_at' => now()->addDay(), 'updated_at' => now()]);

            return $this->json(['received' => true, 'replayed' => false], 201);
        }, 3);
    }

    public function cancel(Request $r, VendorCompany $company, VendorCharger $charger, int $operation)
    {
        $b = $this->scope($r, $company, $charger, true);
        $op = $this->record($b, $operation);

        return DB::transaction(function () use ($r, $company, $charger, $op) {
            VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $b = $this->scope($r, $company, $charger, true);
            $locked = DB::table('csms_bindings')->where('id', $b->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($op->source_generation, FaultMonitor::generation($locked)), 409);
            $op = DB::table('charger_diagnostic_logs')->where('id', $op->id)->lockForUpdate()->firstOrFail();
            if ($op->cancelled_at !== null) {
                return $this->json(['cancelled' => true, 'source_command_sent' => false, 'charging_capacity_changed' => false]);
            }
            DB::table('charger_diagnostic_logs')->where('id', $op->id)->update(['cancelled_at' => $op->cancelled_at ?? now(), 'upload_token_digest' => null, 'payload_ciphertext' => null, 'file_ciphertext' => null, 'content_removed_at' => now(), 'updated_at' => now()]);
            app(VendorAccess::class)->audit($r->user(), 'diagnostic_log.cancelled', $op->id);

            return $this->json(['cancelled' => true, 'source_command_sent' => false, 'charging_capacity_changed' => false]);
        }, 3);
    }

    public function download(Request $r, VendorCompany $company, VendorCharger $charger, int $operation)
    {
        $b = $this->scope($r, $company, $charger, true);
        $op = $this->record($b, $operation);
        abort_unless($this->present($op, true)['can_download'], 404);
        $bytes = Crypt::decryptString($op->file_ciphertext);
        abort_unless(strlen($bytes) === $op->file_size && hash_equals($op->file_sha256, hash('sha256', $bytes)), 409);
        $current = $this->scope($r, $company, $charger, true);
        $latest = $this->record($current, $op->id);
        abort_unless($this->present($latest, true)['can_download'] && hash_equals($latest->file_sha256, $op->file_sha256), 404);

        return response($bytes)->header('Content-Type', 'application/octet-stream')->header('Content-Disposition', 'attachment; filename="charger-log-'.$op->id.'.bin"')->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', 'sandbox; default-src \'none\'');
    }

    public function observe(Request $r, VendorCompany $company, VendorCharger $charger, int $operation, ConfigurationProbe $probe, DiagnosticLogSource $source)
    {
        $source->enabled();
        $b = $this->scope($r, $company, $charger);
        $op = $this->record($b, $operation);
        if (! $op->attempted_at || ! $op->payload_ciphertext || $op->cancelled_at !== null || $op->dispatch_state === 'not_dispatched') {
            return $this->json($this->present($op, $this->privileged($r, $company)));
        }
        $station = $probe->station($b, false);
        $action = $b->protocol === '1.6' ? 'GetDiagnostics' : 'GetLog';
        $payload = json_decode(Crypt::decryptString($op->payload_ciphertext), true, flags: JSON_THROW_ON_ERROR);
        $requests = $probe->graph('query($station:Int!,$action:String!,$after:timestamptz!,$until:timestamptz!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"csms"},type:{_eq:2},action:{_eq:$action},createdAt:{_gte:$after,_lte:$until},payload:{_eq:$match}},limit:2){id createdAt}}', ['station' => $station, 'action' => $action, 'after' => CarbonImmutable::parse($op->attempted_at)->toISOString(), 'until' => CarbonImmutable::parse($op->attempted_at)->addSeconds(30)->toISOString(), 'match' => $payload])['OCPPMessages'];
        $updates = [];
        if (count($requests) === 1) {
            $request = $requests[0];
            $replies = $probe->graph('query($station:Int!,$request:Int!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},requestMessageId:{_eq:$request},type:{_in:[3,4]}},limit:2){id type payload createdAt}}', ['station' => $station, 'request' => $request['id']])['OCPPMessages'];
            if (count($replies) === 1) {
                $reply = $replies[0];
                $at = CarbonImmutable::parse($reply['createdAt'])->utc();
                abort_unless($at->gte(CarbonImmutable::parse($request['createdAt'])) && $at->lte(CarbonImmutable::parse($op->expires_at)), 409);
                $digest = hash('sha256', json_encode($reply['payload'], JSON_THROW_ON_ERROR));
                abort_if($op->source_reply_id !== null && ($op->source_reply_id !== $reply['id'] || ! $op->source_reply_digest || ! hash_equals($op->source_reply_digest, $digest)), 409, 'Changed diagnostic source reply requires review.');
                $body = $reply['payload'];
                $accepted = $reply['type'] === 3 && is_array($body) && ($b->protocol === '1.6' ? (is_string($body['fileName'] ?? null) && strlen($body['fileName']) >= 1 && strlen($body['fileName']) <= 255) : ($body['status'] ?? null) === 'Accepted');
                if (! $op->source_reply_id) {
                    $updates = ['source_request_id' => $request['id'], 'source_reply_id' => $reply['id'], 'source_reply_digest' => $digest, 'accepted_at' => $accepted ? $at->format('Y-m-d H:i:s.u') : null, 'dispatch_state' => $accepted ? $op->dispatch_state : 'device_rejected'];
                }
                if ($accepted) {
                    unset($updates['dispatch_state']);
                }
                if ($accepted && ! $op->source_status_at) {
                    $name = $b->protocol === '1.6' ? 'DiagnosticsStatusNotification' : 'LogStatusNotification';
                    $match = $b->protocol === '1.6' ? new \stdClass : ['requestId' => (int) $op->id];
                    $events = $probe->graph('query($station:Int!,$action:String!,$after:timestamptz!,$until:timestamptz!,$match:jsonb!){OCPPMessages(where:{tenantId:{_eq:1},stationId:{_eq:$station},origin:{_eq:"cs"},type:{_eq:2},action:{_eq:$action},createdAt:{_gte:$after,_lte:$until},payload:{_contains:$match}},order_by:{id:asc},limit:51){id payload createdAt}}', ['station' => $station, 'action' => $name, 'after' => $reply['createdAt'], 'until' => CarbonImmutable::parse($op->expires_at)->toISOString(), 'match' => $match])['OCPPMessages'];
                    abort_if(count($events) > 50, 409, 'Diagnostic source history requires review.');
                    foreach ($events as $event) {
                        $state = $event['payload']['status'] ?? null;
                        if (! in_array($state, ['Uploading', 'Uploaded', 'UploadFailed', 'UploadFailure', 'BadMessage', 'NotSupportedOperation', 'PermissionDenied'], true)) {
                            continue;
                        }
                        // OCPP1.6 does not include a requestId in this notification.
                        // Retain that limitation instead of attributing a late unrelated upload.
                        if ($b->protocol === '1.6') {
                            $updates['device_status'] = 'legacy_status_unattributed';

                            continue;
                        }
                        $updates['device_status'] = $state;
                        if ($state !== 'Uploading') {
                            $updates['source_status_id'] = $event['id'];
                            $updates['source_status_digest'] = hash('sha256', json_encode($event['payload'], JSON_THROW_ON_ERROR));
                            $updates['source_status_at'] = CarbonImmutable::parse($event['createdAt'])->utc()->format('Y-m-d H:i:s.u');
                            break;
                        }
                    }
                }
            }
        }
        DB::transaction(function () use ($r, $company, $charger, $op, $updates) {
            $current = $this->scope($r, $company, $charger);
            $locked = DB::table('csms_bindings')->where('id', $current->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($op->source_generation, FaultMonitor::generation($locked)), 409);
            $row = DB::table('charger_diagnostic_logs')->where('id', $op->id)->lockForUpdate()->firstOrFail();
            if ($row->cancelled_at === null && $updates) {
                if (isset($updates['source_reply_digest']) && $row->source_reply_id !== null) {
                    abort_unless($row->source_request_id === $updates['source_request_id'] && $row->source_reply_id === $updates['source_reply_id'] && $row->source_reply_digest && hash_equals($row->source_reply_digest, $updates['source_reply_digest']), 409);
                    foreach (['source_request_id', 'source_reply_id', 'source_reply_digest', 'accepted_at', 'dispatch_state'] as $field) {
                        unset($updates[$field]);
                    }
                }
                if ($row->source_status_at !== null) {
                    if (isset($updates['source_status_digest'])) {
                        abort_unless($row->source_status_id === $updates['source_status_id'] && $row->source_status_digest && hash_equals($row->source_status_digest, $updates['source_status_digest']), 409);
                    }
                    foreach (['device_status', 'source_status_at', 'source_status_id', 'source_status_digest'] as $field) {
                        unset($updates[$field]);
                    }
                }
                DB::table('charger_diagnostic_logs')->where('id', $row->id)->update($updates + ['updated_at' => now()]);
            }
        }, 3);
        $this->scope($r, $company, $charger);

        return $this->json($this->present($this->record($b, $op->id), $this->privileged($r, $company)));
    }
}
