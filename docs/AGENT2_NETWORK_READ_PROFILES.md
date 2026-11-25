# Network-specific OCPI read profiles — Agent 2

Original requirement 6.4.7, partial executable software scope. This isolated candidate extends #343 (base source cec1b0f00c9ce498f5ae1ac766940af959a7f714), without changing its active CI. Agent 5 alone integrates with canonical, preserving #320 capability and #284 contract gates.

## Configuration

Each existing approved OCPI connection in server-owned OCPI_CONNECTIONS_JSON can optionally include a read_modules array, for example ["locations","tariffs"] or ["sessions","cdrs"]. No credentials or real operators are supplied in this example. Valid names are locations, tariffs, sessions, cdrs. Omission retains the historical four-module configuration. Explicit [] admits no outbound module pulls; it does not grant CPO publishing or inbound receiving permissions. Duplicate, unknown, non-string, associative or null scopes fail closed before network negotiation. New profiles still require independently approved origin, credential, role and endpoint authority.

Negotiation persists only configured sender modules, and each pull and scheduled polling consent checks current scope. Removing permission blocks previously negotiated sender endpoints without dispatching new reads. Provider and capability status reflect the configured read subset. This does not erase historical records or grant any new permission to ingest peer pushes, perform remote commands, reserve chargers or bill customers. A changed profile may require explicit polling renewal. Credential operations retain their own authority checks.

## Evidence

The standalone PHP 8.4.24 helper was reconstructed byte-identically from GitHub blob e1c8d458d01a86d316ef6e8111a63a3c471b9a9b. Syntax check and 30 isolated assertions passed, including default/empty/subset, order normalization, invalid shapes, unknown modules and independent allow/deny. Added feature regressions for restricted negotiation, stale endpoints, invalid profiles, scheduled consent withdrawal, and independent inbound receiver flags. Full Laravel/PostgreSQL/browser/native acceptance remains pending; no current source full-suite pass claimed.

## Remaining original acceptance

6.4.7 is not completed by these generic per-network policy controls alone. Named networks, their approved API contracts, credentials, authorized sandbox interactions, network-specific mapping where applicable, and partner conformance require separate evidence. Do not fabricate any named-network implementation or live agreement. No production change.
