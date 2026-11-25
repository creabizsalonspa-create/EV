# Fleet private report source parity

The existing enterprise report validates raw cost-center identity, currency,
precision and integer claim amounts before grouping. Historic exact decimal
sums remain strings and are never rounded through floating-point arithmetic.
Server CSV and web/native snapshots neutralize spreadsheet formula prefixes
after Unicode separator, control and format characters.

Web and native verify the report's requested fleet, period and cost center,
recorded-claim basis, simulation limits, exact group counts and unique historic
group identity before rendering. Edited filters remove previously accepted
results and export controls. Native current-session expiry uses the canonical
generation-aware predicate and inactive mounts cannot read. Web labels retain
editable fields across language switching.

Added backend Unicode CSV regression and client forged scope regressions.
No fleet/payment/claim mutation or physical charging proof is inferred.
Partial 8.1.8 and enterprise reporting scope; existing Agent 5 canonical
integration and fresh hosted gates remain mandatory.
