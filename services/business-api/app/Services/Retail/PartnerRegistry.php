<?php

namespace App\Services\Retail;

use App\Models\User;
use App\Security\AdminTransactionAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Internal governance only: no rewards, stored money, partner activation or payments. */
final class PartnerRegistry
{
    public function mutate(User $snapshot, ?int $partnerId, string $action, array $input): array
    {
        return DB::transaction(function () use ($snapshot, $partnerId, $action, $input) {
            $actor = AdminTransactionAuthority::requireCurrent($snapshot);
            $key = hash('sha256', $actor->id.'|'.$input['idempotency_key']);
            $fingerprint = hash('sha256', json_encode([$partnerId, $action, $input], JSON_THROW_ON_ERROR));
            $prior = DB::table('retail_partner_events')->where('request_key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->fingerprint, $fingerprint), 409, 'Request identity was already used with different terms.');

                return $this->read((int) $prior->partner_id);
            }
            if ($partnerId === null) {
                abort_unless($action === 'create', 422);
                // A shared persisted row serializes first-time reference allocation.
                // No absent-row lock or unique-violation-to-500 race on PostgreSQL.
                DB::table('regions')->orderBy('country')->lockForUpdate()->firstOrFail();
                abort_if(DB::table('retail_partners')->where('reference', $input['reference'])->exists(), 409, 'Reference already registered.');
                $partnerId = DB::table('retail_partners')->insertGetId([
                    'reference' => $input['reference'], 'created_by' => $actor->id, 'created_at' => now(),
                ]);
                $current = null;
                $versionNumber = 1;
                $revision = 1;
            } else {
                DB::table('retail_partners')->where('id', $partnerId)->lockForUpdate()->firstOrFail();
                $current = $this->read($partnerId);
                abort_unless($current['revision'] === (int) $input['expected_revision'], 409, 'Partner changed. Refresh before deciding.');
                abort_if($current['status'] === 'terminated', 409, 'Terminated partners cannot be changed.');
                $versionNumber = (int) $current['version']->version + 1;
                $revision = $current['revision'] + 1;
            }
            if (in_array($action, ['create', 'revise'], true)) {
                abort_unless($action === 'create' || in_array($current['status'], ['draft', 'suspended'], true), 409, 'Suspend the partner before revising terms.');
                $version = DB::table('retail_partner_versions')->insertGetId([
                    'partner_id' => $partnerId, 'version' => $versionNumber,
                    ...array_intersect_key($input, array_flip(['legal_name', 'display_name', 'display_name_ar', 'country', 'category', 'website', 'terms'])),
                    'effective_from' => CarbonImmutable::parse($input['effective_from'])->utc(),
                    'expires_at' => CarbonImmutable::parse($input['expires_at'])->utc(),
                    'created_by' => $actor->id, 'created_at' => now(),
                ]);
            } else {
                $version = $current['version']->id;
                $allowed = ['approve' => ['draft'], 'suspend' => ['active'], 'reinstate' => ['suspended'], 'terminate' => ['draft', 'active', 'suspended']];
                abort_unless(in_array($current['status'], $allowed[$action] ?? [], true), 409, 'Action is not permitted in the current state.');
                if (in_array($action, ['approve', 'reinstate'], true)) {
                    abort_unless((int) $current['version']->created_by !== $actor->id, 409, 'Another administrator must review these terms.');
                    abort_unless(CarbonImmutable::parse($current['version']->expires_at)->isFuture(), 409, 'Partner terms have expired.');
                    abort_unless(DB::table('regions')->where('country', $current['version']->country)->exists(), 409, 'Country configuration is unavailable.');
                }
            }
            DB::table('retail_partner_events')->insert([
                'partner_id' => $partnerId, 'version_id' => $version, 'action' => $action,
                'reason' => $input['reason'], 'actor_id' => $actor->id, 'revision' => $revision,
                'request_key' => $key, 'fingerprint' => $fingerprint, 'created_at' => now(),
            ]);
            DB::table('audit_events')->insert([
                'actor_id' => $actor->id, 'action' => 'retail_partner.'.$action,
                'subject' => (string) $partnerId, 'created_at' => now(),
            ]);

            return $this->read($partnerId);
        }, 3);
    }

    public function read(int $partnerId): array
    {
        $partner = DB::table('retail_partners')->where('id', $partnerId)->firstOrFail();
        $event = DB::table('retail_partner_events')->where('partner_id', $partnerId)->orderByDesc('revision')->firstOrFail();
        $version = DB::table('retail_partner_versions')->where('id', $event->version_id)->firstOrFail();
        $status = match ($event->action) {
            'create', 'revise' => 'draft', 'approve', 'reinstate' => 'active',
            'suspend' => 'suspended', 'terminate' => 'terminated',
        };
        $effective = $status === 'active' && CarbonImmutable::parse($version->effective_from)->lte(now()) && CarbonImmutable::parse($version->expires_at)->gt(now());

        return ['id' => (int) $partner->id, 'reference' => $partner->reference,
            'status' => $status, 'revision' => (int) $event->revision, 'version' => $version,
            'currently_effective' => $effective, 'external_fulfillment_verified' => false,
            'rewards_enabled' => false, 'money_movement_enabled' => false];
    }
}
