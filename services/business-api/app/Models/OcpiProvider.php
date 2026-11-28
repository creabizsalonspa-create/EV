<?php

namespace App\Models;

use App\Services\Ocpi\ContractAuthorizationGate;
use App\Services\Ocpi\PartnerClient;
use App\Services\Ocpi\PartnerReadProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OcpiProvider extends Model
{
    protected $guarded = ['id'];

    protected $appends = ['readable_modules', 'commands_enabled', 'billing_eligible'];

    /** Published OCPI records lose public visibility when their vendor is disabled. */
    public function scopePubliclyDiscoverable(Builder $query): Builder
    {
        return ContractAuthorizationGate::constrainPublic($query->where('authorized', true)
            ->whereIn('owner_id', User::query()->where('role', 'vendor')
                ->where('account_status', 'active')->select('id')));
    }

    public function getReadableModulesAttribute(): array
    {
        return $this->authorized && $this->version === '2.2.1' ? array_values(array_filter(PartnerClient::MODULES, fn ($m) => PartnerReadProfile::allows(config('ocpi.connections.'.$this->connection_key), $m) && is_string($this->endpoints[$m] ?? null))) : [];
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
