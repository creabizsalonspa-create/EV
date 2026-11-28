const assert = require('node:assert/strict');
const test = require('node:test');
const {stationIdentity, statusSequence, requireReadIdentity} = require('./ocpp-source-matrix.cjs');
const states = [['Available', 'NoError'], ['Faulted', 'GroundFailure'], ['Unavailable', 'NoError'], ['Available', 'NoError']];
const profiles = [
  {vendor: 'CreaBiz simulator', model: 'Protocol test', firmware: 'sim-fw-v1'},
  {vendor: 'Synthetic Labs', model: 'Protocol Edge', firmware: 'sim-fw-v2'},
];
for (const version of ['ocpp1.6', 'ocpp2.0.1']) {
  for (const profile of profiles) {
    test(`${version} ${profile.model}: exact station and concurrent model/firmware identity`, () => {
      const row = {id: 42, protocol: version, chargePointVendor: profile.vendor,
        chargePointModel: profile.model, firmwareVersion: profile.firmware};
      assert.equal(stationIdentity([row], version, profile), row);
      assert.equal(stationIdentity([{...row, protocol: version.slice(4)}], version, profile, 42).id, 42);
      for (const change of [{id: '42'}, {id: 0}, {id: 43}, {protocol: 'wrong'},
        {chargePointVendor: 'Foreign'}, {chargePointModel: 'Foreign'}, {firmwareVersion: 'wrong'}]) {
        assert.equal(stationIdentity([{...row, ...change}], version, profile, 42), null);
        assert.throws(() => requireReadIdentity({ChargingStations: [{...row, ...change}]}, version, profile, 42));
      }
      for (const bad of [null, [], [row, row], [null], {0: row}]) {
        assert.equal(stationIdentity(bad, version, profile), null);
      }
    });
  }
  const rows = states.map(([connectorStatus, errorCode], i) => ({id: i + 1,
    connectorId: 1, evseId: 1, connectorStatus,
    errorCode: version === 'ocpp1.6' ? errorCode : null,
    timestamp: `2026-11-28T00:00:0${i}Z`})).reverse();
  test(`${version}: valid exact connector chronology and distinct other connector`, () => {
    assert.deepEqual(statusSequence(rows, version, states), rows);
    assert.deepEqual(statusSequence([{...rows[0], id: 5, connectorId: 2}, ...rows], version, states), rows);
    if (version === 'ocpp1.6') assert.equal(statusSequence(rows.map(row => ({...row, evseId: null})), version, states).length, 4);
  });
  const changes = [
    ['duplicate ID', {id: 3}], ['string ID', {id: '4'}], ['wrong connector', {connectorId: 2}],
    ['unknown status', {connectorStatus: 'Unknown'}], ['wrong error', {errorCode: 'GroundFailure'}],
    ['relative clock', {timestamp: 'now'}], ['overflow date', {timestamp: '2026-02-30T00:00:03Z'}],
    ['equal clock', {timestamp: rows[1].timestamp}], ['regressed clock', {timestamp: rows[2].timestamp}],
  ];
  if (version === 'ocpp1.6') changes.push(['missing explicit NoError', {errorCode: null}]);
  else changes.push(['wrong EVSE', {evseId: 2}]);
  for (const [name, change] of changes) {
    test(`${version}: rejects ${name}`, () => {
      assert.equal(statusSequence([{...rows[0], ...change}, ...rows.slice(1)], version, states), null);
    });
  }
  test(`${version}: rejects truncated and malformed histories`, () => {
    for (const bad of [null, [], rows.slice(1), [null, ...rows], [...rows].reverse(), [...rows, ...rows, rows[0]]]) {
      assert.equal(statusSequence(bad, version, states), null);
    }
  });
}
