<?php

namespace App\Services\Notifications;

use App\Mail\GenericInboxNotice;
use App\Models\InboxNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** Generic, separately consented handoff. Never claims confirmed delivery or replays a claimed send. */
final class EmailNotices
{
    public function configuration(): ?array
    {
        if (! config('notification_email.enabled') || ! in_array(DB::getDriverName(), ['sqlite', 'pgsql'], true)) {
            return null;
        }
        $mailer = config('mail.default');
        $local = app()->environment(['local', 'testing']);
        if (($local && $mailer !== 'array') || (! $local && $mailer !== 'smtp')) {
            return null;
        }
        $origin = rtrim((string) config('app.url'), '/');
        $url = parse_url($origin);
        $from = (string) config('mail.from.address');
        if (! $url || ! filter_var($from, FILTER_VALIDATE_EMAIL) || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])) {
            return null;
        }
        if ($local) {
            if (! in_array($origin, ['http://127.0.0.1:8000', 'http://localhost', 'https://platform.example'], true)) {
                return null;
            }
        } elseif (($url['scheme'] ?? null) !== 'https' || ($url['port'] ?? 443) !== 443 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $url['host'] ?? '') || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test|example)$/D', $url['host'])) {
            return null;
        }
        $transport = ['transport' => 'array'];
        if (! $local) {
            try {
                $transport = (new ConfigurationUrlParser)->parseConfiguration((array) config('mail.mailers.smtp'));
            } catch (Throwable) {
                return null;
            }
            if (isset($transport['driver']) && ! in_array($transport['driver'], ['smtp', 'smtps'], true)) {
                return null;
            }
            if (($transport['driver'] ?? null) === 'smtps') {
                $transport['scheme'] = 'smtps';
            }
            unset($transport['url'], $transport['driver']);
            $transport = array_replace($transport, ['transport' => 'smtp', 'timeout' => 15, 'require_tls' => true, 'verify_peer' => true, 'auto_tls' => true]);
        }
        if (! $local && (empty($transport['host']) || ! in_array((int) ($transport['port'] ?? 0), [25, 465, 587, 2525], true))) {
            return null;
        }
        $hash = hash('sha256', json_encode([$origin, $from, $mailer, $transport['host'] ?? null, $transport['port'] ?? null], JSON_THROW_ON_ERROR));

        return ['origin' => $origin, 'transport' => $transport, 'hash' => $hash, 'evidence' => $local ? 'SIMULATED_EMAIL_HANDOFF' : 'SMTP_HANDOFF_UNVERIFIED'];
    }

    public function actor(User $snapshot): User
    {
        $current = User::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        abort_unless($current->account_status === 'active' && $current->role === $snapshot->role
            && (int) $current->auth_epoch === (int) $snapshot->auth_epoch && hash_equals((string) $current->password, (string) $snapshot->password), 409, 'Account credentials changed. Sign in again.');

        return $current;
    }

    private function eligible(User $user, ?object $preference, ?array $configuration): bool
    {
        return $configuration !== null && $preference !== null && $preference->enabled
            && $user->account_status === 'active' && $user->hasVerifiedEmail() && filter_var($user->email, FILTER_VALIDATE_EMAIL)
            && (int) $user->auth_epoch === (int) $preference->consent_epoch && $user->role === $preference->consent_role
            && hash_equals((string) $preference->recipient_hash, hash('sha256', $user->email))
            && hash_equals((string) $preference->configuration_hash, $configuration['hash']);
    }

    public function status(User $user): array
    {
        $row = DB::table('notification_email_preferences')->where('user_id', $user->id)->first();
        $configuration = $this->configuration();

        return ['enabled' => (bool) ($row->enabled ?? false), 'revision' => (string) ($row->revision ?? 0),
            'configured' => $configuration !== null, 'consent_current' => $this->eligible($user, $row, $configuration),
            'verified_email_required' => ! $user->hasVerifiedEmail(), 'new_notifications_only' => true, 'generic_content_only' => true, 'delivery_confirmed' => false, 'digest_interval_minutes' => 15, 'maximum_age_hours' => 24];
    }

    public function preference(User $snapshot, bool $enabled, int $expected): array
    {
        return DB::transaction(function () use ($snapshot, $enabled, $expected) {
            $user = $this->actor($snapshot);
            DB::table('notification_email_preferences')->insertOrIgnore(['user_id' => $user->id, 'enabled' => false, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('notification_email_preferences')->where('user_id', $user->id)->lockForUpdate()->first();
            $configuration = $this->configuration();
            if ($enabled) {
                abort_unless($configuration !== null, 409, 'Email handoff is not configured.');
                abort_unless($user->hasVerifiedEmail() && filter_var($user->email, FILTER_VALIDATE_EMAIL), 422, 'Verify your account email before enabling notices.');
                if ($this->eligible($user, $row, $configuration)) {
                    return $this->status($user);
                }
                abort_unless((int) $row->revision === $expected, 409, 'Email preferences changed. Refresh and review them.');
            } elseif (! $row->enabled) {
                return $this->status($user);
            }
            abort_if((int) $row->revision >= PHP_INT_MAX, 409);
            $values = ['enabled' => $enabled, 'revision' => (int) $row->revision + 1, 'updated_at' => now()];
            if ($enabled) {
                $values += ['consent_epoch' => $user->auth_epoch, 'consent_role' => $user->role, 'recipient_hash' => hash('sha256', $user->email), 'configuration_hash' => $configuration['hash'], 'disabled_reason' => null,
                    'after_notification_id' => DB::table('inbox_notifications')->max('id') ?? 0, 'consented_at' => now()];
            }
            DB::table('notification_email_preferences')->where('user_id', $user->id)->update($values);
            if (! $enabled) {
                DB::table('notification_email_outbox')->where('user_id', $user->id)->where('state', 'queued')->update(['state' => 'cancelled', 'failure_code' => 'user_opted_out', 'completed_at' => now(), 'updated_at' => now()]);
            }
            DB::table('audit_events')->insert(['actor_id' => $user->id, 'action' => $enabled ? 'notifications.email_opt_in' : 'notifications.email_opt_out', 'subject' => (string) $user->id, 'created_at' => now()]);

            return $this->status($user);
        }, 3);
    }

    public function prepare(int $maximum = 100): int
    {
        $configuration = $this->configuration();
        if (! $configuration) {
            return 0;
        }
        $candidates = DB::table('inbox_notifications as n')->join('notification_email_preferences as p', 'p.user_id', '=', 'n.user_id')
            ->where('p.enabled', true)->whereColumn('n.id', '>', 'p.after_notification_id')->whereNull('n.read_at')->where('n.created_at', '>=', now()->subDay())->where('n.created_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('p.last_attempted_at')->orWhere('p.last_attempted_at', '<=', now()->subMinutes(15)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('notification_email_outbox as o')->whereColumn('o.user_id', 'p.user_id')->where('o.state', 'queued'))
            ->groupBy('n.user_id')->orderByRaw('MIN(n.id)')->limit(min(100, max(1, $maximum)))->get(['n.user_id', DB::raw('MAX(n.id) as through_id')]);
        $count = 0;
        foreach ($candidates as $candidate) {
            $count += DB::transaction(function () use ($candidate, $configuration) {
                $user = User::whereKey($candidate->user_id)->lockForUpdate()->first();
                $preference = DB::table('notification_email_preferences')->where('user_id', $candidate->user_id)->lockForUpdate()->first();
                if (! $user || ! $this->eligible($user, $preference, $configuration)) {
                    if ($preference && $preference->enabled) {
                        DB::table('notification_email_preferences')->where('user_id', $candidate->user_id)->update(['enabled' => false, 'disabled_reason' => 'consent_invalidated',
                            'revision' => (int) $preference->revision < PHP_INT_MAX ? (int) $preference->revision + 1 : $preference->revision, 'updated_at' => now()]);
                    }

                    return 0;
                }
                if (($preference->last_attempted_at && CarbonImmutable::parse($preference->last_attempted_at)->gt(now()->subMinutes(15)))
                    || DB::table('notification_email_outbox')->where('user_id', $user->id)->where('state', 'queued')->exists()) {
                    return 0;
                }
                $notice = InboxNotification::visibleTo($user)->where('id', '>', $preference->after_notification_id)->where('created_at', '>=', now()->subDay())->where('created_at', '<=', now())->whereNull('read_at')->orderByDesc('id')->first();
                if (! $notice) {
                    DB::table('notification_email_preferences')->where('user_id', $user->id)->update(['after_notification_id' => max((int) $preference->after_notification_id, (int) $candidate->through_id), 'updated_at' => now()]);

                    return 0;
                }

                return DB::table('notification_email_outbox')->insertOrIgnore(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'after_notification_id' => $preference->after_notification_id, 'through_notification_id' => $notice->id,
                    'consent_revision' => $preference->revision, 'recipient_hash' => $preference->recipient_hash, 'configuration_hash' => $configuration['hash'], 'state' => 'queued', 'evidence' => $configuration['evidence'], 'created_at' => now(), 'updated_at' => now()]);
            }, 3);
        }

        return $count;
    }

    public function deliver(string $id): string
    {
        $row = DB::table('notification_email_outbox')->where('id', $id)->first();
        if (! $row || $row->state !== 'queued' || ! $this->configuration()) {
            return 'not_attempted';
        }
        $claim = DB::transaction(function () use ($row) {
            $user = User::whereKey($row->user_id)->lockForUpdate()->firstOrFail();
            $preference = DB::table('notification_email_preferences')->where('user_id', $row->user_id)->lockForUpdate()->first();
            $current = DB::table('notification_email_outbox')->where('id', $row->id)->lockForUpdate()->firstOrFail();
            if ($current->state !== 'queued') {
                return 'not_attempted';
            }
            $configuration = $this->configuration();
            if (! $this->eligible($user, $preference, $configuration) || (int) $current->consent_revision !== (int) $preference->revision) {
                DB::table('notification_email_outbox')->where('id', $row->id)->update(['state' => 'cancelled', 'failure_code' => 'consent_or_visibility_changed', 'completed_at' => now(), 'updated_at' => now()]);

                return 'cancelled';
            }
            if ($preference->last_attempted_at && CarbonImmutable::parse($preference->last_attempted_at)->gt(now()->subMinutes(15))) {
                return 'not_attempted';
            }
            DB::table('notification_email_outbox')->where('id', $row->id)->update(['state' => 'claimed', 'claimed_at' => now(), 'updated_at' => now()]);
            DB::table('notification_email_preferences')->where('user_id', $user->id)->update(['after_notification_id' => max((int) $preference->after_notification_id, (int) $current->through_notification_id), 'last_attempted_at' => now(), 'updated_at' => now()]);

            return 'claimed';
        }, 3);
        if ($claim !== 'claimed') {
            return $claim;
        }
        try {
            // No transaction retries around SMTP. A crash or lost acknowledgment
            // leaves a claimed/unknown receipt for review, never another send.
            return DB::transaction(function () use ($row) {
                $user = User::whereKey($row->user_id)->lockForUpdate()->firstOrFail();
                $preference = DB::table('notification_email_preferences')->where('user_id', $row->user_id)->lockForUpdate()->first();
                $current = DB::table('notification_email_outbox')->where('id', $row->id)->lockForUpdate()->firstOrFail();
                $configuration = $this->configuration();
                $notice = InboxNotification::visibleTo($user)->where('id', '>', $current->after_notification_id)->where('id', '<=', $current->through_notification_id)->where('created_at', '>=', now()->subDay())->where('created_at', '<=', now())->whereNull('read_at')->exists();
                if ($current->state !== 'claimed' || ! $this->eligible($user, $preference, $configuration) || ! $notice
                    || (int) $current->consent_revision !== (int) $preference->revision || ! hash_equals($current->recipient_hash, hash('sha256', $user->email)) || ! hash_equals($current->configuration_hash, $configuration['hash'])) {
                    DB::table('notification_email_outbox')->where('id', $row->id)->update(['state' => 'cancelled', 'failure_code' => 'consent_or_visibility_changed', 'completed_at' => now(), 'updated_at' => now()]);

                    return 'cancelled';
                }
                config(['mail.mailers.notification-email' => $configuration['transport']]);
                Mail::purge('notification-email');
                Mail::mailer('notification-email')->to($user->email)->send(new GenericInboxNotice($user->locale === 'ar' ? 'ar' : 'en', $configuration['origin'], $row->id));
                DB::table('notification_email_outbox')->where('id', $row->id)->update(['state' => 'handed_off', 'completed_at' => now(), 'updated_at' => now()]);

                return 'handed_off';
            });
        } catch (Throwable) {
            DB::table('notification_email_outbox')->where('id', $row->id)->where('state', 'claimed')->update(['state' => 'outcome_unknown', 'failure_code' => 'transport_outcome_unknown', 'completed_at' => now(), 'updated_at' => now()]);

            return 'outcome_unknown';
        }
    }

    public function run(int $maximum = 100): array
    {
        $maximum = min(100, max(1, $maximum));
        if (! $this->configuration()) {
            return ['prepared' => 0, 'handed_off' => 0, 'cancelled' => 0, 'outcome_unknown' => 0, 'not_attempted' => 0, 'delivery_confirmed' => false];
        }
        $prepared = $this->prepare($maximum);
        $states = ['prepared' => $prepared, 'handed_off' => 0, 'cancelled' => 0, 'outcome_unknown' => 0, 'not_attempted' => 0];
        $ids = DB::table('notification_email_outbox')->where('state', 'queued')->orderBy('created_at')->orderBy('id')->limit($maximum)->pluck('id');
        foreach ($ids as $id) {
            $states[$this->deliver($id)]++;
        }

        return $states + ['delivery_confirmed' => false];
    }
}
