# Agent 3 — Account-scoped notification center

## Feature delivered

Existing account notifications remain persisted in `inbox_notifications`; no notification records are deleted, automatically acknowledged, or exposed to another actor. This extension uses existing in-app notification history and introduces:

- `GET /api/v1/notifications/summary` and the existing session-based `GET /api/notifications/summary`: return only `unread_count`, `latest_id`, `in_app_only:true` for the authenticated account.
- `POST /api/v1/notifications/read-through` and `POST /api/notifications/read-through` with `{"through_id":ID}`: update *only the caller's unread rows with IDs ≤ the observed high-water mark*. Return changed row count, cutoff and remaining unread count. Repeated cutoff calls cannot re-count already acknowledged rows. Notifications arriving with later IDs remain unread.
- Web support inbox now shows an unread count and bilingual bulk-read control. Existing per-notification read remains available.
- Canonical customer/vendor Flutter SupportView shows the same count and bulk control in EN/AR. Widget generation checks block stale UI responses after disposal/refresh.
- Successful mutations add one scoped audit event, and no audit event is inserted for an idempotent no-op.

## Evidence and boundaries

The backend feature tests cover unauthenticated access, invalid cutoff values, two-account privacy, repeat acknowledgement, late arrivals and legacy route compatibility. The Flutter widget tests cover the cutoff that came from the server and accurate count refresh. Browser session fencing uses existing `EvApi` identity epoch. All access requires existing authentication and rate limit middleware.

This feature is **in-app only**; it does not claim external push, email, SMS delivery, delivery receipts or that the user opened a notification. This is not a charging-state, transaction, payment or physical-hardware feature. The user must explicitly choose the bulk-read action; no background process silently dismisses messages.

## Verification and integration

Run `./scripts/php artisan test --filter=NotificationCenterTest`, `flutter test test/support_test.dart`, related browser/navigation tests, static lints and exact-source hosted CI before merging. These are required gates, not presumed passes. No manual changes to live backend or production app instances are included.

## Additional listing functionality

Both account-scoped notification listing routes now accept an optional validated `unread_only=1` filter. The canonical `/api/v1/notifications` listing and individual read endpoint are aliases of the existing session API, with the same authorization, pagination and throttling. The web inbox adds a persistent-for-session unread-only toggle, cleared on account identity reset. Flutter SupportView offers the same filter with the existing refresh and page controls. Invalid filter values return 422; no record is modified simply by applying a filter.

## Source-observed simulator charger faults in the owner inbox

The existing `ContinuousFaultObserver` already requires a locally enabled simulator source, tests the authentic source/binding relationship and rechecks current vendor ownership inside a locked database transaction. Its fault monitor now accepts an optional callback *only when a new durable fault alert is inserted*. The observer uses that callback to persist one vendor-owner `charging.fault.simulated` inbox record, **in the same transaction** as the observation, alert and monitor cursor. No message is sent on a replay, rejected source, changed owner, or rollback. Existing monitor callers without callbacks retain their previous behavior.

Notification metadata contains only simulator provenance, the source-evidence class, station ID, internal alert ID and explicit `physical_verification=false`. No raw OCPP source token, device secret, private message, meter data, live payment or actual hardware assurance enters the inbox. The web and Flutter inboxes show clear bilingual simulator-fault labels without inventing a support-ticket link. Updated observer tests exercise both OCPP 1.6 and 2.0.1 fixtures, replay idempotency, owner isolation and failed-batch rollback. **No production monitor is enabled or charger command sent.**

## High-volume indexing and category filtering

A follow-up additive migration creates a composite index on `inbox_notifications(user_id, read_at, id)` for tenant-scoped unread count and cursor-cutoff updates. Both account notification listing routes accept an allowlisted `category=support|vehicle|charging` parameter, filtering by the corresponding fixed `kind` namespace. No user-controlled SQL wildcard enters a query, invalid categories return HTTP 422, and the category combines with `unread_only=1` and existing 25-row pagination. Web and Flutter expose the same category selection in their inboxes. No notification gets changed solely by filtering.

## Individual acknowledgment integrity and localization

The legacy single-notification read endpoint now updates only the current user's still-unread row in an atomic transaction, writing a scoped audit event exactly once. Repeated reads return the already-acknowledged record without duplicating audit events. The unread-category picker displays localized English/Arabic labels; tests cover native category requests, isolated read receipts and combined category/unread filters in the real browser. Read acknowledgments remain **in-app state only**, not external delivery receipts.

### Source-observed recovery status (not physical repair)

The simulator observer now also emits a distinct `charging.fault.recovered.simulated` notification **only** when the durable charging fault alert obtains its first qualifying source recovery observation. The conditional DB update (previous recovery must be NULL) and notification insert occur in the same owner-locked transaction. Replayed observations, restarted monitors and later source pages do not duplicate the recovery notice. All metadata remains simulator-only with `physical_verification=false`; observing `Available` in a simulator is neither proof of actual repair nor authorization to release charging capacity. EN/AR web and Flutter labels explicitly describe *simulator source reported recovery*.
