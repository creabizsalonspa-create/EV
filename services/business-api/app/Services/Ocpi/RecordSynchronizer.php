<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
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
            }$validated[] = [$record, $this->normalizer->normalize($module, $record), $this->normalizer->hash($record)];
        }

        return DB::transaction(function () use ($provider, $module, $validated) {
            // Serialize batches per provider across modules and sources; an entire failed batch rolls back.
            $locked = OcpiProvider::whereKey($provider->id)->lockForUpdate()->firstOrFail();
            if (! $locked->authorized || $locked->version !== '2.2.1' || $locked->authorization_revision !== $provider->authorization_revision || $locked->owner_id !== $provider->owner_id || $locked->connection_key !== $provider->connection_key) {
                throw new OcpiException('Provider authorization changed.');
            }
            $counts = ['created' => 0, 'updated' => 0, 'duplicate' => 0, 'stale' => 0];
            foreach ($validated as [$raw,$n,$hash]) {
                $identity = ['ocpi_provider_id' => $locked->id, 'module' => $module, 'country_code' => $n['country_code'], 'party_id' => $n['party_id'], 'external_id' => $n['id']];
                $existing = OcpiRecord::where($identity)->first();
                $updated = CarbonImmutable::parse($n['last_updated']);
                if ($existing) {
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
                    if ($module === 'sessions' && ($existing->normalized['status'] === 'COMPLETED' && $n['status'] !== 'COMPLETED')) {
                        throw new OcpiException('Completed OCPI session cannot return to an active state.');
                    }
                    $existing->update(['payload' => $raw, 'normalized' => $n, 'content_hash' => $hash, 'source_updated_at' => $updated, 'received_at' => now()]);
                    $counts['updated']++;
                } else {
                    OcpiRecord::create($identity + ['payload' => $raw, 'normalized' => $n, 'content_hash' => $hash, 'source_updated_at' => $updated, 'received_at' => now()]);
                    $counts['created']++;
                }
            }
            $locked->update(['last_synced_at' => now()]);

            return $counts;
        });
    }
}
