// One source observation owns both the event and meter timestamp. No clock
// adjustment, meter invention or Laravel/source-validation bypass is allowed.
const assert = require("node:assert/strict");
function meterSample(
  protocol,
  meterWh,
  context,
  clock = () => new Date().toISOString(),
) {
  assert(["1.6", "2.0.1"].includes(protocol));
  assert(
    Number.isSafeInteger(meterWh) && meterWh >= 0 && meterWh <= 9000000000000,
  );
  assert(
    ["Transaction.Begin", "Sample.Periodic", "Transaction.End"].includes(
      context,
    ),
  );
  const timestamp = clock();
  assert(
    typeof timestamp === "string" && Number.isFinite(Date.parse(timestamp)),
  );
  return {
    timestamp,
    meterValue: [
      {
        timestamp,
        sampledValue: [
          protocol === "1.6"
            ? {
                value: String(meterWh),
                measurand: "Energy.Active.Import.Register",
                unit: "Wh",
                context,
              }
            : {
                value: meterWh,
                measurand: "Energy.Active.Import.Register",
                unitOfMeasure: { unit: "Wh" },
                context,
              },
        ],
      },
    ],
  };
}
module.exports = { meterSample };
