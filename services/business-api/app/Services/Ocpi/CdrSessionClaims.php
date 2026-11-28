<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Indexes immutable CDR source identity. It does not approve any financial operation. */
final class CdrSessionClaims
{
    public const BACKFILL_LIMIT = 100;

    public function __construct(private StoredSourceIntegrity $integrity) {}

    public static function metadata(mixed $sessionId, string $sourceHash): array
    {
        return [
            'cdr_session_claim_key' => self::key($sessionId),
            'cdr_session_claim_source_hash' => $sourceHash,
        ];
    }

    private static function key(mixed $sessionId): string
    {
        // Retain the conservative historical handling for missing or unusable links.
        return hash('sha256', is_string($sessionId) && ! empty($sessionId) && strlen($sessionId) <= 64
            ? "linked\0".$sessionId : 'unlinked');
    }

    public function findings(OcpiRecord $cdr, string $sessionId): array
    {
        $scope = OcpiRecord::query()->where('ocpi_provider_id', $cdr->ocpi_provider_id)->whereRaw("module = 'cdrs'")
            ->where('country_code', $cdr->country_code)->where('party_id', $cdr->party_id);
        $hasUnindexed = (clone $scope)->where(fn ($query) => $query->whereNull('cdr_session_claim_key')
            ->orWhereNull('cdr_session_claim_source_hash')->orWhereColumn('cdr_session_claim_source_hash', '<>', 'content_hash'))->exists();
        if ($hasUnindexed) {
            // Keep the original bounded review until this party has complete
            // source-verified indexing, including records created before this change.
            $claims = $scope->orderByDesc('id')->limit(1001)->pluck('normalized');
            $findings = $claims->count() > 1000 ? ['history_exceeds_comparison_limit'] : [];
            if ($claims->contains(fn ($claim) => empty($claim['session_id']))) {
                $findings[] = 'history_contains_unlinked_cdrs';
            }
            if ($claims->filter(fn ($claim) => ($claim['session_id'] ?? null) === $sessionId)->count() > 1) {
                $findings[] = 'multiple_cdrs_for_session';
            }

            return $findings;
        }

        $findings = [];
        $unlinked = self::key(null);
        $claimKey = self::key($sessionId);
        if ((clone $scope)->where('cdr_session_claim_key', $unlinked)->exists()) {
            $findings[] = 'history_contains_unlinked_cdrs';
        }
        if ($claimKey !== $unlinked && $scope->where('cdr_session_claim_key', $claimKey)->limit(2)->get(['id'])->count() > 1) {
            $findings[] = 'multiple_cdrs_for_session';
        }

        return $findings;
    }

    public function backfill(int $providerId, int $after, int $limit): array
    {
        if ($providerId < 1 || $after < 0 || $limit < 1 || $limit > self::BACKFILL_LIMIT) {
            throw new InvalidArgumentException('Invalid bounded CDR claim indexing scope.');
        }

        return DB::transaction(function () use ($providerId, $after, $limit) {
            OcpiProvider::whereKey($providerId)->lockForUpdate()->firstOrFail(['id']);
            $rows = OcpiRecord::where('ocpi_provider_id', $providerId)->where('module', 'cdrs')
                ->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->lockForUpdate()->get();
            $report = ['indexed' => 0, 'unchanged' => 0, 'requires_review' => 0, 'next_after_id' => null];
            foreach ($rows->take($limit) as $row) {
                if (! $this->integrity->consistent($row)) {
                    if ($row->cdr_session_claim_key !== null || $row->cdr_session_claim_source_hash !== null) {
                        DB::table('ocpi_records')->where('id', $row->id)->update([
                            'cdr_session_claim_key' => null, 'cdr_session_claim_source_hash' => null,
                        ]);
                    }
                    $report['requires_review']++;

                    continue;
                }
                $metadata = self::metadata($row->normalized['session_id'] ?? null, $row->content_hash);
                if ($metadata === $row->only(array_keys($metadata))) {
                    $report['unchanged']++;

                    continue;
                }
                // Update index metadata only. Preserve immutable source bytes,
                // normalized payload, receipts and the original update time.
                DB::table('ocpi_records')->where('id', $row->id)->update($metadata);
                $report['indexed']++;
            }
            if ($rows->count() > $limit) {
                $report['next_after_id'] = $rows[$limit - 1]->id;
            }

            return $report;
        }, 3);
    }
}
