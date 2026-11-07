const assert = require("node:assert/strict"),
  fs = require("node:fs"),
  vm = require("node:vm");
const scope = { window: {} };
vm.runInNewContext(
  fs.readFileSync("services/business-api/public/money-input.js", "utf8"),
  scope,
);
const money = scope.window.EvMoney;
assert.equal(money.parse("٢٫٥٠", 2), 250);
assert.equal(money.parse("۲٫۵۰", 2), 250);
for (const value of [
  "-1",
  "1.001",
  "1e2",
  "2,50",
  "٢٬٥٠٠",
  "10000.01",
  "999999999999999999999",
])
  assert.equal(money.parse(value, 2), null, value);
assert.equal(money.parse("100.00", 2, 10000), 10000);
assert.equal(money.parse("100.01", 2, 10000), null);
assert.equal(money.parse("2.0", 0), null);
for (const digits of [0, 1, 2, 3])
  for (const amount of [0, 1, 99, 100, 999999, 1000000])
    assert.equal(money.parse(money.format(amount, digits), digits), amount);
console.log(
  "PASS: exact EN/Arabic/Persian monetary input, 0–3 currency digits, no silent rounding or ambiguous separators, bounded integer round trips.",
);
