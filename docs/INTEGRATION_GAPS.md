# Integration gaps and external prerequisites

Updated 2026-10-27. Code work must continue without waiting for external prerequisites when it can be safely simulated or implemented independently. Missing code is not reclassified as an external blocker.

## Current development boundaries

| Boundary | What is demonstrated | What remains independently executable | External prerequisite for actual verification |
|---|---|---|---|
| Laravel ↔ CitrineOS | Local simulator-only remote-start submission for OCPP 1.6/2.0.1; both reach the simulated socket | Transaction/meter/fault/reconnect/stop events, authenticated inbox, durable correlation, full charging-to-invoice integration, multi-tenant provisioning | Approved physical charger/site, firmware/protocol identification, monitored safety authorization for hardware |
| CitrineOS upstream services | Pinned OCPP service, PostGIS and RabbitMQ run separately from business SQLite | Evaluate/build the pinned upstream source and operator UI/OCPI/Hasura topology as required; secure deployment configuration and simulator coverage | Live device/network access only when actual hardware/partner tests begin |
| OCPI CPO/eMSP | Upstream inspected role validation is CPO-oriented; no complete outbound aggregator is verified | Separate outbound 2.2.1 adapter, explicit capabilities, credentials/version lifecycle, contract/replay/normalization/reconciliation tests and UI integration | Authorized partner sandbox, credentials, bilateral roaming agreement, supported capabilities |
| Finance and PSP | Application simulator estimates exist; finance work is active but not accepted | Tariff snapshots, immutable journal/invoices, idempotent simulated checkout/refund and reconciliation, provider adapter and sandbox tests | Approved PSP/merchant sandbox credentials; explicit authorization before real capture/refund |
| Maps/directory/navigation | Local catalog preserves discovery-only versus simulator provenance; live availability remains unknown | Provider abstraction, licensed attribution, filtering/caching/freshness, accessibility and navigation UX | API access/key or licensed source where required; discovery data does not grant remote-control rights |
| Flutter ↔ native API | Active Sanctum API tests pass; both native projects and shared source exist | Shared analysis/7 API-widget tests pass; complete full client contracts and compile/test both Android apps | macOS/Xcode for iOS; signing/store credentials for distribution; real devices for device acceptance |
| Notifications | Local support-related in-app feed/read behavior tested | Queue/retry/preferences/templates, provider adapters, delivery contracts | Approved mail/push/SMS service and credentials for actual delivery |
| Fleet/loyalty/subscriptions | Fleet consent/isolation/cost-center foundation tested | Full corporate policy/reporting/billing plus authoritative loyalty/subscription workflows | External partner/retail or commercial agreements only for actual partner operations |
| Staging/operations | Local Docker processes, scripts and migration paths work | Production-grade images/config, observability, restore/load/security drills and CI/mobile pipelines | Authorized staging infrastructure/domain/TLS and regional operational/legal approval |

## Access history that must not be misreported as a present blocker

Initial GitHub REST and package-advisory requests were denied. Subsequent checks succeeded: native Git pushes, GitHub repository access, creation of draft PR #1, and Composer's advisory audit with no reported advisories. Use the existing authenticated connection. Do not request a fresh token or reconnection based only on an old error log.

A full Flutter container unpack failed with insufficient transient storage; it was not used. The slimmer official Flutter Git/SDK installation subsequently succeeded. The initial SDK release-index request returned an unavailable object, but the pinned Git toolchain downloaded its Dart artifacts. Native source compilation/build remains unverified until the actual commands pass. The first analyzer run encountered a read-only state directory and an Android command-line-tools archive was incomplete. Both routine setup problems were corrected: the shared package analyzes cleanly, 7 Flutter tests pass, and checksum-verified Android command-line tools 23 plus platform/build-tools 36 and platform-tools are installed. These results do not yet constitute a successful app APK/iOS build and do not require the owner to manually install routine dependencies.

Network configuration drafts and startup instructions have been saved during onboarding. Saving a draft does not itself prove runtime application, publication or restoration in a new task. Retest each affected operation and report observed outcomes.

## Requirements that cannot be fabricated or inferred

- A charger marked discovery-only stays non-controllable without authenticated operator capability evidence.
- An outbox `submitted` confirmation is not charger acceptance, an active physical session, a final meter reading, a payment or an invoice.
- Application simulation, OCPP protocol simulation, provider sandbox, actual partner verification and physical hardware tests are separate evidence classes.
- A country/currency/tax configuration row is not legal approval; wallet/stored-value activation requires explicit suitability review.
- A successful Android compile is not a signed store release, and an iOS project on Linux is not an iOS build.
- Local development CSMS `localDev` tokens and security profile 0 remain loopback-only test settings. Secure staging requires JWT issuer/audience/service identity and appropriate TLS/mTLS/device provisioning.
- No actual partner, UAE operator relationship, production payment, government approval, compliance certification or production deployment is currently verified.

Only request the smallest missing account/credential/hardware/authorization when it is needed for a concrete remaining operation. Never request secret values in chat or store them in Git. Continue independent work and document exact failed commands rather than bypassing access controls or integrity verification.

## Optional proprietary reuse

No paid package is necessary to proceed. If licensed Naasmind or other commercial source later arrives, audit its actual source/license/security/dependencies and paid-subscription/end-product terms before extracting components. Its published MongoDB stack must not become a second canonical backend alongside Laravel/CitrineOS. Reject reuse when it creates more work or fails legal/technical checks; never infer working OCPP/OCPI/payments from a purchased UI. No such source has been supplied or reused in this checkpoint.
