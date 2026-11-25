<?php

namespace App\Services\Notifications;

/** A queued handoff must match the exact current consent and cursor before consuming either. */
final class EmailNoticeReceipt
{
    private static function unsigned(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            || (string) (int) $value !== $value) {
            return null;
        }

        return (int) $value;
    }

    public static function matches(object $receipt, object $preference, string $recipientHash, string $configurationHash, bool $claimed): bool
    {
        $user = self::unsigned($receipt->user_id ?? null);
        $revision = self::unsigned($receipt->consent_revision ?? null);
        $after = self::unsigned($receipt->after_notification_id ?? null);
        $through = self::unsigned($receipt->through_notification_id ?? null);
        $cursor = self::unsigned($preference->after_notification_id ?? null);

        return $user !== null && $user > 0 && $user === self::unsigned($preference->user_id ?? null)
            && $revision !== null && $revision > 0 && $revision === self::unsigned($preference->revision ?? null)
            && $after !== null && $through !== null && $through > $after
            && $cursor === ($claimed ? $through : $after)
            && is_string($receipt->recipient_hash ?? null) && hash_equals($recipientHash, $receipt->recipient_hash)
            && is_string($receipt->configuration_hash ?? null) && hash_equals($configurationHash, $receipt->configuration_hash);
    }
}
