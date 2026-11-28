<?php

namespace App\Services\Charging;

use Illuminate\Support\Facades\DB;

/** Local operator authority only; never an upstream request or charger admission. */
final class DirectSourceAuthority
{
    private function configured(): array
    {
        $value = config('csms.registered_source_authority_epoch');
        abort_unless((is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]{0,15}$/D', $value)))
            && (int) $value > 0 && (int) $value <= 9007199254740991, 503, 'Configure a positive direct source authority epoch.');
        $url = app(CsmsGraphqlTransport::class)->requireApproved();
        $secret = config('csms.graphql_secret');
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && is_string($secret) && strlen($secret) >= 32,
            503, 'Direct source authority requires authenticated TLS.');

        return [(int) $value, hash('sha256', json_encode(['direct-source-v1', $url, hash('sha256', $secret)], JSON_THROW_ON_ERROR))];
    }

    /** Explicit privileged shell action, with immutable ticket-correlated history. */
    public function provision(string $ticket): bool
    {
        abort_unless(preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $ticket), 422, 'A valid operator ticket reference is required.');
        [$epoch, $fingerprint] = $this->configured();

        return DB::transaction(function () use ($epoch, $fingerprint, $ticket) {
            $state = DB::table('direct_source_authority_state')->where('id', 1)->lockForUpdate()->first();
            abort_unless($state && $this->configured() === [$epoch, $fingerprint], 409, 'Direct source configuration changed.');
            $latest = DB::table('direct_source_authority_epochs')->max('epoch');
            abort_unless(($latest === null && $state->epoch === null)
                || ($latest !== null && (int) $latest === (int) $state->epoch), 409, 'Direct source authority history requires reconciliation.');
            if ($latest !== null) {
                abort_unless($epoch >= (int) $latest, 409, 'Direct source authority epoch cannot regress.');
                if ($epoch === (int) $latest) {
                    $previous = DB::table('direct_source_authority_epochs')->where('epoch', $epoch)->first();
                    abort_unless($previous && hash_equals($previous->authority_fingerprint, $fingerprint), 409,
                        'Changed direct source authority requires a higher epoch.');

                    return false;
                }
            }
            DB::table('direct_source_authority_epochs')->insert(['epoch' => $epoch,
                'authority_fingerprint' => $fingerprint, 'ticket_reference' => $ticket,
                'provisioned_at' => now()->format('Y-m-d H:i:s.u')]);
            DB::table('direct_source_authority_state')->where('id', 1)->update(['epoch' => $epoch]);

            return true;
        }, 3);
    }

    /** Read-only check. During review persistence the state row remains locked. */
    public function requireCurrent(): array
    {
        [$epoch, $fingerprint] = $this->configured();
        $query = DB::table('direct_source_authority_state')->where('id', 1);
        $state = DB::transactionLevel() > 0 ? $query->lockForUpdate()->first() : $query->first();
        abort_unless($state && $state->epoch !== null && (int) $state->epoch === $epoch, 409,
            'Direct source authority is not provisioned for this epoch.');
        $latest = DB::table('direct_source_authority_epochs')->max('epoch');
        $row = DB::table('direct_source_authority_epochs')->where('epoch', $epoch)->first();
        abort_unless($row && (int) $latest === $epoch && hash_equals($row->authority_fingerprint, $fingerprint)
            && $this->configured() === [$epoch, $fingerprint], 409, 'Direct source authority changed; reconcile before inspection.');

        return [$epoch, $fingerprint];
    }
}
