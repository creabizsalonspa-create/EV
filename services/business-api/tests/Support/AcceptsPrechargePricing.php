<?php

namespace Tests\Support;

trait AcceptsPrechargePricing
{
    /** Exercise the owner's actual explicit review/accept API before a new start. */
    protected function acceptPrechargePrice(int $reservation, int $energyWh = 10000): int
    {
        $quote = $this->postJson('/api/reservations/'.$reservation.'/price-estimate', ['energy_wh' => $energyWh])->assertOk()->json();

        return (int) $this->postJson('/api/reservations/'.$reservation.'/price-consent', ['quote_token' => $quote['quote_token'], 'accepted' => true])->assertOk()->json('id');
    }
}
