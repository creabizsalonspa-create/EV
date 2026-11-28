const assert = require('node:assert/strict');

const protocols = new Set(['ocpp1.6', 'ocpp2.0.1']);
const validId = (id) => Number.isSafeInteger(id) && id > 0 && id <= 2147483647;

function stationIdentity(rows, version, profile, expectedId) {
  if (!protocols.has(version) || !Array.isArray(rows) || rows.length !== 1) return null;
  const row = rows[0];
  if (!row || typeof row !== 'object' || !validId(row.id) ||
      ![version, version.slice(4)].includes(row.protocol) ||
      row.chargePointVendor !== profile.vendor || row.chargePointModel !== profile.model ||
      row.firmwareVersion !== profile.firmware ||
      (expectedId !== undefined && row.id !== expectedId)) return null;
  return row;
}

function sourceTime(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(value)) return null;
  const day = value.slice(0, 10);
  const date = new Date(day + 'T00:00:00Z');
  if (!Number.isFinite(date.valueOf()) || date.toISOString().slice(0, 10) !== day) return null;
  const time = Date.parse(value);
  return Number.isFinite(time) && +value.slice(11, 13) < 24 ? time : null;
}

// Verify the most recent exact connector history in source-ID order. The
// OCPP 1.6 wire message contains no EVSE ID; never treat its normalized
// source EVSE field as a second independently verified identity.
function statusSequence(rows, version, states, connector = 1, evse = 1) {
  if (!protocols.has(version) || !Array.isArray(rows) || rows.length > 8 ||
      !Array.isArray(states) || states.length === 0 || states.length > 8) return null;
  let previousId = Infinity;
  for (const row of rows) {
    if (!row || typeof row !== 'object' || !validId(row.id) || row.id >= previousId ||
        !Number.isInteger(row.connectorId) || row.connectorId < 0) return null;
    previousId = row.id;
  }
  const recent = rows.filter((row) => row.connectorId === connector &&
    (version === 'ocpp1.6' || row.evseId === evse)).slice(0, states.length);
  if (recent.length !== states.length) return null;
  let previousTime = Infinity;
  for (const [i, row] of recent.entries()) {
    const [status, error] = states[states.length - 1 - i];
    const time = sourceTime(row.timestamp);
    if (time === null || time >= previousTime || row.connectorStatus !== status ||
        (version === 'ocpp1.6' ? row.errorCode !== error :
          ![null, undefined, 'NoError'].includes(row.errorCode))) return null;
    previousTime = time;
  }
  return recent;
}

function requireReadIdentity(data, version, profile, expectedId) {
  const result = stationIdentity(data?.ChargingStations, version, profile, expectedId);
  assert(result, 'Concurrent source read changed station, protocol, model or firmware identity');
  return result;
}

module.exports = {stationIdentity, statusSequence, requireReadIdentity};
