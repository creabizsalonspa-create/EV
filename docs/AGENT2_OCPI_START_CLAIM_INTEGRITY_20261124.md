# OCPI durable START_SESSION claim integrity (Agent 2)

PRD 6.3.6 / 6.3.8 — bounded sandbox authorization improvement; no live operation or whole-atom credit.

An existing start workflow reserves a command identity before sending its token and subsequently dispatches START_SESSION. It now verifies the encrypted payload against the original intent digest, verifies the current stored command before sending the token, and locks and rechecks the exact same command after the token response. Changed identities, ciphertext, hashes, transport state or earlier callback/attempt evidence block START_SESSION. A stale actor/provider check still applies. The catch path cannot overwrite callback/transport evidence or release the EVSE when an attempted command is ambiguous. A repeated request does not send again.

Regression coverage uses synthetic HTTP fakes and three changed-claim cases (digest, ciphertext, prior attempted-at evidence). The expected only outbound operation in these cases is the already-consented token registration; no command, canonical charging session, invoice or journal is created. The prior attempted-at evidence and capacity hold are preserved for reconciliation. No physical charger, real partner, legal agreement or production capability is asserted. Exact hosted CI and Agent 5 integration remain required.
