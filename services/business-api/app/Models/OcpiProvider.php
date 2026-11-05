<?php

namespace App\Models;

use App\Services\Ocpi\PartnerClient;
use Illuminate\Database\Eloquent\Model;

class OcpiProvider extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['readable_modules', 'commands_enabled', 'billing_eligible'];

    public function getReadableModulesAttribute(): array
    {
        return $this->authorized && $this->version === '2.2.1' ? array_values(array_filter(PartnerClient::MODULES, fn ($m) => is_string($this->endpoints[$m] ?? null))) : [];
    }

    public function getCommandsEnabledAttribute(): bool
    {
        return false;
    }

    public function getBillingEligibleAttribute(): bool
    {
        return false;
    }

    protected $hidden = ['connection_key', 'endpoints', 'authorization_revision'];

    protected $attributes = ['authorized' => false, 'evidence' => 'UNVERIFIED', 'authorization_revision' => 0];

    protected function casts(): array
    {
        return ['authorization_revision' => 'integer', 'authorized' => 'boolean', 'endpoints' => 'array', 'negotiated_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }
}
