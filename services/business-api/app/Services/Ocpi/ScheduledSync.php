<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\User;
use App\Services\Charging\MaintenanceActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Opt-in bounded GET polling only; canonical ingress owns every accepted record. */
final class ScheduledSync
{
    public const INTERVALS = [15, 30, 60, 360, 1440];

    private function users(int $actor, int $owner): void
    {
        User::whereIn('id', array_values(array_unique([$actor, $owner])))->orderBy('id')->lockForUpdate()->get();
    }

    public function scope(User $snapshot, OcpiProvider $provider): OcpiProvider
    {
        $this->users($snapshot->id, $provider->owner_id);
        app(MaintenanceActor::class)->check($snapshot);
        $current = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->owner_id === $provider->owner_id, 409);
        abort_unless($snapshot->role === 'admin' || ($snapshot->role === 'vendor' && $current->owner_id === $snapshot->id), 404);

        return $current;
    }

    private function fingerprint(OcpiProvider $provider, User $actor, string $module, int $interval): string
    {
        abort_unless($provider->authorized && $provider->version === '2.2.1'
            && User::whereKey($provider->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(), 409);
        abort_unless(in_array($module, PartnerClient::MODULES, true) && in_array($interval, self::INTERVALS, true)
            && is_string($provider->endpoints[$module] ?? null), 409);
        $client = app(PartnerClient::class);
        $configuration = $client->connection($provider);
        $client->guardedUrl($provider, $provider->endpoints[$module]);
        $link = ($configuration['managed_credentials'] ?? false)
            ? DB::table('ocpi_credential_links')->where('provider_id', $provider->id)->first()
            : null;

        // A legacy consent digest cannot be upgraded silently. Operators must
        // explicitly renew plans after this exact-source consent format changes.
        // Persist only the combined digest, never credential or password bytes.
        return hash('sha256', json_encode(['poll-consent-v2', $provider->id, $provider->connection_key,
            $provider->authorization_revision, $provider->owner_id, $provider->version, $provider->endpoints,
            $module, $interval, $actor->id, $actor->role, $actor->auth_epoch, $actor->password, $configuration,
            $link ? [$link->id, $link->owner_id, $link->generation, $link->state, $link->configuration_hash,
                $link->bootstrap_hash, $link->local_token_digest, $link->local_token_ciphertext, $link->remote_token_ciphertext] : null,
        ], JSON_THROW_ON_ERROR));
    }

    private function currentConsent(object $plan, OcpiProvider $provider): bool
    {
        $actor = User::find($plan->actor_id);
        if (! $plan->enabled || ! $actor || $actor->account_status !== 'active' || $actor->role !== $plan->actor_role
            || (int) $actor->auth_epoch !== (int) $plan->actor_epoch || $provider->owner_id !== (int) $plan->owner_id
            || $provider->authorization_revision !== (int) $plan->provider_revision
            || ! ($actor->role === 'admin' || ($actor->role === 'vendor' && $actor->id === $provider->owner_id))) {
            return false;
        }
        try {
            return hash_equals((string) $plan->configuration_hash, $this->fingerprint($provider, $actor, $plan->module, (int) $plan->interval_minutes));
        } catch (Throwable) {
            return false;
        }
    }

    private function project(?object $plan, string $module, OcpiProvider $provider): array
    {
        return ['module' => $module, 'enabled' => (bool) ($plan->enabled ?? false), 'revision' => (string) ($plan->revision ?? 0),
            'interval_minutes' => (int) ($plan->interval_minutes ?? 60), 'consent_current' => $plan ? $this->currentConsent($plan, $provider) : false,
            'negotiated' => is_string($provider->endpoints[$module] ?? null), 'next_run_at' => $plan->next_run_at ?? null,
            'running' => ($plan->active_run ?? null) !== null, 'stopped_reason' => $plan->stopped_reason ?? null];
    }

    public function show(User $actor, OcpiProvider $provider, int $page = 1): array
    {
        return DB::transaction(function () use ($actor, $provider, $page) {
            $provider = $this->scope($actor, $provider);
            $plans = DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->get()->keyBy('module');
            $history = DB::table('ocpi_sync_runs as r')->join('ocpi_sync_plans as p', 'p.id', '=', 'r.plan_id')->where('p.provider_id', $provider->id)
                ->select('r.id', 'p.module', 'r.state', 'r.result_code', 'r.counts', 'r.started_at', 'r.completed_at')->orderByDesc('r.started_at')->orderByDesc('r.id')->paginate(25, ['*'], 'page', $page);

            return ['catalog_health' => app(PartnerCatalogHealth::class)->snapshot($provider), 'plans' => array_map(fn ($m) => $this->project($plans->get($m), $m, $provider), PartnerClient::MODULES), 'history' => $history->toArray(),
                'configured' => (bool) config('ocpi.scheduled_sync_enabled'), 'intervals' => self::INTERVALS, 'read_only_polling' => true, 'billing_authority' => false, 'remote_control' => false, 'evidence' => 'PARTNER_REPORTED_UNVERIFIED', 'physical_charging_verified' => false];
        });
    }

    public function update(User $actor, OcpiProvider $provider, string $module, bool $enabled, int $interval, int $expected): array
    {
        abort_unless(in_array($module, PartnerClient::MODULES, true) && in_array($interval, self::INTERVALS, true), 422);

        return DB::transaction(function () use ($actor, $provider, $module, $enabled, $interval, $expected) {
            $provider = $this->scope($actor, $provider);
            DB::table('ocpi_sync_plans')->insertOrIgnore(['provider_id' => $provider->id, 'module' => $module, 'enabled' => false, 'revision' => 0, 'interval_minutes' => 60, 'created_at' => now(), 'updated_at' => now()]);
            $plan = DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->where('module', $module)->lockForUpdate()->first();
            if ($enabled) {
                abort_unless(config('ocpi.scheduled_sync_enabled') && is_string($provider->endpoints[$module] ?? null), 409, 'Scheduled polling is not configured for this module.');
                $hash = $this->fingerprint($provider, $actor, $module, $interval);
                if ($this->currentConsent($plan, $provider) && (int) $plan->interval_minutes === $interval) {
                    return $this->project($plan, $module, $provider);
                }
                abort_unless((int) $plan->revision === $expected && $plan->active_run === null, 409, 'Refresh the plan and review any interrupted run.');
            } elseif (! $plan->enabled) {
                return $this->project($plan, $module, $provider);
            }
            abort_if((int) $plan->revision >= PHP_INT_MAX, 409);
            $values = ['enabled' => $enabled, 'revision' => (int) $plan->revision + 1, 'updated_at' => now()];
            if ($enabled) {
                $values += ['interval_minutes' => $interval, 'actor_id' => $actor->id, 'actor_role' => $actor->role, 'actor_epoch' => $actor->auth_epoch,
                    'owner_id' => $provider->owner_id, 'provider_revision' => $provider->authorization_revision, 'configuration_hash' => $hash, 'next_run_at' => now(), 'stopped_reason' => null];
            } else {
                $values += ['next_run_at' => null, 'stopped_reason' => 'operator_disabled'];
            }
            DB::table('ocpi_sync_plans')->where('id', $plan->id)->update($values);
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => $enabled ? 'ocpi.polling_enabled' : 'ocpi.polling_disabled', 'subject' => $provider->id.':'.$module, 'created_at' => now()]);

            return $this->project(DB::table('ocpi_sync_plans')->find($plan->id), $module, $provider);
        }, 3);
    }

    private function locked(object $hint): array
    {
        $this->users($hint->actor_id, $hint->owner_id);
        $provider = OcpiProvider::whereKey($hint->provider_id)->lockForUpdate()->firstOrFail();
        $plan = DB::table('ocpi_sync_plans')->where('id', $hint->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $plan->actor_id === (int) $hint->actor_id && (int) $plan->owner_id === (int) $hint->owner_id, 409, 'Polling consent changed before claim.');

        return [$provider, $plan];
    }

    private function claimCurrent(object $plan, object $current, OcpiProvider $provider, string $id): bool
    {
        return config('ocpi.scheduled_sync_enabled') && $current->active_run === $id
            && (int) $current->revision === (int) $plan->revision
            && (int) $current->provider_id === (int) $plan->provider_id
            && $current->module === $plan->module
            && (int) $current->interval_minutes === (int) $plan->interval_minutes
            && $current->configuration_hash === $plan->configuration_hash
            && (int) $current->actor_epoch === (int) $plan->actor_epoch
            && $current->actor_role === $plan->actor_role
            && (int) $current->provider_revision === (int) $plan->provider_revision
            && $this->currentConsent($current, $provider)
            && DB::table('ocpi_sync_runs')->where('id', $id)->where('plan_id', $current->id)
                ->where('plan_revision', $plan->revision)->where('state', 'claimed')->exists();
    }

    public function run(): array
    {
        if (! config('ocpi.scheduled_sync_enabled')) {
            return ['state' => 'disabled'];
        }
        // An interrupted poll never starts another request invisibly. An operator
        // can disable the plan; the next worker releases only the expired claim.
        $expired = DB::table('ocpi_sync_plans')->whereNotNull('active_run')->where('claimed_at', '<=', now()->subMinutes(30))->orderBy('id')->limit(100)->get();
        foreach ($expired as $hint) {
            DB::transaction(function () use ($hint) {
                [, $p] = $this->locked($hint);
                if ($p->active_run && $p->claimed_at <= now()->subMinutes(30)->toDateTimeString()) {
                    DB::table('ocpi_sync_runs')->where('id', $p->active_run)->where('state', 'claimed')->update(['state' => 'interrupted', 'result_code' => 'operator_review_required', 'completed_at' => now()]);
                    DB::table('ocpi_sync_plans')->where('id', $p->id)->update(['enabled' => false, 'active_run' => null, 'claimed_at' => null, 'next_run_at' => null, 'stopped_reason' => 'interrupted_poll', 'updated_at' => now()]);
                }
            });
        }
        $hints = DB::table('ocpi_sync_plans')->where('enabled', true)->whereNull('active_run')->where('next_run_at', '<=', now())->orderBy('next_run_at')->orderBy('id')->limit(100)->get();
        foreach ($hints as $hint) {
            $claim = DB::transaction(function () use ($hint) {
                [$provider, $plan] = $this->locked($hint);
                if (! $plan->enabled || $plan->active_run || $plan->next_run_at > now()->toDateTimeString()
                    || DB::table('ocpi_sync_plans')->where('provider_id', $provider->id)->whereNotNull('active_run')->exists()) {
                    return null;
                }
                if (! $this->currentConsent($plan, $provider)) {
                    DB::table('ocpi_sync_plans')->where('id', $plan->id)->update(['enabled' => false, 'stopped_reason' => 'consent_or_partner_changed', 'next_run_at' => null, 'updated_at' => now()]);

                    return null;
                }
                $id = (string) Str::uuid();
                DB::table('ocpi_sync_runs')->insert(['id' => $id, 'plan_id' => $plan->id, 'plan_revision' => $plan->revision, 'state' => 'claimed', 'started_at' => now()]);
                DB::table('ocpi_sync_plans')->where('id', $plan->id)->update(['active_run' => $id, 'claimed_at' => now(), 'next_run_at' => now()->addMinutes($plan->interval_minutes), 'updated_at' => now()]);

                return [$provider, $plan, $id];
            }, 3);
            if (! $claim) {
                continue;
            }
            [$provider, $plan, $id] = $claim;
            try {
                $consent = fn () => DB::transaction(function () use ($plan, $id) {
                    [$p, $current] = $this->locked($plan);

                    return $this->claimCurrent($plan, $current, $p, $id);
                });
                $records = $consent() ? app(PartnerClient::class)->pull($provider, $plan->module, [], $consent) : null;

                return DB::transaction(function () use ($plan, $id, $records) {
                    [$provider, $current] = $this->locked($plan);
                    $valid = $records !== null && $this->claimCurrent($plan, $current, $provider, $id);
                    $counts = $valid ? app(RecordSynchronizer::class)->ingest($provider, $plan->module, $records) : null;
                    if ($valid) {
                        $latest = DB::table('ocpi_sync_plans')->where('id', $plan->id)->lockForUpdate()->firstOrFail();
                        if (! $this->claimCurrent($plan, $latest, $provider->fresh(), $id)) {
                            throw new OcpiException('Source polling consent changed during ingestion.');
                        }
                    }
                    DB::table('ocpi_sync_runs')->where('id', $id)->where('state', 'claimed')->update(['state' => $valid ? 'completed' : 'cancelled', 'result_code' => $valid ? null : 'consent_or_partner_changed', 'counts' => $counts ? json_encode($counts, JSON_THROW_ON_ERROR) : null, 'completed_at' => now()]);
                    DB::table('ocpi_sync_plans')->where('id', $plan->id)->where('active_run', $id)->update(['active_run' => null, 'claimed_at' => null, 'updated_at' => now()]);

                    return ['state' => $valid ? 'completed' : 'cancelled', 'run_id' => $id];
                }, 3);
            } catch (Throwable) {
                return DB::transaction(function () use ($plan, $id) {
                    [$provider, $current] = $this->locked($plan);
                    $state = $this->claimCurrent($plan, $current, $provider, $id) ? 'failed' : 'cancelled';
                    DB::table('ocpi_sync_runs')->where('id', $id)->where('state', 'claimed')->update([
                        'state' => $state, 'result_code' => $state === 'failed' ? 'poll_requires_review' : 'consent_or_partner_changed',
                        'completed_at' => now(),
                    ]);
                    DB::table('ocpi_sync_plans')->where('id', $plan->id)->where('active_run', $id)->update(['active_run' => null, 'claimed_at' => null, 'updated_at' => now()]);

                    return ['state' => $state, 'run_id' => $id];
                });
            }
        }

        return ['state' => 'idle'];
    }
}
