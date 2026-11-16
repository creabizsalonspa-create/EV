<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargerCommand extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['consent_auth_epoch'];

    protected $attributes = ['status' => 'pending'];

    /**
     * The canonical command must retain its encrypted-at-rest transport input
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
            $normalized = is_string($key) ? strtolower(str_replace(['_', '-'], '', $key)) : '';
            if (in_array($normalized, [
                'idtoken', 'accesstoken', 'refreshtoken', 'authorization',
                'secret', 'clientsecret', 'apikey', 'privatekey', 'password',
                'xhasuraadminsecret', 'signature',
            ], true)) {
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
