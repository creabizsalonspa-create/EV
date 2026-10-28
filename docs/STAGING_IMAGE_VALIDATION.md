# Staging image build and isolation validation

Candidate follow-up to merged PR16. This is software validation, not a deployed/production-accepted environment.

The runtime and Composer bases are digest-pinned. PHP8.5 includes OPcache; attempting docker-php-ext-install opcache failed with `cp: cannot stat modules/*` / install-modules. The image now compiles the other required extensions and verifies the built-in OPcache instead. Debian downloads use HTTPS and fail on incomplete indexes; an optional BuildKit CA secret enables trusted proxy builds without disabling TLS/package signatures or persisting proxy trust configuration. The wrapper forwards only Docker's predefined proxy arguments, not image ENV/ARG credentials.

Build-context rules exclude local SQLite files/journals, private .env files, PHPUnit cache, application tests and Composer's host vendor directory. The former broad **/vendor exclusion would also have removed self-hosted public Leaflet assets; the exclusion is now scoped to the Composer dependency directory. The image audit verifies every licensed map file against the reviewed upstream hashes and asserts no private database/environment/development material or build-only CA configuration enters the image. CI injects synthetic SQLite/journal/environment canaries before building, so those checks are not vacuous.

The private storage directory is created and owned by www-data in the image, supporting initial ownership copying into fresh private volumes. Existing operator volumes are preserved: verify/migrate their ownership separately; never reset a volume to fix permissions.

Commands:

```
./scripts/staging-image-build creabiz-ev-business:review
./scripts/staging-image-smoke creabiz-ev-business:review
```

The build wrapper runs an offline, read-only, unprivileged image audit. The second command creates only run-owned isolated containers, a private network and anonymous volumes, using pinned PostgreSQL17 and mode0600 generated synthetic credentials. It runs the complete migrations as www-data, verifies actual Apache/Laravel catalog/page/map responses and security/session headers, and writes/reads/deletes a synthetic private-volume file as the runtime worker. Cleanup uses captured newly created resource IDs and removes only those resources. No host ports, existing databases, real charger/PSP/partner endpoints or production credentials are used.

Local validation so far: Bash and both embedded PHP syntax checks pass. The full build progressed through corrected HTTPS/proxy downloads and PHP extension compilation, then failed at COPY with `no space left on device`. This workspace uses a32GiB VFS Docker store; no successful local image/runtime claim is made. Only specifically identified cache records from this task's failed build were cleaned (not a global prune), restoring capacity while preserving all original images/containers/database volumes. A96MiB generated Flutter test-build cache was retained in /tmp/ev-mobile-build-e942017. The identical complete image audit and PostgreSQL17 smoke run as a new hosted CI job; their exact-source conclusion must be recorded before merge.

Still required for production: authorized deployment domain/TLS and secret provisioning, least-privilege database/bootstrap review, existing volume/backup/restore acceptance, monitoring and release/security review, physical-device/hardware/PSP/roaming acceptance and signing/store release. This job does not replace those gates or the existing full backend/browser/protocol/finance/connector suites.
