# Agent 10 — source-linked private staging runtime acceptance

The command in scripts/staging-live-source-acceptance.cjs is an **operator-invoked, read-only acceptance gate** that combines checks previously run only separately. It is designed for a privately authorized staging machine with the reviewed Git checkout, locally reachable Docker Engine, private .env, and separately authorized TLS certificate trust. It does not start a demo or deploy services.

## Required integration order

1. Agent 4 reviews and merges PR #62, providing canonical private staging preflight and pinned PostgreSQL/Caddy images.
2. Agent 4 reviews and merges PR #66, providing source-labeled image verifier, effective Compose gate, HTTPS smoke and this combined read-only acceptance tool.
3. Running infrastructure with unreviewed, mutable or different PostgreSQL/Caddy images **fails closed**. Any upgrade requires separate backed-up and operator-approved maintenance, not an implicit action of this gate.

## Read-only usage on the authorized private staging host

Create owner-only output outside public document roots. Keep private .env, Docker config and reports private.

    umask 077
    mkdir -p -m 700 artifacts/staging-private-evidence
    node scripts/staging-live-source-acceptance.cjs \
      --source-sha "$(git rev-parse HEAD)" \
      --url https://YOUR_AUTHORIZED_STAGING_FQDN:8443/ \
      --output artifacts/staging-private-evidence/source-review-UNIQUE.json

The URL is **always HTTPS with certificate verification**, connects to 127.0.0.1 only, requires private port 8443, and must match reviewed STAGING_HOST. The private CA public root must be independently reviewed and trusted on the operator's device. This tool never installs trust, disables TLS verification, contacts public URLs or permits a wildcard bind.

Every stage must pass before PRIVATE_SOURCE_LINKED_STAGE_READY:

- Clean exact checked-out Git SHA, also checked again after HTTPS.
- No Agent 10 staging release/rollback interlock present at the start or end of the inspection. A crash-retained lock means **operator reconciliation required**; this tool never removes, refreshes or bypasses a release lock.
- Canonical PR #62 review of owner-only .env including inherited-shell and payment/device/partner isolation restrictions.
- Resolved Compose config: exact pinned PostgreSQL/Caddy references, isolated persistent volumes, single private network, no public binding, and no unreviewed services.
- Actual running application, worker and scheduler containers use one immutable, source-labeled image ID matching reviewed Git SHA and public repository source. OCI labels are **self-declared**, not signed attestation.
- Five Docker Compose container IDs match project roles and persist through the first local-only Docker inspection.
- Seven anonymous HTTPS routes verify readiness, public catalog, web security headers, and denial of unauthorized customer/Admin identity endpoints.
- After HTTPS: re-read the exact owner-only private .env inode using no-follow semantics and require its SHA-256 to equal the pre-probe reviewed value. The digest is **never written** to customer evidence. Recheck image and five container identities, health, loopback port, source checkout and resolved Compose config. Any drift is INCOMPLETE.

The full source/HTTPS verification window is limited to **three minutes** and must use valid, ordered UTC timestamps. Slower/inconsistent checks fail closed rather than producing an indefinitely stale acceptance report. The interlock check observes the lock before and after but is not an exclusive shared transaction lock: another operator must still coordinate deployment activity separately.\n\nEvidence is a new exclusive owner-only JSON file. It includes the Git SHA, limited boolean checkpoints, seven route/status summaries, a one-way SHA-256 of the five stable container IDs plus image ID, and explicit non-production flags. No secrets, Docker environment values, passwords, account details or raw response bodies are written.

**Limitations:** This proves neither independently attested image bytes nor backup restoration, authenticated customer/vendor/Admin journeys, working queues, completed OCPP simulator sessions, physical charging, live OCPI, real payments, mobile signing, contractual acceptance or production readiness. PR #73 contains separate development-only mobile artifact verification. Agent 4 and Agent 9 still own integrated staging/quality acceptance.

## Safe tests

    node --check scripts/staging-live-source-acceptance.cjs
    node --test tests/staging-live-source-acceptance.cjs tests/staging-image-provenance.cjs

Tests inject synthetic Docker inspect, HTTPS, source review and Compose responses. They do **not** establish a real staging acceptance.
