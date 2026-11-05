// Distribution bootstrap only: upstream owns all OCPP transport and handlers.
import { ConfigLoader } from "@citrineos/base";
import { CitrineOSServer, RealTimeAuthorizer } from "@citrineos/ocpp";
import { createRequire } from "node:module";
import { existsSync, readFileSync } from "node:fs";
import { createHmac } from "node:crypto";
const { asValue } = createRequire(import.meta.resolve("@citrineos/ocpp"))(
  "awilix",
);
const callback = "http://ev-business-api:8000/api/internal/rider-authorize";
const secretFile = "/run/creabiz-rider/client-secret";
const secret = existsSync(secretFile)
  ? readFileSync(secretFile, "utf8").trim()
  : "";
const version = "creabiz-rider-v1";
class RiderServer extends CitrineOSServer {
  registerAdditionalServices(container) {
    super.registerAdditionalServices(container);
    const ready =
      secret.length >= 32 &&
      this._config.auth.mode === "localDev" &&
      this._config.env !== "production";
    const real = new RealTimeAuthorizer({
      chargingStationRepository: container.cradle.chargingStationRepository,
      authorizationRepository: container.cradle.authorizationRepository,
      logger: container.cradle.logger,
      config: {
        ...this._config,
        oidcClient: {
          tokenUrl:
            "http://ev-business-api:8000/api/internal/rider-oauth/token",
          clientId: "creabiz-csms-rider",
          clientSecret: secret,
          audience: "creabiz-rider-authorize",
        },
      },
    });
    const adapter = {
      authorize: async (authorization, context, evse, connector) => {
        const managed =
          authorization.realTimeAuthUrl === callback ||
          (Array.isArray(authorization.additionalInfo) &&
            authorization.additionalInfo.some(
              (m) => m?.type === "CREABIZ_RIDER",
            ));
        if (!managed) return authorization.status ?? "Invalid";
        if (
          !ready ||
          Number(context.tenantId) !== 1 ||
          !/^ev-test-[a-zA-Z0-9-]{1,80}$/.test(context.ocppConnectionName) ||
          authorization.realTimeAuthUrl !== callback ||
          authorization.realTimeAuth !== "Never" ||
          authorization.realTimeAuthTimeout !== 0
        )
          return "Invalid";
        return real.authorize(authorization, context, evse, connector);
      },
    };
    container.register({
      authorizers: asValue([adapter]),
      realTimeAuthorizer: asValue(adapter),
    });
    this._server.get(
      "/creabiz/rider-auth/capabilities",
      async (request, reply) => {
        const nonce = request.query?.nonce;
        reply.header("Cache-Control", "no-store");
        if (typeof nonce !== "string" || !/^[a-f0-9-]{36}$/i.test(nonce))
          return reply.code(400).send({ ready: false });
        if (!ready) return reply.code(503).send({ ready: false });
        return {
          ready: true,
          adapter_version: version,
          tenant_id: 1,
          offline_fallback: false,
          cache_seconds: 0,
          proof: createHmac("sha256", secret)
            .update(version + "\n" + nonce)
            .digest("hex"),
        };
      },
    );
  }
  get authExcludedRoutes() {
    return [...super.authExcludedRoutes, "/creabiz/rider-auth/capabilities"];
  }
}
const config = await ConfigLoader.loadConfig();
const server = new RiderServer(process.env.APP_NAME?.toLowerCase(), config);
server.run().catch(() => {
  console.error(
    "Rider-enabled CSMS startup failed; inspect service configuration without exposing credentials.",
  );
  process.exit(1);
});
