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

## Idempotent fleet notification lifecycle

Fleet invitations now produce an inbox notification only when a registered, different eligible account receives a new or explicitly re-issued invitation. Existing invitations and active memberships are not duplicated. A removed membership can be re-invited by the current verified fleet owner through a lock-protected transition; the API still always returns the same generic response for unregistered/eligible addresses. Accepting a fleet invitation creates one owner notification (not again on idempotent acceptance). Revoking a membership creates one member notice and clears attached vehicle authorizations; repeated revocation is a no-op. All notification and membership state changes commit in the same transaction. EN/AR web/Flutter inbox categories and labels cover invited, accepted and revoked. No email/push delivery, account enumeration or cross-tenant browsing is introduced.

### Lock ordering and resilient client labels

The fleet membership revocation path now acquires the fleet row before the membership row (matching invitation creation) and retries transient transaction failures up to three times. This reduces deadlock risk on concurrent owner re-invitations/revocations. The Flutter inbox uses a single typed title formatter with a validated positive numeric station/vehicle/fleet identifier. Malformed/missing metadata produces a harmless placeholder instead of raising a widget build exception; localized English/Arabic wording remains explicitly simulator-scoped.

### Web inbox pagination

The browser now reads backend page metadata and renders bounded previous/next controls with EN/AR labels. The page state is identity-scoped, and changing unread/category filters or completing a bulk-read resets to the first page. The existing Laravel pagination limit stays at 25; backend regression tests verify 27 own records across two pages, descending order, and non-disclosure of an unrelated account's rows. No API returns an unbounded inbox dump.

### Bounded UI refresh behavior

Account initialization loads the existing support tickets and then the inbox, while **inbox-only** filter, category, page and read actions now call `refreshInbox()` rather than refetching support tickets. The two read-only inbox requests (page and global unread summary) are issued concurrently and the result is discarded if the account/session epoch changed. This reduces repeated support requests and preserves existing ticket reply/transition flows.

## Actionable fleet notifications (no automatic consent)

The browser inbox now provides a **Review fleet invitation** action that refreshes the existing authenticated fleet invitation list and scrolls to the current actionable invitations. A historical or revoked notification cannot directly accept an invitation. The Flutter SupportView accepts the currently authenticated account ID and can open the existing FleetView on its Invitations tab; this reuses FleetView's explicit confirmation-before-accept path. In both surfaces, tapping the inbox notification itself causes **no membership mutation** and does not bypass backend ownership checks. EN/AR labels and a native navigation fixture were added.

### Same-snapshot high-water summary

The unread count and maximum observed notification ID are now derived from one aggregate SQL statement (portable conditional SUM and MAX), rather than two independently timed SELECT queries. This prevents a concurrent insert between separate reads from producing an internally inconsistent count/cutoff pair. The count is still per account, and the ID remains an observed database high-water mark, not proof that the user saw or received every item. Tests verify zero-unread-with-history and later-arrival behavior. The native fleet review regression also asserts that navigating from the notification performs no invitation acceptance POST.

### In-account inbox request ordering

In addition to the session epoch guard, the browser inbox now increments an `inboxGeneration` counter at the start of each refresh, and again when private views are cleared. A previous request (including one for a different page/category with the same account) is discarded if it finishes after a more recent request. This prevents a delayed older response from replacing the newest category/filter view. Requests still use existing `EvApi` session fencing and do not mutate notifications merely by refreshing.
