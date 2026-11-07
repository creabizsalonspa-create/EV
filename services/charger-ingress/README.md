# TLS charger admission edge

Uses Node24 built-ins only. See [CHARGER_ADMISSION.md](../../docs/CHARGER_ADMISSION.md) for configuration, trust boundaries, lifecycle, tests and explicit non-production/hardware acceptance limits.

Required environment: EV_INGRESS_TLS_KEY_FILE, EV_INGRESS_TLS_CERT_FILE, EV_INGRESS_SECRET_FILE, EV_INGRESS_AUTH_URL and EV_INGRESS_UPSTREAM_URL. Optional EV_INGRESS_CLIENT_CA_FILE enforces mutual TLS. Default listener127.0.0.1:8443. Never expose the underlying CSMS WebSocket directly. Keep key files outside source control, bind only authorized staging interfaces and preserve strict certificate verification. No physical deployment is performed by the test or this repository setup.
