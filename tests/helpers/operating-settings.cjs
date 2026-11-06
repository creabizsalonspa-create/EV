// The persistent lab device's actual timer/connector settings. No credential keys.
const assert = require("node:assert/strict");
const HEARTBEAT_SECONDS = 30,
  METER_SECONDS = 5;
function operatingSettings(protocol) {
  assert(["1.6", "2.0.1"].includes(protocol));
  return (request, action) => {
    if (protocol === "1.6") {
      assert.equal(action, "GetConfiguration");
      assert(Array.isArray(request.key) && request.key.length <= 10);
      const values = {
        HeartbeatInterval: String(HEARTBEAT_SECONDS),
        MeterValueSampleInterval: String(METER_SECONDS),
        NumberOfConnectors: "1",
      };
      return {
        configurationKey: request.key
          .filter((key) => Object.hasOwn(values, key))
          .map((key) => ({ key, readonly: true, value: values[key] })),
        unknownKey: request.key.filter((key) => !Object.hasOwn(values, key)),
      };
    }
    assert.equal(action, "GetVariables");
    assert(
      Array.isArray(request.getVariableData) &&
        request.getVariableData.length <= 10,
    );
    const values = {
      "OCPPCommCtrlr.HeartbeatInterval": String(HEARTBEAT_SECONDS),
      "SampledDataCtrlr.TxUpdatedInterval": String(METER_SECONDS),
      "ClockCtrlr.TimeZone": "UTC",
    };
    return {
      getVariableResult: request.getVariableData.map((row) => {
        const key = row.component?.name + "." + row.variable?.name;
        const accepted =
          Object.hasOwn(values, key) &&
          (row.attributeType ?? "Actual") === "Actual";
        return {
          component: row.component,
          variable: row.variable,
          attributeType: row.attributeType ?? "Actual",
          attributeStatus: accepted ? "Accepted" : "UnknownVariable",
          ...(accepted ? { attributeValue: values[key] } : {}),
        };
      }),
    };
  };
}
module.exports = { operatingSettings, HEARTBEAT_SECONDS, METER_SECONDS };
