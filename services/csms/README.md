# Isolated development CSMS

Pinned CitrineOS **v2.1.0**, Apache-2.0, with a separate PostGIS database and RabbitMQ. `compose.yaml` pins every image digest. The business database is never shared with CitrineOS.

From the repository root:

```sh
./scripts/csms-setup
./scripts/csms-start
npm run test:ocpp
```

Setup generates random local-only DB/broker credentials into ignored `.env` without printing them. It preserves an existing file. Named Docker volumes retain current-instance state; snapshot restoration of those volumes has not been independently verified. Do not delete them during routine setup.

Only API 8080 and simulator WebSocket 8081 bind to host loopback. Database and broker ports are not published. This stack intentionally uses upstream localDev API token minting and OCPP security profile 0 for **local simulated chargers**. It is unsuitable for staging or production. No external CA integration is enabled. Real deployment requires supported JWT issuance/audience validation, TLS/mTLS and hardware provisioning with explicit safety approval.

The Node test checks both OCPP versions: boot accepted, heartbeat time, connector status, rejection of unauthenticated remote commands, authenticated remote-start delivery and simulator acknowledgment, and a second remote-start delivered from the Laravel outbox. Synthetic station identifiers use `ev-test-*`. It never connects to a physical charger. Token values are kept in memory and not logged.

Application simulator sessions and OCPP protocol tests remain separate. Full OCPP transaction/meter event ingestion, durable correlation of acknowledgments, physical session/billing reconciliation, stop/fault/reconnect coverage and multi-tenant CSMS provisioning remain outstanding.

## OCPI assessment

At source `4fd7599b91c2fb6e6065e7c9b94be69880cced27`, the OCPI app's credential-role checks (`packages/ocpi/src/util/util.ts`) accept EMSP clients and CPO server roles. Thus the inspected server is CPO-oriented; its existence alone does not implement our outbound eMSP aggregator. OCPI is not deployed or conformance-tested here. A separate eMSP adapter, partner registration/token handling, location/tariff/session/CDR reconciliation and authorized contract tests are required. No operator relationship or access is implied.
