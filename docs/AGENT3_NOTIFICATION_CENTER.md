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

### Independent authenticated notification traffic budget

The browser inbox no longer spends support-ticket rate-limit capacity. Session-authenticated `GET /api/notifications` and `/summary` use a dedicated **120/minute per-account notification-read** limiter; `POST /api/notifications/read-through` and individual read actions use a separate **30/minute notification-write** limiter. Support ticket reads/writes keep their existing **30/minute** allowance. The canonical `/api/v1` endpoints remain under their pre-existing `platform-api` limiter, preserving existing native API throttling. Tests issue 70 actual inbox read requests, then confirm support creation still works; another test confirms the 31st notification-write returns 429 while the read and support routes remain available.

## Explicit unread-state reversal

New account-scoped `POST /api[/v1]/notifications/{notification}/unread` reverses an earlier local read acknowledgement without recreating or resending an event. The update is atomic, idempotent and audits exactly one real transition. Both web and Flutter expose localized Mark unread controls; existing support/charging/fleet events and external delivery states do not change. Backend tests cover cross-account 404, stable record count and single audit; native widget tests cover read→unread round trips.

### Category-level unread totals

The single-query tenant-scoped summary now returns `unread_by_category` for `support`, `vehicle`, `charging`, `fleet`, and `other` using fixed SQL prefix parameters. `other` counts unrecognized event classes rather than hiding them. Category counts add up to the global unread count and cannot include foreign users. Web and Flutter show EN/AR category totals only if all category fields are valid nonnegative integers, while older clients can continue consuming the existing `unread_count`, `latest_id`, and `in_app_only` fields. Backend and native widget tests cover known categories, unknown types, another account's records, and summary presentation.

## Visibility-aware notification refresh

The web account inbox now has an explicit **Refresh notifications** control and a single 60-second read-only refresh loop. The loop is registered once per page load, runs only for an authenticated user while the tab is visible and the support pane is available, and is silent when the tab is hidden or logout is pending. Returning to a visible tab triggers a fresh read. Refreshes reuse the per-account generation and session-epoch guards and do not acknowledge, reopen, create or delete notifications. All background calls use the separate notification-read allowance, not the support ticket quota. The browser test exercises refreshing a newly received support notification without reloading the entire application.

### Additional query index and exact UI read-reversal test

The additive migration now includes `inbox_notifications(user_id,kind,id)` alongside the owner-unread index. The second index targets the allowlisted notification-category listing while preserving owner scoping and descending ID pagination. The full Playwright browser workflow now clicks the real **Mark unread** and **Mark read** controls for an identified persisted notification, asserting unchanged total records, updated per-category counts and the newer event's independent receipt. Native GitHub Actions workflow runs are now grouped by branch/head ref, canceling superseded attempts on the same branch rather than accumulating outdated iOS/Android jobs; this does not imply current CI has run successfully.

### Consistent lock ordering for fleet acceptance

The fleet acceptance transaction now acquires the owning fleet row **before** the invitation membership row, matching invitation creation and revocation. It rechecks invitation ownership and fleet membership after locking, retries transient deadlocks up to three times, and creates only one acceptance notice on a real `invited`→`active` transition. Membership revocation rechecks the locked row's fleet ID. A new feature test confirms that a revoked invitation cannot be accepted until the owner deliberately issues a new invitation, and that repeated acceptance cannot create extra owner notices.

### Vehicle-assignment authorization and fleet lock continuity

The fleet assignment path now obtains the same fleet-before-membership row locks as invitation, acceptance and revocation. The driver's active membership, vehicle ownership and cost-center fleet association are all checked **inside the locked transaction**, not only against pre-transaction snapshots. The assignment transaction also retries transient deadlocks up to three times. A new test demonstrates that a driver's own vehicle is assigned while active, is detached on revocation, and cannot be assigned again after revocation; foreign-user vehicles are rejected.

### Audited owner-visible driver assignment changes

A driver's new vehicle assignment and an actual cost-center update now generate separate owner-only in-app notifications in the same database transaction as the audit and vehicle change. An identical retry is a no-op: no additional notification or audit. Self-actions by the owner do not generate redundant owner messages. Metadata contains internal identifiers, not private charger information or payment data. Backend tests verify exactly two owner notices, separate audit actions and no unrelated-user visibility. Web and Flutter format these events in English and Arabic with safe placeholders for invalid/missing IDs.

### Accurate invitation withdrawal vs active membership revocation

Canceling an *unaccepted* fleet invitation now emits `fleet.invitation.withdrawn` and audits `fleet.invitation_withdrawn`, whereas revoking an *active* membership emits `fleet.membership.revoked`, removes associated fleet-vehicle assignments and audits `fleet.revoked`. Repeated cancellation is idempotent. The restored state remains `revoked` internally so the owner may explicitly re-invite later; neither the client nor audit trail falsely claims active fleet access existed. Web and Flutter show separate localized labels, with backend and native coverage for withdrawal/reinvite/accept/revoke.

### Privacy-sensitive response caching and cutoff input bounds

Notification list, unread/category summary, individual read/unread and bulk-read endpoints now explicitly set `Cache-Control: private, no-store` for account-specific responses. Bulk-read `through_id` is bounded to JavaScript's exact integer range (`1..9007199254740991`), rejecting larger out-of-range inputs rather than risking a PostgreSQL cast or cross-client precision error. Backend tests cover all endpoint cache headers and an oversized cutoff. These are in-app state controls only, not external delivery/read receipts.

### Source-observed fault inbox to authorized operational review

Each new source-observed simulator charger-fault/recovery notice now stores internal `company_id` and `charger_id` alongside its already-present station/alert IDs. Those IDs are obtained from an ownership-checked locked binding, not caller input, and the persisted notice remains vendor-owner scoped. On web, an explicit **Review simulated charger fault** action appears only for consistent simulator provenance and numeric IDs. It opens the existing Vendor Operations fault-alert view, whose company and charger API performs all normal access checks. If the alert is on an older page, users are directed to the pre-existing pagination. The action does not acknowledge, repair, release, authorize or send commands.

### Native source fault review navigation

Flutter's account SupportView now renders **Review simulated charger fault** only if the notification kind, simulator provenance, explicit `physical_verification=false`, and positive company/charger/alert IDs are all present. It opens the existing `ChargerDiagnosticsView` in a read-only alert-first mode, which sequentially loads the source diagnostics and fault alert list. The existing diagnostics and repair panels retain all backend ownership checks and explicit human confirmation for any later note/acknowledgment; navigation itself performs GET requests only. Tests cover malformed/forged navigation metadata and a full native fixture asserting no POST/PATCH charger actions.

### Deduplicated simulator monitor review and catch-up alerts

When the existing read-only simulator observer cannot accept a source event page, its separately lock-checked failure transaction transitions the monitor to `requires_review` and inserts one owner-only `charging.monitor.requires_review.simulated` message **only** on first entry to that state. Additional failures while already requiring review do not repeat it. Once a valid authenticated source page catches up, the same locked transaction updates the monitor cursor and adds one `charging.monitor.resumed.simulated` status notice. A later failure is a new transition and may create a new incident message. Both are distinct from charger fault/repair states, explicitly simulator-scoped with `physical_verification=false`, and never send charger commands, change capacity or claim physical acceptance. Backend tests cover failed-batch rollback, repeated failure deduplication, source catch-up and subsequent new failure; Flutter/web have localized labels.

### Source-monitor notification navigation

A monitor status notice (`charging.monitor.requires_review.simulated` or `charging.monitor.resumed.simulated`) now offers **Review simulator source monitor** on web and Flutter when verified simulator provenance and positive company/charger IDs exist. It opens the existing vendor source-diagnostics screen, not the fault acknowledgment action. The web workspace re-authorizes the company/charger through its existing API; Flutter uses `ChargerDiagnosticsView` with read-only initial load. Tests reject forged/incomplete metadata and check that navigation performs GET calls only, never automatic source inspection, fault acknowledgment or charger commands.

### Recovery-operator authorization after remote source reads

ChargingOperationsController's existing source-inspection path now rechecks vendor-to-station ownership *after* an awaited source read but *before* the reconciler runs. Before recording the case-inspected event, a second authorization recheck holds row locks for the command and station, and refuses writes under stale vendor ownership. This addresses the time-of-check/time-of-use window where station access changes while remote evidence is being fetched. A new feature test deliberately transfers the station during a mocked source read, verifies HTTP 404, verifies no reconciliation call or operational case event was written, and leaves charger commands/invoices untouched. Agent 1 still owns source-consumer/reconciliation internals; this patch does not claim to roll back source reads that already occurred.

### Canonical command recovery case notifications (operator-scoped)

Agent 3 added two **vendor-owned in-app status signals** to the existing `ChargingOperationsController` without modifying Agent 1's canonical dispatch/reconciliation: `charging.operational_case.opened` on the first quarantined case-open transition and `charging.operational_case.source_observed` after the first authenticated `command_source_observations` proof is found during operator inspection. The latter uses the locked case row plus an existing encrypted case-event marker (`source_observed_notice`) to prevent duplicate notifications after repeated inspections. Both updates and the event marker commit in the same DB transaction, contain only station/command/case IDs and explicit `physical_verification=false`, and never send commands, release charging capacity or create finance records. Regression tests cover owner-only visibility, idempotent opening and inspection, no invoices, no additional commands and no HTTP source requests.

### Operator recovery inbox actions in web and Flutter

The two operational case notice kinds now have localized EN/AR titles in both clients. A notice with an integer command ID, valid case ID and explicit `physical_verification=false` can open the existing authorization-enforced operator recovery panel. Web uses the existing `openOperationalCase` read path against that specific command; Flutter `ChargingOperationsView` supports `focusCommandId` and fetches the current case through its normal authenticated GET endpoint, displaying its state and history without issuing a POST. Further inspection, state edits and physical operations remain separate manual controls, not automatic notification behavior. Native widget tests assert no writes during focused-case opening.

### Focused read-only native regression evidence

Flutter fixture coverage now verifies that opening an operational case notice issues a GET for the *exact referenced command* (`charging-operations/7/case`), renders its returned status, and sends **zero write requests**. It also checks invalid case IDs, non-physical provenance mismatches and unrelated notification kinds cannot form a deep-link target. The operational recovery widget fixture independently checks the focused case GET request and read-only state display. Native test execution on the exact pushed revision remains pending hosted CI; fixtures alone are not build acceptance.

### Operator case source-based review closure notification

Reviewing a command recovery case as `resolved` continues to require canonical applied source evidence. The transition from any non-resolved state to `resolved` now inserts one vendor-owned `charging.operational_case.review_resolved` notice inside the existing locked transaction. Repeating a resolved review is not a new transition, so no duplicate closure notice is emitted. The case remains purely a source-evidence review; metadata always states `source_observed=true` and `physical_verification=false`. EN/AR web and native labels explicitly say *source-based operator review closed*, with owner-only visibility and repeated-resolution regression coverage.

### Bounded historical operations-attention dashboard

The existing `/api[/v1]/charging-operations/dashboard` now provides `recorded_monitor_review_count`, `unacknowledged_fault_alert_count` and `source_recovered_unacknowledged_fault_count` computed from archived simulator source records and scoped to the requesting vendor's stations (Admin may see all). These are **historical operator-workload counters**, not claims of current charger health, and the response retains `physical_charging_verified=false`. No fault payload, API token, note, customer identity or internal private evidence is returned. Existing web and Flutter operational recovery dashboards display EN/AR counts with the existing physical-evidence disclaimer. Backend tests create two vendors' source records and assert separation from Admin aggregate; widget tests verify displayed values.

### Focused native recovery navigation and stale response control

An inbox-originated `ChargingOperationsView(focusCommandId: ...)` now issues only the authorized single-case GET rather than also loading unrelated dashboard/list endpoints. Its focused request has an independent generation marker, suppresses stale replies and handles session-expiration callback disposal safely. Generic dashboard/list refresh buttons are hidden in focused mode, while the focused card still offers an explicit read refresh and existing case history. The full SupportView-to-focused-case widget fixture asserts exactly one case GET, no unsolicited dashboard queries, no write method calls, and rendering of the actual server case state. This remains uncompiled until hosted Flutter validation finishes.

### Vendor reassignment isolation for archived source fault metrics

A new dashboard safety check scopes simulator fault/monitor counts through the currently valid station↔vendor-company↔site↔charger↔CSMS binding graph, including matching simulator source and OCPP protocol. Legacy or transferred stations without an intact mapping do not expose a previous vendor's archived alert counts to a new station owner. An Admin view likewise counts only currently coherent mappings rather than misattributing historical records. Laravel regression tests create separate legitimate vendor mappings, verify each tenant's own counters, transfer a station to a different vendor without changing the prior company ownership, and require all invalidated counts to disappear. No historical source records are deleted or rewritten.

### Simulator source ownership race regression

Two additional observer feature tests simulate station ownership transfer and the reassignment of the canonical charger binding **during the pending source HTTP call**. Both must fail closed with HTTP 409 and leave the source cursor, observations, fault alerts and vendor inbox empty. These tests protect against sending a fault notice to a previous vendor after station or charger mapping changes; they do not grant physical charger authority or trigger any control commands.
