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
