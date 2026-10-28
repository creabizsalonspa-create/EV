# Staging image build and isolation validation

Verified PR17, tested sourcecbed320 and merge6a9b404: all eight exact-source checks passed, including both complete runtime validations. Hosted image IDs/log hashes are retained in docs/evidence/staging-image/hosted-checkpoint.json. Follow-up to merged PR16. This is software validation, not a deployed/production-accepted environment.

The runtime and Composer bases are digest-pinned. PHP8.5 includes OPcache; attempting docker-php-ext-install opcache failed with `cp: cannot stat modules/*` / install-modules. The image now compiles the other required extensions and verifies the built-in OPcache instead. Debian downloads use HTTPS and fail on incomplete indexes; an optional BuildKit CA secret enables trusted proxy builds without disabling TLS/package signatures or persisting proxy trust configuration. The wrapper forwards only Docker's predefined proxy arguments, not image ENV/ARG credentials.

Build-context rules exclude local SQLite files/journals, private .env files, PHPUnit cache, application tests and Composer's host vendor directory. The former broad **/vendor exclusion would also have removed self-hosted public Leaflet assets; the exclusion is now scoped to the Composer dependency directory. The image audit verifies every licensed map file against the reviewed upstream hashes and asserts no private database/environment/development material or build-only CA configuration enters the image. CI injects synthetic SQLite/journal/environment canaries before building, so those checks are not vacuous.

The private storage directory is created and owned by www-data in the image, supporting initial ownership copying into fresh private volumes. Existing operator volumes are preserved: verify/migrate their ownership separately; never reset a volume to fix permissions.

Commands:

```
./scripts/staging-image-build creabiz-ev-business:review
./scripts/staging-image-smoke creabiz-ev-business:review
```

The build wrapper runs an offline, read-only, unprivileged image audit. The second command creates only run-owned isolated containers, a private network and anonymous volumes, using pinned PostgreSQL17 and mode0600 generated synthetic credentials. It runs the complete migrations as www-data, verifies actual Apache/Laravel catalog/page/map responses and security/session headers, and writes/reads/deletes a synthetic private-volume file as the runtime worker. Cleanup uses captured newly created resource IDs and removes only those resources. No host ports, existing databases, real charger/PSP/partner endpoints or production credentials are used.

Local validation so far: Bash and both embedded PHP syntax checks pass. The full build progressed through corrected HTTPS/proxy downloads and PHP extension compilation, then failed at COPY with `no space left on device`. This workspace uses a32GiB VFS Docker store; no successful local image/runtime claim is made. Only specifically identified cache records from this task's failed build were cleaned (not a global prune), restoring capacity while preserving all original images/containers/database volumes. A96MiB generated Flutter test-build cache was retained in /tmp/ev-mobile-build-e942017. The identical complete image audit and PostgreSQL17 smoke run as a new hosted CI job; both exact-source hosted executions passed and their conclusions are recorded in the hosted checkpoint.

Still required for production: authorized deployment domain/TLS and secret provisioning, least-privilege database/bootstrap review, existing volume/backup/restore acceptance, monitoring and release/security review, physical-device/hardware/PSP/roaming acceptance and signing/store release. This job does not replace those gates or the existing full backend/browser/protocol/finance/connector suites.

## First hosted attempt and correction

Sourcea1b9412 built successfully and passed the offline image/asset/data audit, then the real application failed its40-second HTTP-readiness check. Review found that authoritative Composer metadata was generated before copying application namespaces; Laravel can silently omit unavailable configured providers during package discovery. Application/database namespaces are now copied before generation, and the audit explicitly loads real controllers, middleware and every configured application provider. Runtime source read/traverse permissions are normalized in the image while remaining root-owned; private storage is0700 and worker-owned. Host source permissions and secrets are not changed. Failed smoke runs now retain redacted application startup/error output before deleting only their own resources. Corrected sourcecbed320 passed both full image audits and actual PostgreSQL17/HTTP/private-volume runs, followed by all remaining regression checks. The failed attempt remains recorded as failed.
