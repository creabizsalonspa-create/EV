<?php

namespace App\Models;

use App\Services\Charging\CommandSecretFields;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ChargerCommand extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['consent_auth_epoch'];

    protected $attributes = ['status' => 'pending'];

    protected static function booted(): void
    {
        static::saving(function (self $command): void {
            // All identity fields are available here, regardless of fill order.
            $attributes = $command->getAttributes();
            foreach (['payload', 'confirmation'] as $field) {
                $attributes[$field] = CommandSecretFields::seal($attributes[$field] ?? null, $attributes, $field);
            }
            $command->setRawAttributes($attributes);
        });
    }

    protected function payload(): Attribute
    {
        return Attribute::get(fn ($value, $attributes) => CommandSecretFields::read($value, $attributes, 'payload'));
    }

    protected function confirmation(): Attribute
    {
        return Attribute::get(fn ($value, $attributes) => CommandSecretFields::read($value, $attributes, 'confirmation'));
    }

    /**
     * The canonical command must retain its original transport input
     * for reconciliation, but public vendor/Admin JSON must never expose an
     * opaque customer start token or arbitrary CSMS reply credentials.
     * Model accessors keep the original stored values for the dispatcher.
     */
    public function toArray(): array
    {
        $out = parent::toArray();
        foreach (['payload', 'confirmation'] as $field) {
            if (is_array($out[$field] ?? null)) {
                $out[$field] = self::redactSecrets($out[$field]);
            }
        }

        return $out;
    }

    private static function redactSecrets(array $data): array
    {
        foreach ($data as $key => $value) {
            if (CommandSecretFields::secretKey($key)) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redactSecrets($value);
            }
        }

        return $data;
    }

    protected function casts(): array
    {
        return ['consent_auth_epoch' => 'integer', 'payload' => 'array', 'confirmation' => 'array'];
    }
}
