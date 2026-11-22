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
     * opaque customer start token or recognized CSMS reply credential fields.
     * Model accessors keep the original stored values for the dispatcher.
     */
    public function attributesToArray(): array
    {
        // Public projections never need operational credential decryption.
        // Parent serialization still handles metadata casts/visibility/dates;
        // temporarily exclude these two accessor-backed secret containers.
        $visible = $this->getArrayableAttributes();
        $hidden = $this->hidden;
        $this->hidden = array_unique([...$hidden, 'payload', 'confirmation']);
        try {
            $out = parent::attributesToArray();
        } finally {
            $this->hidden = $hidden;
        }
        foreach (['payload', 'confirmation'] as $field) {
            if (array_key_exists($field, $visible)) {
                // Malformed JSON is unavailable, never raw error/secret text.
                $value = is_string($visible[$field]) ? json_decode($visible[$field], true) : null;
                $out[$field] = is_array($value) ? self::redactSecrets($value) : $value;
            }
        }

        return $out;
    }

    private static function redactSecrets(array $data): array
    {
        foreach ($data as $key => $value) {
            if (CommandSecretFields::secretKey($key) || CommandSecretFields::credentialUri($value) || (is_array($value) && array_key_exists('__creabiz_command_secret', $value))) {
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
