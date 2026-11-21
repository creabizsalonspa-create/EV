<?php

namespace App\Services\Commercial\Loyalty;

use Illuminate\Support\Facades\DB;
use LogicException;

/** Reviewed runtime capability for manual noncash inventory; never enables PSPs or point spending. */
final class NoncashActivation
{
    public function legacyPointsEnabled(): bool
    {
        return (bool) config('loyalty.enabled') && ! app()->environment('production');
    }

    public function mode(): string
    {
        if ($this->legacyPointsEnabled()) {
            return 'sandbox';
        }
        $reference = config('loyalty.noncash.approval_reference');
        $terms = config('loyalty.noncash.terms_sha256');
        if (config('loyalty.noncash.mode') === 'reviewed_manual'
            && config('loyalty.noncash.fulfillment_reviewed') === true
            && is_string($reference) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{7,119}$/D', $reference)
            && is_string($terms) && preg_match('/^[a-f0-9]{64}$/D', $terms)) {
            return 'reviewed_manual';
        }

        return 'disabled';
    }

    public function enabled(): bool
    {
        return $this->mode() !== 'disabled';
    }

    public function requireEnabled(): void
    {
        abort_unless($this->enabled(), 503, 'Manual noncash rewards are disabled; configure reviewed terms and fulfillment approval before activation.');
    }

    private function resource(string $kind): array
    {
        return match ($kind) {
            'coupon' => ['loyalty_coupon_activation_evidence', 'coupon_id'],
            'programme' => ['loyalty_referral_activation_evidence', 'programme_id'],
            default => throw new LogicException('Unknown noncash activation resource.'),
        };
    }

    public function record(string $kind, int $id): void
    {
        $this->requireEnabled();
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Noncash activation evidence requires its publication transaction.');
        }
        [$table, $foreign] = $this->resource($kind);
        $mode = $this->mode();
        DB::table($table)->insert([$foreign => $id, 'activation_mode' => $mode,
            'approval_reference' => $mode === 'reviewed_manual' ? config('loyalty.noncash.approval_reference') : null,
            'terms_sha256' => $mode === 'reviewed_manual' ? config('loyalty.noncash.terms_sha256') : null,
            'created_at' => now()]);
    }

    public function metadata(string $kind, int $id, bool $admin = false): array
    {
        [$table, $foreign] = $this->resource($kind);
        $row = DB::table($table)->where($foreign, $id)->first();
        // Pre-capability records could only be created by the old sandbox
        // guard. Never reinterpret them after a deployment flag changes.
        $mode = $row?->activation_mode ?? 'sandbox';
        $value = ['activation_mode' => $mode, 'activation_recorded' => $row !== null,
            'evidence' => match ($mode) {
                'sandbox' => 'TEST_PROMOTIONAL_ECONOMICS', 'reviewed_manual' => 'REVIEWED_MANUAL_NONCASH', default => 'UNKNOWN_NONCASH_ACTIVATION'
            }];
        if ($admin) {
            $value['approval_reference'] = $row?->approval_reference;
            $value['approval_terms_sha256'] = $row?->terms_sha256;
        }

        return $value;
    }

    public function usable(string $kind, int $id, bool $newCommitment = true): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $value = $this->metadata($kind, $id, true);
        if ($value['activation_mode'] !== $this->mode()) {
            return false;
        }
        if ($this->mode() === 'sandbox') {
            return true;
        }
        if (! $value['activation_recorded'] || ! is_string($value['approval_reference']) || ! is_string($value['approval_terms_sha256'])) {
            return false;
        }

        return ! $newCommitment || (hash_equals(config('loyalty.noncash.approval_reference'), $value['approval_reference'])
            && hash_equals(config('loyalty.noncash.terms_sha256'), $value['approval_terms_sha256']));
    }

    public function requireUsable(string $kind, int $id, bool $newCommitment = true): void
    {
        $this->requireEnabled();
        abort_unless($this->usable($kind, $id, $newCommitment), 409, 'This record belongs to a different or unreviewed noncash activation; review the original commitment.');
    }

    public function requirePointIsolation(int $user): void
    {
        if ($this->legacyPointsEnabled()) {
            return;
        }
        abort_if(DB::table('loyalty_awards')->where('user_id', $user)->exists()
            || DB::table('loyalty_ledger')->where('user_id', $user)->where('points', '!=', 0)->exists(),
            409, 'Legacy promotional point sources require reviewed reconciliation; they cannot authorize production noncash fulfillment.');
    }
}
