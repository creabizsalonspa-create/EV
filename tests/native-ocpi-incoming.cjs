// Reuse the native credentials client with a server-approved incoming fixture.
process.argv.push("--incoming");
require("./native-ocpi-credentials.cjs");
