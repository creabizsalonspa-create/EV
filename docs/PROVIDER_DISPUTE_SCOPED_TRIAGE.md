# Provider dispute scoped triage

The existing authenticated provider receipt list accepts bounded page size, page,
currency, invoice ID and canonical provider state. Attention filtering combines
with these filters; existing default page size and tenant isolation are unchanged.
Admin-only deadline triage also supports currency and invoice scope. Both reads
validate exact safe financial source identities, amounts and currency precision
before disclosure. Deadline triage reads the unresolved evidence count in the
same row query instead of a separate per-row query; a single captured observation
time determines cutoff, expiration and stale-source labels. No new provider calls
or financial mutations are performed.

Regression tests cover signed TEST receipt filters, foreign invoice isolation,
invalid filter bounds, Admin-only triage and unrepresentable source rejection
without releasing a settlement hold. This is partial 3.5.8 / 7.3.9, not live
provider evidence submission acceptance. Existing native clients can retain their
default query. Agent 5 must preserve the independent #247 provider service guards
and tests during integration. Fresh exact-head hosted validation is required.

## Web filter and receipt workflow

The existing English/Arabic provider dispute workspace now exposes optional currency/invoice filters, a provider-state selector for the ordinary list, and Admin deadline windows of 0/24/48/72/168 hours. Every page verifies the server's applied scope, exact pagination, safe minor units, simulation flags, unique identities and urgency before rendering. Filter edits erase stale result/action controls. Detail reads verify the selected receipt ID before showing existing authorized evidence actions. Detached controls cannot initiate requests. Existing provider evidence draft/retry identity is preserved. The component browser regression exercises filtered empty results, custom deadline windows, mismatched scope rejection, and the original same-key retry flow. Native remains read-only pending Agent 4 coordination. No production acceptance.
