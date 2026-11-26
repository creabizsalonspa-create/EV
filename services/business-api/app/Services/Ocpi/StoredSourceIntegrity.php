<?php

namespace App\Services\Ocpi;

use App\Models\OcpiProvider;
use App\Models\OcpiRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Offline consistency observations over already stored OCPI rows. This is
 * neither a peer conformance test nor permission to repair source records.
 */
final class StoredSourceIntegrity
{
    public const MAX_PAGE = 100;

    private const FINDINGS = [
        'payload_unreadable',
        'digest_mismatch',
        'current_subset_validation_failed',
        'identity_mismatch',
        'source_timestamp_mismatch',
        'projection_mismatch',
    ];

    public function __construct(private RecordNormalizer $normalizer) {}

    public function inspect(int $providerId, string $module, int $after = 0, int $limit = 50): array
    {
        if ($providerId < 1 || $after < 0 || $limit < 1 || $limit > self::MAX_PAGE
            || ! in_array($module, PartnerClient::MODULES, true)) {
            throw new InvalidArgumentException('Invalid bounded source inspection scope.');
        }

        // Privileged operator shell can review historical revoked rows. There
        // is no provider credential use, network transport or mutation here.
        OcpiProvider::query()->whereKey($providerId)->firstOrFail(['id']);
        $page = OcpiRecord::query()->where('ocpi_provider_id', $providerId)
            ->where('module', $module)->where('id', '>', $after)
            ->orderBy('id')->limit($limit + 1)->get();
        $more = $page->count() > $limit;
        $rows = $page->take($limit);
        $counts = array_fill_keys(self::FINDINGS, 0);
        $review = 0;
        foreach ($rows as $row) {
            $findings = $this->findings($row, $module);
            if ($findings !== []) {
                $review++;
            }
            foreach ($findings as $finding) {
                $counts[$finding]++;
            }
        }

        return [
            'evidence' => 'STORED_SOURCE_CONSISTENCY_ONLY',
            'provider_id' => $providerId,
            'module' => $module,
            'after_id' => $after,
            'page_limit' => $limit,
            'next_after_id' => $more ? (int) $rows->last()->id : null,
            'has_more' => $more,
            'examined' => $rows->count(),
            'consistent' => $rows->count() - $review,
            'review_required' => $review,
            'findings' => $counts,
            'status' => $rows->isEmpty() ? 'no_evidence' : ($review > 0 ? 'review_required' : 'page_consistent'),
            'whole_catalog_verified' => false,
            'partner_conformance_verified' => false,
            'live_availability_verified' => false,
            'charging_authority' => false,
            'billing_authority' => false,
            'external_requests' => 0,
            'source_mutations' => 0,
        ];
    }

    private function findings(OcpiRecord $row, string $module): array
    {
        try {
            $raw = $row->payload;
            if (! is_array($raw) || array_is_list($raw)) {
                return ['payload_unreadable'];
            }
        } catch (Throwable) {
            // Never return decryption errors, identifiers, raw JSON or hashes.
            return ['payload_unreadable'];
        }

        $findings = [];
        try {
            if (! is_string($row->content_hash) || ! hash_equals($row->content_hash, $this->normalizer->hash($raw))) {
                $findings[] = 'digest_mismatch';
            }
        } catch (Throwable) {
            $findings[] = 'digest_mismatch';
        }

        try {
            $expected = $this->normalizer->normalize($module, $raw);
        } catch (Throwable) {
            $findings[] = 'current_subset_validation_failed';

            return $findings;
        }

        if ($row->country_code !== $expected['country_code'] || $row->party_id !== $expected['party_id']
            || $row->external_id !== $expected['id']) {
            $findings[] = 'identity_mismatch';
        }
        try {
            if (! $row->source_updated_at || ! $row->source_updated_at->equalTo(CarbonImmutable::parse($expected['last_updated'], 'UTC'))) {
                $findings[] = 'source_timestamp_mismatch';
            }
        } catch (Throwable) {
            $findings[] = 'source_timestamp_mismatch';
        }

        try {
            $stored = $row->normalized;
            if (! is_array($stored) || ! hash_equals($this->normalizer->hash($expected), $this->normalizer->hash($stored))) {
                $findings[] = 'projection_mismatch';
            }
        } catch (Throwable) {
            $findings[] = 'projection_mismatch';
        }

        return $findings;
    }
}
