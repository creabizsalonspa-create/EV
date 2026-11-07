<?php

namespace App\Services\Commercial\Documents;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class FinancialProfiles
{
    public function publish(User $actor, array $values): object
    {
        abort_unless($actor->role === 'admin', 403);
        $key = hash('sha256', $actor->id.':'.$values['idempotency_key']);
        unset($values['idempotency_key']);
        $values['vendor_id'] = isset($values['vendor_id']) ? (int) $values['vendor_id'] : null;
        $values['minor_units'] = (int) $values['minor_units'];
        $values['tax_registration'] = $values['tax_registration'] ?? null;
        ksort($values);
        $hash = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return DB::transaction(function () use ($actor, $values, $key, $hash) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($prior = DB::table('financial_profile_versions')->where('request_key', $key)->first()) {
                abort_unless(hash_equals($prior->fingerprint, $hash), 409, 'Financial profile publishing key payload mismatch.');

                return $prior;
            }
            $region = DB::table('regions')->where('country', $values['country'])->lockForUpdate()->first();
            abort_unless($region && $region->currency === $values['currency'] && (int) $region->minor_units === $values['minor_units'], 409, 'Configure the matching country, currency and precision first.');
            if ($values['vendor_id']) {
                abort_unless(User::whereKey($values['vendor_id'])->where('role', 'vendor')->exists(), 422, 'The financial issuer must be a vendor account.');
            }
            $scope = ($values['vendor_id'] ? 'vendor:'.$values['vendor_id'] : 'platform').':'.$values['country'];
            $version = (int) DB::table('financial_profile_versions')->where('scope_key', $scope)->max('version') + 1;
            $id = DB::table('financial_profile_versions')->insertGetId($values + ['scope_key' => $scope, 'version' => $version, 'created_by' => $actor->id, 'request_key' => $key, 'fingerprint' => $hash, 'created_at' => now()]);
            DB::table('audit_events')->insert(['actor_id' => $actor->id, 'action' => 'finance.issuer_profile_published_unverified', 'subject' => (string) $id, 'created_at' => now()]);

            return DB::table('financial_profile_versions')->find($id);
        }, 3);
    }

    public function forSource(array $source): object
    {
        $query = DB::table('financial_profile_versions')->where('currency', $source['currency'])->where('minor_units', $source['minor_units'])->where('effective_from', '<=', $source['issued_at']);
        $issuerVendor = array_key_exists('issuer_vendor_id', $source) ? $source['issuer_vendor_id'] : $source['vendor_id'];
        if ($issuerVendor) {
            $query->where('vendor_id', $issuerVendor)->where('country', $source['country']);
        } else {
            $query->whereNull('vendor_id');
            if ($source['country'] !== null) {
                $query->where('country', $source['country']);
            }
        }
        $profiles = $query->orderByDesc('effective_from')->orderByDesc('version')->get()->unique('scope_key');
        abort_unless($profiles->count() === 1, 409, 'Exactly one matching effective financial issuer jurisdiction is required. Ask an administrator to configure issuer disclosure; subscription billing country is not inferred from language or device location.');

        return $profiles->first();
    }
}
