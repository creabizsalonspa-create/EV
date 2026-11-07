// The connected browser/native journey shares one isolated approved TEST contract.
process.env.EV_SANDBOX_NATIVE_ONLY = "1";
require("./sandbox-roaming.cjs");
