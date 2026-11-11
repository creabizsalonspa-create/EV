<?php

namespace App\Services\Charging;

use App\Models\Station;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Explicit operator policy only; never evidence of live connector availability. */
final class OperatingCalendar
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Caller holds the station lock shared with information publication. */
    public function requireWindow(Station $station, CarbonInterface $start, CarbonInterface $end): ?array
    {
        $row = DB::table('station_information')->where('station_id', $station->id)->first();
        if (! $row || ! ($row->enforce_operating_hours ?? false)) {
            return null; // Existing advisory metadata never silently becomes a control.
        }
        try {
            $snapshot = [
                'revision' => (int) $row->revision,
                'timezone' => $station->timezone,
                'weekly_hours' => json_decode($row->weekly_hours, true, flags: JSON_THROW_ON_ERROR),
                'closed_dates' => json_decode($row->closed_dates, true, flags: JSON_THROW_ON_ERROR),
                'policy' => 'VENDOR_OPERATING_CALENDAR',
                'physical_availability_verified' => false,
            ];
            $allowed = $this->permits($snapshot, $start, $end);
        } catch (Throwable) {
            abort(409, 'The operating calendar is unavailable. Refresh before booking or starting.');
        }
        abort_unless($allowed, 409, 'The selected time is outside the station operating calendar.');

        return $snapshot;
    }

    public function requireStart(Station $station): void
    {
        $at = CarbonImmutable::now('UTC');
        $this->requireWindow($station, $at, $at->addMicrosecond());
    }

    /** Half-open UTC interval evaluated at each station-local minute boundary. */
    public function permits(array $snapshot, CarbonInterface $start, CarbonInterface $end): bool
    {
        $timezone = $snapshot['timezone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            return false;
        }
        $week = $snapshot['weekly_hours'] ?? null;
        $closed = $snapshot['closed_dates'] ?? null;
        if (! is_array($week) || count($week) !== 7 || ! is_array($closed) || count($closed) > 50) {
            return false;
        }
        foreach (self::DAYS as $day) {
            $hours = $week[$day] ?? null;
            if (! is_array($hours) || ! array_key_exists('open', $hours) || ! array_key_exists('close', $hours)) {
                return false;
            }
            if (($hours['open'] === null) !== ($hours['close'] === null)) {
                return false;
            }
            foreach (['open', 'close'] as $key) {
                if ($hours[$key] !== null && (! is_string($hours[$key]) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $hours[$key]))) {
                    return false;
                }
            }
        }
        foreach ($closed as $date) {
            if (! is_string($date) || ! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
                || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                return false;
            }
        }
        $cursor = CarbonImmutable::instance($start)->utc();
        $until = CarbonImmutable::instance($end)->utc();
        if (! $cursor->lt($until) || $cursor->diffInSeconds($until) > 86400) {
            return false;
        }
        // Schedules have minute precision. Walking actual UTC minutes handles
        // skipped/repeated DST wall times without inventing local timestamps.
        while ($cursor->lt($until)) {
            $local = $cursor->setTimezone($timezone);
            $date = $local->format('Y-m-d');
            if (in_array($date, $closed, true)) {
                return false;
            }
            $time = $local->format('H:i');
            $today = $week[strtolower($local->format('D'))];
            $previous = $local->subDay();
            $yesterday = $week[strtolower($previous->format('D'))];
            $open = $today['open'];
            $close = $today['close'];
            $ownDay = $open !== null && ($open === $close
                || ($open < $close ? $time >= $open && $time < $close : $time >= $open));
            $spill = ! in_array($previous->format('Y-m-d'), $closed, true)
                && $yesterday['open'] !== null && $yesterday['open'] > $yesterday['close']
                && $time < $yesterday['close'];
            if (! $ownDay && ! $spill) {
                return false;
            }
            $cursor = $cursor->addMinute()->startOfMinute();
        }

        return true;
    }
}
