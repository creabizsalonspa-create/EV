<?php

namespace App\Services\Ocpi;

use App\Http\Middleware\AdminSecondFactorGate;
use App\Models\OcpiProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Nonfinancial peer trust. Approval never enables commands, charges or a provider. */
final class TechnicalAgreement
{
    public function required(OcpiProvider $provider): bool
    {
        return app()->environment('production')
            || config('ocpi.connections.'.$provider->connection_key.'.environment') === 'production'
            || config('ocpi.connections.'.$provider->connection_key.'.technical_agreement_required') === true;
    }

    /** Server-derived bilateral identity; never includes an origin or credential in API output. */
    public function identity(OcpiProvider $provider): array
    {
        $probe = clone $provider;
        $probe->authorized = true;
        $c = app(PartnerClient::class)->connection($probe, true);
        $local = app(CredentialExchange::class)->localPayload('');
        abort_unless(preg_match('/^[A-Z]{2}$/D', (string) ($c['party_country'] ?? ''))
            && preg_match('/^[A-Z0-9]{3}$/D', (string) ($c['party_id'] ?? ''))
            && in_array($c['party_role'] ?? null, ['CPO', 'EMSP'], true), 409, 'Configured bilateral peer identity is required.');
        abort_unless($c['party_role'] !== $local['roles'][0]['role'], 409, 'Opposite CPO/eMSP peer roles are required.');
        $incoming = $c['incoming_modules'] ?? [];
        abort_unless(is_array($incoming) && array_is_list($incoming)
            && count($incoming) === count(array_unique($incoming, SORT_REGULAR)), 409);
        foreach ($incoming as $module) {
            abort_unless(is_string($module) && in_array($module, PartnerClient::MODULES, true), 409);
        }
        sort($incoming, SORT_STRING);

        return ['provider_id' => $provider->id, 'owner_id' => $provider->owner_id,
            'connection_key_hash' => hash('sha256', $provider->connection_key),
            'origin_hash' => hash('sha256', rtrim($c['origin'], '/')),
            'peer_country' => $c['party_country'], 'peer_party' => $c['party_id'], 'peer_role' => $c['party_role'],
            'local_country' => $local['roles'][0]['country_code'], 'local_party' => $local['roles'][0]['party_id'],
            'local_role' => $local['roles'][0]['role'], 'local_identity_hash' => hash('sha256', json_encode($local, JSON_THROW_ON_ERROR)),
            'version' => '2.2.1', 'incoming_modules' => $incoming,
            'registration_direction' => $c['registration_direction'] ?? 'outbound'];
    }

    public function fingerprint(OcpiProvider $provider): string
    {
        return hash('sha256', json_encode($this->identity($provider), JSON_THROW_ON_ERROR));
    }

    private function validQuery(OcpiProvider $provider)
    {
        $now = now()->utc()->format('Y-m-d H:i:s.u');

        return DB::table('ocpi_technical_agreements as a')
            ->join('ocpi_technical_agreement_heads as h', function ($j) {
                $j->on('h.active_agreement_id', '=', 'a.id')->on('h.provider_id', '=', 'a.provider_id');
            })
            ->where('a.provider_id', $provider->id)->where('a.provider_owner_id', $provider->owner_id)
            ->where('a.configuration_hash', $this->fingerprint($provider))
            ->where('a.valid_from', '<=', $now)->where('a.valid_until', '>', $now)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ocpi_technical_agreement_events as e')->whereColumn('e.agreement_id', 'a.id')->where('e.action', 'approved'));
    }

    public function active(OcpiProvider $provider): ?object
    {
        try {
            if (! User::whereKey($provider->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists()) {
                return null;
            }

            return $this->validQuery($provider)->select('a.*')->first();
        } catch (OcpiException) {
            return null;
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    public function allowed(OcpiProvider $provider): array
    {
        if (! $this->required($provider)) {
            return PartnerClient::MODULES;
        }
        $row = $this->active($provider);
        if (! $row) {
            return [];
        }
        $modules = json_decode($row->allowed_modules, true, flags: JSON_THROW_ON_ERROR);

        return is_array($modules) ? array_values(array_intersect(PartnerClient::MODULES, $modules)) : [];
    }

    public function assertActive(OcpiProvider $provider, ?string $module = null): void
    {
        if (! $this->required($provider)) {
            return;
        }
        $allowed = $this->allowed($provider);
        if ($allowed === [] || ($module !== null && ! in_array($module, $allowed, true))) {
            throw new OcpiException('A current approved OCPI technical agreement with this operation scope is required.');
        }
    }

    /** Enforced at SQL pagination time, including expiry/configuration/owner changes. */
    public function restrictDiscovery(Builder $query): Builder
    {
        $connections = config('ocpi.connections', []);
        $required = [];
        foreach ($connections as $key => $c) {
            $probe = new OcpiProvider(['connection_key' => $key]);
            if ($this->required($probe)) {
                $required[] = $key;
            }
        }
        if (! app()->environment('production') && $required === []) {
            return $query;
        }

        return $query->where(function ($outer) use ($required) {
            if (! app()->environment('production')) {
                $outer->whereNotIn('ocpi_providers.connection_key', $required);
            } else {
                $outer->whereRaw('1 = 0');
            }
            // A small server-provisioned connection list, not supplier-controlled data.
            foreach (OcpiProvider::whereIn('connection_key', $required)->get() as $provider) {
                $key = $provider->connection_key;
                try {
                    $ids = $this->validQuery($provider)->whereJsonContains('a.allowed_modules', 'locations')->select('a.provider_id');
                    $outer->orWhere(fn ($q) => $q->where('ocpi_providers.connection_key', $key)
                        ->whereIn('ocpi_providers.id', $ids)
                        ->whereIn('ocpi_providers.owner_id', User::where('role', 'vendor')->where('account_status', 'active')->select('id')));
                } catch (OcpiException|HttpExceptionInterface) {
                    // Inconsistent configured identity is never a publication grant.
                }
            }
        });
    }

    private function locked(OcpiProvider $snapshot, User $actor): array
    {
        $provider = OcpiProvider::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $current = app(AdminCredentialApproval::class)->current($actor, true);
        if (AdminSecondFactorGate::required()) {
            DB::table('admin_second_factors')->where('user_id', $current->id)->lockForUpdate()->first();
            abort_unless(AdminSecondFactorGate::verified(request(), $current), 403, 'Current administrator MFA approval is required.');
        }
        DB::table('ocpi_technical_agreement_heads')->insertOrIgnore(['provider_id' => $provider->id, 'revision' => 0]);
        $head = DB::table('ocpi_technical_agreement_heads')->where('provider_id', $provider->id)->lockForUpdate()->first();

        return [$provider, $head];
    }

    public function propose(OcpiProvider $snapshot, User $actor, array $v): int
    {
        return DB::transaction(function () use ($snapshot, $actor, $v) {
            [$p, $head] = $this->locked($snapshot, $actor);
            abort_unless(User::whereKey($p->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(), 409);
            $modules = $v['allowed_modules'];
            sort($modules, SORT_STRING);
            $payload = ['reference' => $v['reference'], 'evidence_sha256' => $v['evidence_sha256'],
                'terms_en' => $v['terms_en'], 'terms_ar' => $v['terms_ar'],
                'allowed_modules' => $modules, 'valid_from' => $v['valid_from'], 'valid_until' => $v['valid_until'],
                'identity' => $this->identity($p)];
            $text = json_encode($payload, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', json_encode([$actor->id, $v['expected_revision'], $payload], JSON_THROW_ON_ERROR));
            $old = DB::table('ocpi_technical_agreements')->where('provider_id', $p->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409);

                return $old->id;
            }
            abort_unless($head->revision === $v['expected_revision'] && $head->revision < 2147483647, 409, 'Refresh the current agreement revision.');
            $id = DB::table('ocpi_technical_agreements')->insertGetId(['provider_id' => $p->id, 'revision' => $head->revision + 1,
                'provider_owner_id' => $p->owner_id, 'configuration_hash' => $this->fingerprint($p),
                'request_key' => $v['request_key'], 'request_hash' => $hash, 'payload_ciphertext' => Crypt::encryptString($text),
                'payload_hash' => hash('sha256', $text), 'allowed_modules' => json_encode($modules, JSON_THROW_ON_ERROR),
                'valid_from' => CarbonImmutable::parse($v['valid_from'])->format('Y-m-d H:i:s.u'),
                'valid_until' => CarbonImmutable::parse($v['valid_until'])->format('Y-m-d H:i:s.u'), 'created_by' => $actor->id, 'created_at' => now()]);
            abort_unless($id <= 9007199254740991, 409, 'Agreement identity range is exhausted.');
            DB::table('ocpi_technical_agreement_heads')->where('provider_id', $p->id)->update(['revision' => $head->revision + 1]);
            $this->audit($actor, $p, 'proposed');

            return $id;
        }, 3);
    }

    public function decide(OcpiProvider $snapshot, User $actor, int $id, array $v): int
    {
        return DB::transaction(function () use ($snapshot, $actor, $id, $v) {
            [$p, $head] = $this->locked($snapshot, $actor);
            $row = DB::table('ocpi_technical_agreements')->where('provider_id', $p->id)->where('id', $id)->first();
            abort_unless($row, 404);
            $hash = hash('sha256', json_encode([$actor->id, $id, $v], JSON_THROW_ON_ERROR));
            $old = DB::table('ocpi_technical_agreement_events')->where('provider_id', $p->id)->where('request_key', $v['request_key'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409);

                return $id;
            }
            abort_unless($head->revision === $v['expected_revision'], 409, 'Refresh the current agreement revision.');
            $revoke = $v['action'] === 'revoked';
            if ($revoke) {
                abort_unless($head->active_agreement_id === $id, 409, 'Only the current approved agreement can be revoked.');
            } else {
                abort_unless($row->revision === $head->revision && ! DB::table('ocpi_technical_agreement_events')->where('agreement_id', $id)->where('phase', 'review')->exists(), 409);
                if ($v['action'] === 'approved') {
                    abort_unless(hash_equals($row->configuration_hash, $this->fingerprint($p)) && $row->provider_owner_id === $p->owner_id
                        && CarbonImmutable::parse($row->valid_from)->lessThanOrEqualTo(now()) && CarbonImmutable::parse($row->valid_until)->greaterThan(now())
                        && User::whereKey($p->owner_id)->where('role', 'vendor')->where('account_status', 'active')->exists(), 409, 'Current peer identity and effective dates must match before approval.');
                }
            }
            DB::table('ocpi_technical_agreement_events')->insert(['provider_id' => $p->id, 'agreement_id' => $id,
                'phase' => $revoke ? 'revocation' : 'review', 'action' => $v['action'], 'request_key' => $v['request_key'],
                'request_hash' => $hash, 'reason_ciphertext' => Crypt::encryptString($v['reason']), 'actor_id' => $actor->id, 'created_at' => now()]);
            if ($revoke || $v['action'] === 'approved') {
                DB::table('ocpi_technical_agreement_heads')->where('provider_id', $p->id)->update(['active_agreement_id' => $revoke ? null : $id]);
                $this->withdraw($p);
            }
            $this->audit($actor, $p, $v['action']);

            return $id;
        }, 3);
    }

    private function withdraw(OcpiProvider $p): void
    {
        $p->fill(['authorized' => false, 'version' => null, 'endpoints' => null, 'negotiated_at' => null,
            'authorization_revision' => min(PHP_INT_MAX, $p->authorization_revision + ($p->authorization_revision < PHP_INT_MAX ? 1 : 0))])->save();
        DB::table('ocpi_credential_links')->where('provider_id', $p->id)->update(['state' => 'revoked', 'local_token_digest' => null, 'updated_at' => now()]);
        DB::table('ocpi_credential_operations')->where('provider_id', $p->id)->where('action', 'prepare_incoming')->where('state', 'awaiting_peer')->update(['state' => 'revoked', 'completed_at' => now(), 'updated_at' => now()]);
    }

    private function audit(User $actor, OcpiProvider $p, string $action): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'ocpi.technical_agreement_'.$action, 'subject' => (string) $p->id, 'created_at' => now()]);
    }
}
