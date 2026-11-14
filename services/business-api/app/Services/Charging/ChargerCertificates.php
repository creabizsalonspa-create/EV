<?php

namespace App\Services\Charging;

use App\Models\Charging\AdmissionProfile;
use App\Models\User;
use App\Models\VendorOps\VendorCharger;
use App\Models\VendorOps\VendorCompany;
use App\Services\VendorOps\VendorAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ChargerCertificates
{
    public function scope(User $actor, VendorCompany $company, VendorCharger $charger, bool $write = false): void
    {
        app(VendorAccess::class)->require($actor, $company, $write ? 'inventory.write' : 'inventory.read');
        abort_unless($charger->company_id === $company->id, 404);
        if ($write) {
            abort_unless($actor->role === 'admin' || $actor->id === $company->owner_id, 404);
        }
    }

    public function usable(AdmissionProfile $profile): ?object
    {
        if (! $profile->managed_certificate_id) {
            return null;
        }
        $cert = DB::table('charger_certificates')->find($profile->managed_certificate_id);
        abort_unless($cert && $cert->profile_id === $profile->id && $cert->identity === $profile->identity && hash_equals($cert->fingerprint, $profile->certificate_sha256 ?? '') && CarbonImmutable::parse($cert->not_before)->lte(now()) && CarbonImmutable::parse($cert->not_after)->gt(now()), 409, 'Managed charger certificate is not currently usable.');
        abort_if(DB::table('charger_certificate_actions')->where('certificate_id', $cert->id)->where('action', 'revoke')->where('state', 'completed')->exists(), 409, 'Managed charger certificate is revoked.');
        $chain = $cert->issuer_chain === null ? '' : Crypt::decryptString($cert->issuer_chain);
        abort_unless(($cert->chain_sha256 === null && $chain === '') || ($cert->chain_sha256 !== null && hash_equals($cert->chain_sha256, hash('sha256', $chain))), 409, 'Certificate issuer chain changed.');
        $verified = app(ChargerCertificateVerifier::class)->verify(Crypt::decryptString($cert->public_certificate), $profile->identity, $chain);
        abort_unless(hash_equals($cert->fingerprint, $verified['fingerprint']) && hash_equals($cert->issuer_fingerprint, $verified['issuer_fingerprint']) && hash_equals($cert->trust_anchor_fingerprint ?? $cert->issuer_fingerprint, $verified['trust_anchor_fingerprint']) && (int) $cert->chain_length === $verified['chain_length'], 409, 'Managed certificate fingerprints changed.');

        return $cert;
    }

    public function present(object $cert, ?AdmissionProfile $profile = null): array
    {
        $revoked = DB::table('charger_certificate_actions')->where('certificate_id', $cert->id)->where('action', 'revoke')->where('state', 'completed')->exists();
        $state = $revoked ? 'revoked' : (CarbonImmutable::parse($cert->not_after)->lte(now()) ? 'expired' : 'registered');
        if ($profile && $profile->managed_certificate_id === $cert->id && $state === 'registered') {
            try {
                $this->usable($profile);
                $state = CarbonImmutable::parse($cert->not_after)->lte(now()->addDays(7)) ? 'expiring' : 'current';
            } catch (\Throwable) {
                $state = 'requires_review';
            }
        }

        if ($profile && $profile->managed_certificate_id !== $cert->id && $state === 'registered') {
            $state = 'superseded';
        }

        return ['id' => $cert->id, 'profile_id' => $cert->profile_id, 'fingerprint' => $cert->fingerprint, 'issuer_fingerprint' => $cert->issuer_fingerprint, 'trust_anchor_fingerprint' => $cert->trust_anchor_fingerprint ?? $cert->issuer_fingerprint, 'chain_length' => (int) $cert->chain_length, 'serial' => $cert->serial, 'identity' => $cert->identity, 'key_type' => $cert->key_type, 'key_bits' => $cert->key_bits, 'not_before' => $cert->not_before, 'not_after' => $cert->not_after, 'state' => $state, 'created_at' => $cert->created_at];
    }

    private function receipt(object $action): array
    {
        $cert = $action->certificate_id ? DB::table('charger_certificates')->find($action->certificate_id) : null;

        return ['request_key' => $action->request_key, 'actor_id' => $action->actor_id, 'profile_id' => $action->profile_id, 'action' => $action->action, 'state' => $action->state, 'certificate_id' => $action->certificate_id, 'certificate' => $cert ? $this->present($cert) : null, 'result_version' => $action->result_version, 'device_commands_sent' => false, 'connection_close_requested' => false, 'gateway_recheck_may_disconnect' => true];
    }

    public function apply(User $actor, VendorCompany $company, VendorCharger $charger, string $kind, array $intent): array
    {
        ksort($intent);
        $hash = hash('sha256', json_encode([$charger->id, $kind, $intent], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($actor, $company, $charger, $kind, $intent, $hash) {
                $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $charger = VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
                $this->scope($actor, $company, $charger, true);
                $p = AdmissionProfile::where('charger_id', $charger->id)->lockForUpdate()->firstOrFail();
                abort_unless($intent['expected_actor_id'] === $actor->id && $intent['expected_profile_id'] === $p->id, 409, 'Certificate account or profile context changed.');
                $old = DB::table('charger_certificate_actions')->where('actor_id', $actor->id)->where('request_key', $intent['idempotency_key'])->first();
                if ($old) {
                    abort_unless($old->profile_id === $p->id && $old->action === $kind && $old->state === 'completed' && hash_equals($old->payload_hash ?? '', $hash), 409, 'Certificate request is cancelled or conflicts.');

                    return $this->receipt($old);
                }
                abort_unless($p->credential_version === $intent['expected_version'] && $intent['consent'] === true, 409, 'Refresh the certificate/credential version before another explicit action.');
                if ($kind === 'enroll') {
                    abort_unless(config('csms.certificate_management_enabled'), 503, 'Certificate enrollment is disabled.');
                    app(VendorAccess::class)->operational($company);
                    abort_unless(app(AdmissionIdentity::class)->compatible($company, $charger, $p->identity, $p->protocol, app()->environment('production')), 409, 'Canonical charger identity requires review.');
                    $metadata = app(ChargerCertificateVerifier::class)->verify($intent['public_certificate'], $p->identity, $intent['issuer_chain'] ?? null);
                    $chain = $metadata['issuer_chain'];
                    unset($metadata['issuer_chain']);
                    abort_if(DB::table('charger_certificates')->where('fingerprint', $metadata['fingerprint'])->exists(), 409, 'Certificate is already registered; resolve its existing request.');
                    $id = DB::table('charger_certificates')->insertGetId(['profile_id' => $p->id, 'public_certificate' => Crypt::encryptString($intent['public_certificate']), 'issuer_chain' => $chain === '' ? null : Crypt::encryptString($chain), 'created_at' => now()] + $metadata);
                    $p->update(['managed_certificate_id' => $id, 'certificate_sha256' => $metadata['fingerprint'], 'enabled' => false, 'credential_digest' => null, 'credential_version' => $p->credential_version + 1]);
                } else {
                    $cert = DB::table('charger_certificates')->where('id', $intent['certificate_id'])->where('profile_id', $p->id)->first();
                    abort_unless($cert, 404);
                    abort_if(DB::table('charger_certificate_actions')->where('certificate_id', $cert->id)->where('action', 'revoke')->where('state', 'completed')->exists(), 409, 'Certificate is already revoked; resolve the prior request.');
                    $id = $cert->id;
                    if ($p->managed_certificate_id === $cert->id) {
                        $p->update(['enabled' => false, 'credential_digest' => null, 'credential_version' => $p->credential_version + 1]);
                    }
                }
                $actionId = DB::table('charger_certificate_actions')->insertGetId(['profile_id' => $p->id, 'certificate_id' => $id, 'actor_id' => $actor->id, 'request_key' => $intent['idempotency_key'], 'action' => $kind, 'state' => 'completed', 'payload_hash' => $hash, 'reason' => Crypt::encryptString($intent['reason']), 'result_version' => $p->credential_version, 'created_at' => now()]);
                DB::table('charger_admission_events')->insert(['profile_id' => $p->id, 'actor_id' => $actor->id, 'action' => 'certificate_'.$kind, 'credential_version' => $p->credential_version, 'created_at' => now()]);

                return $this->receipt(DB::table('charger_certificate_actions')->find($actionId));
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Certificate serial or request identity is already registered.');
        }
    }

    public function resolve(User $actor, VendorCompany $company, VendorCharger $charger, array $intent): array
    {
        return DB::transaction(function () use ($actor, $company, $charger, $intent) {
            $company = VendorCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $charger = VendorCharger::whereKey($charger->id)->lockForUpdate()->firstOrFail();
            $this->scope($actor, $company, $charger, true);
            $p = AdmissionProfile::where('charger_id', $charger->id)->lockForUpdate()->firstOrFail();
            abort_unless($intent['expected_actor_id'] === $actor->id && $intent['expected_profile_id'] === $p->id, 409);
            $old = DB::table('charger_certificate_actions')->where('actor_id', $actor->id)->where('request_key', $intent['idempotency_key'])->first();
            if ($old) {
                abort_unless($old->profile_id === $p->id && $old->action === $intent['expected_action'], 409);

                return $this->receipt($old);
            }
            abort_unless($intent['cancel_unissued'] === true, 409);
            abort_if(DB::table('charger_certificate_actions')->where('actor_id', $actor->id)->where('state', 'cancelled')->where('created_at', '>=', now()->subDay())->count() >= 30, 429);
            $id = DB::table('charger_certificate_actions')->insertGetId(['profile_id' => $p->id, 'actor_id' => $actor->id, 'request_key' => $intent['idempotency_key'], 'action' => $intent['expected_action'], 'state' => 'cancelled', 'result_version' => $p->credential_version, 'created_at' => now()]);

            return $this->receipt(DB::table('charger_certificate_actions')->find($id));
        }, 3);
    }
}
