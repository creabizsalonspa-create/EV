// Narrow compatibility fix for the exact pinned CitrineOS v2.1.0 base.
// Strict official schemas/handlers are installed separately by the distribution.
import { readFileSync, writeFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const index = fileURLToPath(import.meta.resolve("@citrineos/base"));
const target = new URL("./src/ocpp/rpc/message.js", "file://" + index);
const original = readFileSync(target, "utf8");
const expected =
  "4353e72ed33e5c145d3dbda8506ea8bd6e399ba6488bc1730c4777013260933f";
if (createHash("sha256").update(original).digest("hex") !== expected)
  throw Error(
    "Pinned source action map changed; review upstream before applying compatibility fix.",
  );
const needle = "const OCPP_CallActions = new Set([\n";
if (original.split(needle).length !== 2)
  throw Error("Unique OCPP1.6 action allowlist was not found.");
const patched = original.replace(
  needle,
  needle +
    "    OCPP_CallAction.SignedUpdateFirmware,\n    OCPP_CallAction.SignedFirmwareStatusNotification,\n",
);
writeFileSync(target, patched);
console.log(
  "Added only the two standard OCPP1.6 signed-firmware actions to the pinned allowlist.",
);
