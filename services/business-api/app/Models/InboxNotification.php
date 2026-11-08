<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InboxNotification extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['metadata' => 'array'];

    protected $attributes = ['read_at' => null];

    /**
     * Both opening and closure escalation notices contain cross-company
     * operator identifiers; access
     * depends on the recipient's CURRENT role, not just who received them.
     * This single scope also constrains counters and read/unread mutations.
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        // Use source-owned, static JSON paths for the two supported database
        // engines. Compare as TEXT, avoiding casts of untrusted JSON numbers
        // that could throw on malformed historical metadata in PostgreSQL.
        $driver = $query->getConnection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            abort(503, 'Operational inbox database driver is not verified.');
        }
        $field = static function (string $key) use ($driver): string {
            if ($driver === 'pgsql') {
                return "inbox_notifications.metadata->>'{$key}'";
            }

            // SQLite JSON columns are stored as text. A truncated historical
            // payload must fail closed for this row, not break the entire inbox.
            // CASE guards json_extract itself, avoiding "malformed JSON" errors.
            return 'CASE WHEN json_valid(inbox_notifications.metadata) '
                ."THEN CAST(json_extract(inbox_notifications.metadata, '$.{$key}') AS TEXT) "
                .'ELSE NULL END';
        };
        $station = $field('station_id');
        $company = $field('company_id');
        $charger = $field('charger_id');
        $command = $field('command_id');
        $caseId = $field('case_id');
        $simulatorKinds = [
            'charging.fault.simulated',
            'charging.fault.recovered.simulated',
            'charging.fault.reminder.simulated',
            'charging.monitor.requires_review.simulated',
            'charging.monitor.resumed.simulated',
        ];
        $caseKinds = [
            'charging.operational_case.opened',
            'charging.operational_case.review_resolved',
            'charging.operational_case.source_observed',
        ];
        $operationalKinds = array_merge($simulatorKinds, $caseKinds);

        // Role and asset ownership are checked INSIDE the statement that
        // reads/updates the inbox. A revoked vendor cannot reuse an already
        // built query to see a transferred company's station/charger IDs.
        return $query->where('user_id', $actor->id)
            ->where(function (Builder $visible): void {
                $visible->whereNotIn('kind', [
                    'charging.fault.escalated.simulated',
                    'charging.fault.escalation_closed.simulated',
                ])->orWhereExists(function ($active) {
                    $active->selectRaw('1')->from('users as active_admin')
                        ->whereColumn('active_admin.id', 'inbox_notifications.user_id')
                        ->where('active_admin.role', 'admin');
                });
            })
            ->where(function (Builder $visible) use (
                $actor, $simulatorKinds, $caseKinds, $operationalKinds,
                $station, $company, $charger, $command, $caseId
            ): void {
                $visible->whereNotIn('kind', $operationalKinds)
                    // Preserve text-only historical notices that predate
                    // structured metadata; they contain no asset references.
                    ->orWhereNull('metadata')
                    ->orWhere(function (Builder $fault) use (
                        $actor, $simulatorKinds, $station, $company, $charger
                    ): void {
                        $fault->whereIn('kind', $simulatorKinds)
                            ->whereExists(function ($current) use (
                                $actor, $station, $company, $charger
                            ): void {
                                $current->selectRaw('1')
                                    ->from('vendor_companies as visible_company')
                                    ->join('users as visible_vendor', 'visible_vendor.id',
                                        '=', 'visible_company.owner_id')
                                    ->join('vendor_sites as visible_site',
                                        'visible_site.company_id', '=', 'visible_company.id')
                                    ->join('stations as visible_station',
                                        'visible_station.id', '=', 'visible_site.station_id')
                                    ->join('vendor_chargers as visible_charger', function ($join): void {
                                        $join->on('visible_charger.company_id', '=', 'visible_company.id')
                                            ->on('visible_charger.site_id', '=', 'visible_site.id');
                                    })
                                    ->where('visible_company.owner_id', $actor->id)
                                    ->where('visible_vendor.role', 'vendor')
                                    ->where('visible_station.vendor_id', $actor->id)
                                    ->whereRaw("CAST(visible_station.id AS TEXT) = {$station}")
                                    ->whereRaw("CAST(visible_company.id AS TEXT) = {$company}")
                                    ->whereRaw("CAST(visible_charger.id AS TEXT) = {$charger}");
                            });
                    })
                    ->orWhere(function (Builder $case) use (
                        $actor, $caseKinds, $station, $command, $caseId
                    ): void {
                        $case->whereIn('kind', $caseKinds)
                            ->whereExists(function ($current) use (
                                $actor, $station, $command, $caseId
                            ): void {
                                $current->selectRaw('1')
                                    ->from('stations as visible_station')
                                    ->join('users as visible_vendor',
                                        'visible_vendor.id', '=', 'visible_station.vendor_id')
                                    ->join('charger_commands as visible_command',
                                        'visible_command.station_id', '=', 'visible_station.id')
                                    ->join('charging_operational_cases as visible_case',
                                        'visible_case.command_id', '=', 'visible_command.id')
                                    ->where('visible_vendor.role', 'vendor')
                                    ->where('visible_station.vendor_id', $actor->id)
                                    ->whereRaw("CAST(visible_station.id AS TEXT) = {$station}")
                                    ->whereRaw("CAST(visible_command.id AS TEXT) = {$command}")
                                    ->whereRaw("CAST(visible_case.id AS TEXT) = {$caseId}");
                            });
                    });
            });
    }
}
