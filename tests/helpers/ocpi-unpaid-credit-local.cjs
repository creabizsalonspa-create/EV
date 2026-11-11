// No collection is performed; the existing approved TEST source fixture is reused.
const withCredit = require("./ocpi-credit-local.cjs");
module.exports = (run) => withCredit(run, { paid: false });
