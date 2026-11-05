// Pinned v2.1.0 exposes these 1.6 security endpoints but omits their transport
// schemas/handlers. Extend public registries with the official strict schemas.
import {
  AbstractHandler,
  AsRequestHandler,
  AsResponseHandler,
  buildHandlers,
  mapToCallAction,
  OCPP1_6_CALL_SCHEMA_RECORD,
  OCPP1_6_CALL_RESULT_SCHEMA_RECORD,
} from "@citrineos/base";
import { OCPP1_6, OCPPVersion, OCPP_CallAction } from "@citrineos/types";
import { createRequire } from "node:module";
const { asFunction } = createRequire(import.meta.resolve("@citrineos/ocpp"))(
  "awilix",
);
class SignedFirmwareStatusHandler extends AbstractHandler {
  constructor({ logger, ocppSender }) {
    super(logger);
    this.sender = ocppSender;
  }
  async handle(message) {
    await this.sender.sendCallResultWithMessage(message, {});
  }
}
AsRequestHandler(
  [OCPPVersion.OCPP1_6],
  OCPP_CallAction.SignedFirmwareStatusNotification,
)(SignedFirmwareStatusHandler);
class SignedUpdateResponseHandler extends AbstractHandler {
  constructor({ logger }) {
    super(logger);
  }
  async handle() {
    /* Upstream persists the correlated response; no execution claim. */
  }
}
AsResponseHandler(
  [OCPPVersion.OCPP1_6],
  OCPP_CallAction.SignedUpdateFirmware,
)(SignedUpdateResponseHandler);
export function installSignedFirmware(container) {
  for (const action of [
    OCPP_CallAction.SignedUpdateFirmware,
    OCPP_CallAction.SignedFirmwareStatusNotification,
  ]) {
    if (mapToCallAction(OCPPVersion.OCPP1_6, action) !== action)
      throw Error(
        "Pinned signed-firmware action compatibility is unavailable.",
      );
  }

  const entries = [
    [
      OCPP_CallAction.SignedUpdateFirmware,
      OCPP1_6.SignedUpdateFirmwareRequestSchema,
      OCPP1_6.SignedUpdateFirmwareResponseSchema,
    ],
    [
      OCPP_CallAction.SignedFirmwareStatusNotification,
      OCPP1_6.SignedFirmwareStatusNotificationRequestSchema,
      OCPP1_6.SignedFirmwareStatusNotificationResponseSchema,
    ],
  ];
  for (const [action, request, response] of entries) {
    if (!request || !response)
      throw Error("Official signed firmware schemas are unavailable.");
    // OCPP1.6 Security 2020:3 defines optional integer requestId here.
    // The pinned generated schema omits it; retain every other strict constraint.
    const requestSchema = structuredClone(request);
    if (action === OCPP_CallAction.SignedFirmwareStatusNotification) {
      requestSchema.properties.requestId ??= { type: "integer" };
    }
    if (!OCPP1_6_CALL_SCHEMA_RECORD[action])
      OCPP1_6_CALL_SCHEMA_RECORD[action] = {
        ...requestSchema,
        $id: `creabiz-16-${action}-request`,
      };
    if (!OCPP1_6_CALL_RESULT_SCHEMA_RECORD[action])
      OCPP1_6_CALL_RESULT_SCHEMA_RECORD[action] = {
        ...structuredClone(response),
        $id: `creabiz-16-${action}-response`,
      };
  }
  const original = container.registrations.configurationHandlers;
  if (!original?.resolve)
    throw Error(
      "Supported source configuration handler registry is unavailable.",
    );
  container.register({
    configurationHandlers: asFunction((cradle) => [
      ...original.resolve(cradle.moduleScope),
      ...buildHandlers(cradle.moduleScope, [
        SignedFirmwareStatusHandler,
        SignedUpdateResponseHandler,
      ]),
    ]).scoped(),
  });
  return true;
}
