const { test } = require("node:test");
const assert = require("node:assert/strict");
const { meterSample } = require("./helpers/client-demo-meter-sample.cjs");
for (const protocol of ["1.6", "2.0.1"]) {
  test(`${protocol} final meter and transaction use one observation even across a clock boundary`, () => {
    let reads = 0;
    const reading = meterSample(protocol, 1045, "Transaction.End", () => {
      reads++;
      return new Date(
        Date.UTC(2026, 10, 11, 23, 59, 59, 999) + reads - 1,
      ).toISOString();
    });
    assert.equal(reads, 1);
    assert.equal(reading.timestamp, reading.meterValue[0].timestamp);
    assert.equal(reading.timestamp, "2026-11-11T23:59:59.999Z");
    const value = reading.meterValue[0].sampledValue[0];
    assert.equal(Number(value.value), 1045);
    assert.equal(value.context, "Transaction.End");
    assert.equal(
      protocol === "1.6" ? value.unit : value.unitOfMeasure.unit,
      "Wh",
    );
  });
}
test("source samples preserve begin/update contexts and reject unsupported protocol or meter values", () => {
  for (const context of ["Transaction.Begin", "Sample.Periodic"]) {
    const reading = meterSample(
      "2.0.1",
      0,
      context,
      () => "2026-11-11T00:00:00.000Z",
    );
    assert.equal(reading.meterValue[0].sampledValue[0].context, context);
  }
  for (const amount of [-1, 1.5, NaN, 9000000000001])
    assert.throws(() => meterSample("2.0.1", amount, "Transaction.End"));
  assert.throws(() => meterSample("2.1", 1, "Transaction.End"));
});
