// One transport budget shared by the simulator and its readiness supervisor.
// No device command or transaction deadline is increased.
const CONNECT_TIMEOUT_MS = 10000;
const RPC_TIMEOUT_MS = 25000;
const SIMULATOR_STARTUP_TIMEOUT_MS = CONNECT_TIMEOUT_MS + 2 * RPC_TIMEOUT_MS + 10000;
module.exports = { CONNECT_TIMEOUT_MS, RPC_TIMEOUT_MS, SIMULATOR_STARTUP_TIMEOUT_MS };
