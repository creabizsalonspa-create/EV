<?php

namespace App\Services\Privacy;

use App\Models\AccountPrivacyRequest;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** A bounded, explicit personal-data copy; never a database dump or ledger mutation. */
final class AccountDataExport
{
    private int $bytes = 0;

    private function rows(Builder $query, array $columns, string $order = 'id'): array
    {
        $rows = $query->orderBy($order)->limit(2501)->get($columns)->all();
        abort_if(count($rows) > 2500, 409, 'This request needs assisted fulfillment: a section exceeds 2500 records. No partial copy was prepared.');

        foreach ($rows as $row) {
            $this->bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
            abort_if($this->bytes > 2 * 1024 * 1024, 409, 'This request needs assisted fulfillment: the copy exceeds 2 MiB. No partial copy was prepared.');
        }

        return $rows;
    }

    public function snapshot(User $owner, AccountPrivacyRequest $case): string
    {
        $this->bytes = 0;
        $id = $owner->id;
        $owned = fn ($table) => DB::table($table)->where('user_id', $id);
        $reservations = fn () => DB::table('reservations')->where('user_id', $id)->select('id');
        $invoices = fn () => DB::table('finance_invoices')->where('user_id', $id)->select('id');
        $vehicles = fn () => DB::table('vehicles')->where('user_id', $id)->select('id');
        $sections = [
            'profile' => ['id' => $id, 'name' => $owner->name, 'email' => $owner->email, 'role' => $owner->role, 'locale' => $owner->locale, 'email_verified_at' => $owner->email_verified_at, 'phone' => $owner->phone_e164, 'phone_verified_at' => $owner->phone_verified_at, 'created_at' => $owner->created_at],
            'vehicles' => $this->rows($owned('vehicles'), ['id', 'name', 'connector_type', 'created_at', 'updated_at']),
            'driver_preferences' => $this->rows(DB::table('vehicle_driver_preferences')->whereIn('vehicle_id', $vehicles()), ['vehicle_id', 'revision', 'preferred_power_kw', 'charging_goal', 'accessible_site_required', 'avoid_paid_parking', 'updated_at'], 'vehicle_id'),
            'reservations' => $this->rows($owned('reservations'), ['id', 'station_id', 'starts_at', 'status', 'created_at', 'updated_at']),
            'charging_sessions' => $this->rows(DB::table('charging_sessions')->whereIn('reservation_id', $reservations()), ['id', 'reservation_id', 'status', 'energy_wh', 'currency', 'total_minor', 'stopped_at', 'created_at']),
            'invoices' => $this->rows($owned('finance_invoices'), ['id', 'number', 'charging_session_id', 'currency', 'minor_units', 'subtotal_minor', 'tax_minor', 'total_minor', 'evidence', 'created_at']),
            'payments' => $this->rows(DB::table('finance_payments')->whereIn('invoice_id', $invoices()), ['id', 'invoice_id', 'amount_minor', 'created_at']),
            'refunds' => $this->rows(DB::table('finance_refunds')->whereIn('invoice_id', $invoices()), ['id', 'invoice_id', 'amount_minor', 'created_at']),
            'station_favorites' => $this->rows($owned('station_favorites'), ['station_id', 'created_at'], 'station_id'),
            'partner_favorites' => $this->rows($owned('partner_location_favorites'), ['record_id', 'created_at'], 'record_id'),
            'support_messages' => $this->rows(DB::table('ticket_messages')->where('author_id', $id)->where('internal', false)->whereIn('support_ticket_id', $owned('support_tickets')->select('id')), ['id', 'support_ticket_id', 'body', 'created_at']),
            'support_tickets' => $this->rows($owned('support_tickets'), ['id', 'subject', 'status', 'created_at', 'updated_at']),
            'manual_battery_reports' => $this->rows(DB::table('vehicle_manual_soc_reports')->whereIn('vehicle_id', $vehicles()), ['id', 'vehicle_id', 'battery_percent', 'reported_at', 'created_at']),
            'loyalty_entries' => $this->rows($owned('loyalty_ledger'), ['id', 'kind', 'points', 'created_at']),
            'subscriptions' => $this->rows($owned('commercial_subscriptions'), ['id', 'plan_id', 'status', 'period_start', 'period_end', 'cancel_at_period_end', 'created_at']),
            'notification_preferences' => $this->rows($owned('notification_preferences'), ['user_id', 'default_category', 'default_unread_only', 'remind_unresolved_simulator_faults', 'updated_at'], 'user_id'),
        ];
        $sections['privacy_requests'] = $this->rows($owned('account_privacy_requests'), ['id', 'kind', 'status', 'request_details_encrypted', 'created_at']);
        foreach ($sections['privacy_requests'] as $record) {
            $record->details = Crypt::decryptString($record->request_details_encrypted);
            unset($record->request_details_encrypted);
        }
        // Decimal strings keep minor-unit amounts exact in browser JSON parsers.
        foreach ($sections as $name => $rows) {
            if ($name === 'profile') {
                continue;
            }
            foreach ($rows as $row) {
                foreach (get_object_vars($row) as $key => $value) {
                    if ($value !== null && (str_ends_with($key, '_minor') || in_array($key, ['energy_wh', 'points'], true))) {
                        $row->{$key} = (string) $value;
                    }
                }
            }
        }
        $body = json_encode([
            'schema_version' => 1, 'request_id' => $case->id, 'owner_id' => $id, 'generated_at' => now()->toIso8601String(),
            'scope' => 'self_service_account_copy',
            'notice' => 'Personal account copy. Financial amounts are decimal strings of integer minor units; invoice currency and precision apply. This is not a payment or legal invoice. Retained source, financial and audit records are not deleted.',
            'excluded' => ['Passwords, session cookies, bearer tokens, MFA secrets and recovery codes', 'Other people, organization/vendor records, private reviewer notes and source credentials', 'Private evidence files, provider payloads and internal operational/security records require separate assisted review'],
            'sections' => $sections,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        abort_if(strlen($body) > 2 * 1024 * 1024, 409, 'This request needs assisted fulfillment: the copy exceeds 2 MiB. No partial copy was prepared.');

        return $body;
    }

    public function metadata(int $case): ?array
    {
        $record = DB::table('account_privacy_exports')->where('privacy_request_id', $case)->first(['id', 'expires_at', 'purged_at', 'revoked_at', 'byte_length', 'created_at']);
        if (! $record) {
            return null;
        }

        return ['id' => (int) $record->id, 'status' => $record->revoked_at ? 'revoked' : ($record->purged_at || $record->expires_at <= now()->toDateTimeString() ? 'expired' : 'available'), 'expires_at' => $record->expires_at, 'byte_length' => (int) $record->byte_length, 'created_at' => $record->created_at];
    }

    public function purge(): int
    {
        return DB::table('account_privacy_exports')->where('expires_at', '<=', now())->whereNull('purged_at')
            ->update(['payload_encrypted' => null, 'purged_at' => now()]);
    }
}
