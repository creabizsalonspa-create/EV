# Agent 10 — private staging Caddy public CA review

The `ev-staging` gateway is loopback-only (`127.0.0.1:8443`) and uses `tls internal`. It cannot complete public ACME challenges, and automatic production trust must never be assumed. Before a planned staging image rollout, an authorized operator must obtain and independently approve the Caddy **PUBLIC root certificate** for that private lab.

**Never export the Caddy root or intermediate PRIVATE KEY.** The root PUBLIC certificate is enough for a local trust review. The operator must separately verify the test host, DNS and HTTPS leaf certificate using the existing `staging-health.cjs` route probe; reviewing a CA root alone is not evidence that the leaf certificate belongs to the staging domain.

## Offline public certificate review

Obtain the intended Caddy public root certificate through an approved local infrastructure procedure, save it as an owner-only PEM file (file mode 0600 under mode 0700 directory), and obtain its SHA-256 fingerprint from a **separately approved** record. A digest copied from the *same untrusted file* is **not independent approval**.

```sh
node scripts/staging-public-ca-review.cjs \
  --certificate /private/ev-staging-ca/root-public.pem \
  --approved-sha256 64_CHARACTER_LOWERCASE_REVIEWED_SHA256
```

The reviewer executes entirely offline and rejects symlinked, publicly readable, oversized and non-PEM inputs. It accepts **exactly one public self-signed CA root certificate**, verifies the issuer and subject are identical, verifies the self-signature, checks root CA status, requires a minimum 2048-bit RSA or standard P-256/P-384/P-521 EC public key, and refuses a certificate that is not yet valid or expires within seven days. It also rejects a file containing an additional PEM block, OpenSSH/EC/RSA private key, bundled certificate or hidden content. The only successful output is the approved public SHA-256 fingerprint and a statement that no trust state was changed.

The script never calls `docker`, `openssl`, `curl` or network APIs at runtime, never installs certificates, never enables TLS bypass flags, never starts a charger, and never connects a real payment processor. The tests use disposable OpenSSL-generated **synthetic root material**, which is promptly destroyed from a private temporary directory.

## Separately verify real private staging TLS

After independent approval, the operator may pass the **PUBLIC root** to an authorized local Node process without changing machine-wide trust. For example:

```sh
NODE_EXTRA_CA_CERTS=/private/ev-staging-ca/root-public.pem \
  node scripts/staging-health.cjs \
  --url https://AUTHORIZED-STAGING-HOST:8443/ --tls-loopback
```

The HTTP smoke performs TLS certificate and hostname checks while directing the actual TCP connection to `127.0.0.1`. It will not follow redirects to external servers or accept `NODE_TLS_REJECT_UNAUTHORIZED=0`. Passing that HTTP smoke confirms only the current private TLS and limited HTTP route responses; it does not verify login beyond those routes, payments, hardware, production readiness, Android/iOS signing or customer acceptance.

## Tests

```sh
node --check scripts/staging-public-ca-review.cjs
node --check tests/staging-public-ca-review.cjs
node --test tests/staging-public-ca-review.cjs
```

Exact checked-in source on an isolated authorized Node 24.22.1 host with OpenSSL 3.5.1: **14/14 tests passed**, zero failures/skips, including fake CA/leaf, weak key, expiry, mismatched independently approved hash, symlink, world-readable root, extra PEM/private-key blocks, invalid switches and successful no-install review. No live Caddy root or private key was accessed during the test run.

This tool does **not** install trust or authorize a staging deploy. Agent 4 coordinates exact-head CI/integration, Agent 3 the verified recovery prerequisite, and the authorized operator remains responsible for private TLS/host acceptance.
