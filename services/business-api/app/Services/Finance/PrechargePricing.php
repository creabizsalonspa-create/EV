<?php

namespace App\Services\Finance;

use App\Models\Finance\TariffVersion;
use App\Models\Reservation;
use App\Models\Station;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/** Consent to the canonical simulator tariff. No ledger, payment or charger mutation. */
final class PrechargePricing
{
    public function canonical(Station $station, ?TariffVersion $tariff): array
    {
        abort_unless($station->source === 'simulator' && ! app()->environment('production'), 409, 'Only explicitly simulated pre-charge pricing is supported.');
        $region = DB::table('regions')->where('country', $station->country)->first();
        $precision = (int) ($tariff?->minor_units ?? $region?->minor_units ?? 2);
        abort_unless($precision >= 0 && $precision <= 3 && ($tariff === null || $tariff->currency === $station->currency), 409, 'Pricing currency or precision requires reconciliation.');

        abort_unless($tariff === null || ($tariff->station_id === $station->id && $tariff->rate_minor_per_kwh >= 0 && $tariff->rate_minor_per_kwh <= 1000000 && $tariff->tax_bps >= 0 && $tariff->tax_bps <= 10000 && $tariff->commission_bps >= 0 && $tariff->commission_bps <= 10000), 409, 'Tariff requires reconciliation.');

        return ['station_id' => $station->id, 'country' => $station->country, 'currency' => $station->currency,
            'tariff_version_id' => $tariff?->id, 'minor_units' => $precision,
            'rate_minor_per_kwh' => (int) ($tariff?->rate_minor_per_kwh ?? 100),
            'tax_bps' => (int) ($tariff?->tax_bps ?? 0), 'commission_bps' => (int) ($tariff?->commission_bps ?? 0)];
    }

    private function fingerprint(array $price): string
    {
        return hash('sha256', json_encode($price, JSON_THROW_ON_ERROR));
    }

    private function actor(Reservation $reservation, int $actor): User
    {
        $user = User::whereKey($actor)->lockForUpdate()->firstOrFail();
        abort_unless($reservation->user_id === $actor && $user->role === 'customer' && $user->account_status === 'active', 404);

        return $user;
    }

    public function preview(Reservation $reservation, int $actor, int $energyWh): array
    {
        $user = $this->actor($reservation, $actor);
        abort_unless($reservation->status === 'confirmed', 409, 'Reservation is not confirmed.');
        abort_unless($energyWh >= 1 && $energyWh <= 1000000, 422, 'Estimate energy must be between 1 Wh and 1000 kWh.');
        $station = Station::findOrFail($reservation->station_id);
        $tariff = app(FinanceService::class)->activeTariff($station);
        $price = $this->canonical($station, $tariff);
        $subtotal = Money::multiplyDivide($energyWh, $price['rate_minor_per_kwh'], 1000);
        $tax = Money::multiplyDivide($subtotal, $price['tax_bps'], 10000);
        $estimate = ['version' => 1, 'evidence' => 'SIMULATED', 'currency' => $price['currency'], 'minor_units' => $price['minor_units'],
            'tariff_version_id' => $price['tariff_version_id'], 'rate_minor_per_kwh' => (string) $price['rate_minor_per_kwh'], 'tax_bps' => $price['tax_bps'],
            'energy_wh' => $energyWh, 'subtotal_minor' => (string) $subtotal, 'tax_minor' => (string) $tax, 'total_minor' => (string) ($subtotal + $tax),
            'session_fee_minor' => '0', 'idle_fee_minor' => '0', 'external_fees_included' => false, 'automatic_stop' => false,
            'final_total_depends_on_meter' => true, 'expires_at' => now()->startOfSecond()->addMinutes(5)->toISOString()];
        $body = ['reservation_id' => $reservation->id, 'user_id' => $actor, 'auth_epoch' => (int) $user->auth_epoch,
            'nonce' => (string) Str::uuid(), 'price_fingerprint' => $this->fingerprint($price), 'estimate' => $estimate];

        return ['estimate' => $estimate, 'quote_token' => Crypt::encryptString(json_encode($body, JSON_THROW_ON_ERROR))];
    }

    public function accept(Reservation $reservation, int $actor, string $token): array
    {
        $user = $this->actor($reservation, $actor);
        try {
            $body = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $e) {
            abort(422, 'Invalid pricing confirmation.');
        }
        abort_unless(is_array($body) && is_array($body['estimate'] ?? null) && ($body['estimate']['version'] ?? null) === 1 && ($body['estimate']['evidence'] ?? null) === 'SIMULATED' && is_string($body['estimate']['expires_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $body['estimate']['expires_at']), 422, 'Invalid pricing confirmation.');
        abort_unless(($body['reservation_id'] ?? null) === $reservation->id && ($body['user_id'] ?? null) === $actor && ($body['auth_epoch'] ?? null) === (int) $user->auth_epoch, 409, 'Pricing confirmation no longer matches the current account and reservation.');
        $expires = CarbonImmutable::parse($body['estimate']['expires_at'], 'UTC');
        $old = DB::table('charging_price_consents')->where('token_digest', hash('sha256', $token))->first();
        if ($old) {
            return ['id' => (int) $old->id, 'accepted_at' => $old->accepted_at, 'valid_until' => $old->valid_until];
        }
        abort_unless($reservation->status === 'confirmed' && now()->lt($expires), 409, 'Pricing confirmation expired; review a fresh estimate.');
        $station = Station::findOrFail($reservation->station_id);
        $price = $this->canonical($station, app(FinanceService::class)->activeTariff($station));
        abort_unless(hash_equals($this->fingerprint($price), $body['price_fingerprint'] ?? ''), 409, 'Tariff changed; review a fresh estimate.');
        $id = DB::table('charging_price_consents')->insertGetId(['reservation_id' => $reservation->id, 'user_id' => $actor, 'auth_epoch' => $user->auth_epoch,
            'token_digest' => hash('sha256', $token), 'price_fingerprint' => $body['price_fingerprint'], 'estimate' => json_encode($body['estimate'], JSON_THROW_ON_ERROR),
            'accepted_at' => now(), 'valid_until' => $expires]);
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => 'charging.price_accepted', 'subject' => (string) $id, 'created_at' => now()]);

        return ['id' => $id, 'accepted_at' => now()->toISOString(), 'valid_until' => $body['estimate']['expires_at']];
    }

    /** Caller holds the same station lock used to capture its canonical session quote. */
    public function consent(Reservation $reservation, Station $station, ?TariffVersion $tariff): ?int
    {
        $user = $this->actor($reservation, $reservation->user_id);
        $record = DB::table('charging_price_consents')->where('reservation_id', $reservation->id)->where('user_id', $user->id)
            ->where('auth_epoch', $user->auth_epoch)->where('price_fingerprint', $this->fingerprint($this->canonical($station, $tariff)))
            ->where('valid_until', '>', now())->orderByDesc('id')->first();
        abort_if(! $record && ($reservation->reservation_terms_snapshot['price_acceptance_required'] ?? false), 409, 'Review and accept current charging prices before starting.');

        return $record?->id;
    }
}
