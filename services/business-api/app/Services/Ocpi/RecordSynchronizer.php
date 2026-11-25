<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecordSynchronizer
{
    public function __construct(private RecordNormalizer $normalizer) {}

    public function ingest(OcpiProvider $provider, string $module, array $records): array
    {
        if (! $provider->authorized || $provider->version !== '2.2.1' || ! in_array($module, PartnerClient::MODULES, true)) {
            throw new OcpiException('Provider is not authorized and negotiated for ingestion.');
        }
        if (count($records) > (int) config('ocpi.max_records', 1000)) {
            throw new OcpiException('Record batch exceeds the configured limit.');
        }
        $validated = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new OcpiException('Malformed OCPI record.');
            }
            $validated[] = [$record, $this->normalizer->normalize($module, $record), $this->normalizer->hash($record)];
        }

        return DB::transaction(function () use ($provider, $module, $validated) {
            // Serialize batches per provider across modules and sources; an entire failed batch rolls back.
            $locked = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            if (! $locked->authorized || $locked->version !== '2.2.1' || $locked->authorization_revision !== $provider->authorization_revision || $locked->owner_id !== $provider->owner_id || $locked->connection_key !== $provider->connection_key) {
                throw new OcpiException('Provider authorization changed.');
            }
            if (! app(ContractReviewGate::class)->current($locked)) {
                throw new OcpiException('Current partner owner contract review expired before source ingestion.');
            }
            // A location/tariff response can arrive after the owning vendor
            // was suspended. Do not mutate a catalog that may later reappear
            // upon reactivation. Existing sessions/CDRs remain eligible for
            // explicitly admin-authorized historical financial reconciliation.
            if (in_array($module, ['locations', 'tariffs'], true)
                && ! User::whereKey($locked->owner_id)->where('role', 'vendor')
                    ->where('account_status', 'active')->exists()) {
                throw new OcpiException('Inactive operator owner cannot publish catalog source records.');
            }
            // A bilateral managed connection approves one peer identity.
            // Apply the same party boundary to pulls and pushes, regardless
            // of which application entry point supplies the normalized batch.
            $connection = config('ocpi.connections.'.$locked->connection_key);
            if (is_array($connection) && ($connection['managed_credentials'] ?? false) === true) {
                $country = $connection['party_country'] ?? null;
                $party = $connection['party_id'] ?? null;
                if (! is_string($country) || ! preg_match('/^[A-Z]{2}$/D', $country)
                    || ! is_string($party) || ! preg_match('/^[A-Z0-9]{3}$/D', $party)) {
                    throw new OcpiException('Approved managed operator identity is unavailable.');
                }
                foreach ($validated as [, $normalized]) {
                    if ($normalized['country_code'] !== $country || $normalized['party_id'] !== $party) {
                        throw new OcpiException('Source record is outside the approved managed operator identity.');
                    }
                }
            }
            $counts = ['created' => 0, 'updated' => 0, 'duplicate' => 0, 'stale' => 0];
            foreach ($validated as [$raw,$n,$hash]) {
                $identity = ['ocpi_provider_id' => $locked->id, 'module' => $module, 'country_code' => $n['country_code'], 'party_id' => $n['party_id'], 'external_id' => $n['id']];
                $existing = OcpiRecord::where($identity)->first();
                $previous = null;
                $updated = CarbonImmutable::parse($n['last_updated']);
                if ($existing && $module === 'tariffs') {
                    $retired = DB::table('ocpi_catalog_retirements')->where('record_id', $existing->id)->orderByDesc('id')->first();
                    if ($retired && ($updated->lte(CarbonImmutable::parse($retired->retired_at, 'UTC')) || $updated->lte(CarbonImmutable::parse($retired->source_updated_at, 'UTC')))) {
                        $counts['stale']++;

                        continue;
                    }
                }
                if ($existing) {
                    if (($connection['managed_credentials'] ?? false) === true
                        && ! app(StoredSourceIntegrity::class)->consistent($existing)) {
                        throw new OcpiException('Stored managed operator record requires integrity review before synchronization.');
                    }
                    if (hash_equals($existing->content_hash, $hash)) {
                        $counts['duplicate']++;

                        continue;
                    }
                    if ($module === 'cdrs') {
                        throw new OcpiException('Conflicting OCPI CDR replay requires reconciliation; accepted CDRs are immutable.');
                    }
                    if ($updated->lessThan($existing->source_updated_at)) {
                        $counts['stale']++;

                        continue;
                    }
                    if ($updated->equalTo($existing->source_updated_at)) {
                        throw new OcpiException('Conflicting OCPI replay at the same source timestamp.');
                    }
                    if ($module === 'sessions' && DB::table('ocpi_test_invoice_receipts')->where('source_session_record_id', $existing->id)->exists()) {
                        throw new OcpiException('Consented TEST invoice source is immutable; a separate correction requires review.');
                    }
                    if ($module === 'sessions' && ($existing->normalized['status'] === 'COMPLETED' && $n['status'] !== 'COMPLETED')) {
                        throw new OcpiException('Completed OCPI session cannot return to an active state.');
                    }
                    $previous = clone $existing;
                    $existing->update(['payload' => $raw, 'normalized' => $n, 'content_hash' => $hash, 'source_updated_at' => $updated, 'received_at' => now()]);
                    $counts['updated']++;
                } else {
                    $existing = OcpiRecord::create($identity + ['payload' => $raw, 'normalized' => $n, 'content_hash' => $hash, 'source_updated_at' => $updated, 'received_at' => now()]);
                    $counts['created']++;
                }
                if ($module === 'sessions') {
                    app(SessionMeterHistory::class)->append($existing, (int) $locked->owner_id, $previous);
                }
            }
            $locked->update(['last_synced_at' => now()]);
            if ($module === 'locations' && ($counts['created'] + $counts['updated']) > 0) {
                // The whole quality observation commits or rolls back together
                // with the canonical normalized source batch.
                app(CatalogQualityHistory::class)->capture($locked);
            }

            return $counts;
        });
    }
}
