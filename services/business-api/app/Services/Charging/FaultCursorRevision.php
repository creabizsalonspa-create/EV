<?php

namespace App\Services\Charging;

/** A poll's durable cursor lease also changes when no event ID advances. */
final class FaultCursorRevision
{
    public static function matches(?object $expected, ?object $current): bool
    {
        if ($expected === null || $current === null) {
            return $expected === $current;
        }
        foreach (['id', 'revision', 'binding_id', 'source_station_id', 'last_event_id', 'state', 'review_pending'] as $field) {
            if (! property_exists($expected, $field) || ! property_exists($current, $field)
                || $expected->{$field} !== $current->{$field}) {
                return false;
            }
        }

        return is_int($current->revision) && $current->revision >= 0 && $current->revision < PHP_INT_MAX;
    }

    public static function reviewPending(?object $cursor): bool
    {
        return $cursor !== null && ($cursor->state === 'requires_review' || (bool) $cursor->review_pending);
    }
}
