const fs = require("node:fs");
const line = fs
  .readFileSync("services/csms/charging/.env", "utf8")
  .split("\n")
  .find((x) => x.startsWith("CHARGING_HASURA_ADMIN_SECRET="));
const secret = line.slice(line.indexOf("=") + 1);
async function metadata(body) {
  let r = await fetch("http://127.0.0.1:8095/v1/metadata", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "x-hasura-admin-secret": secret,
    },
    body: JSON.stringify(body),
  });
  let j = await r.json();
  if (!r.ok)
    throw Error(
      "CSMS GraphQL metadata request failed (" + (j.code || r.status) + ")",
    );
  return j;
}
(async () => {
  const current = await metadata({ type: "export_metadata", args: {} });
  if (!current.sources?.some((x) => x.name === "default"))
    await metadata({
      type: "pg_add_source",
      args: {
        name: "default",
        configuration: {
          connection_info: {
            database_url: { from_env: "HASURA_GRAPHQL_DATABASE_URL" },
          },
        },
      },
    });
  const latest = await metadata({ type: "export_metadata", args: {} });
  const source = latest.sources.find((s) => s.name === "default");
  for (const name of [
    "Authorizations",
    "Transactions",
    "MeterValues",
    "SampledValues",
    "ChargingStations",
    "Connectors",
    "StatusNotifications",
  ])
    if (!source.tables.some((t) => t.table.name === name)) {
      try {
        await metadata({
          type: "pg_track_table",
          args: { source: "default", table: { schema: "public", name } },
        });
      } catch (e) {
        if (
          [
            "Authorizations",
            "Transactions",
            "ChargingStations",
            "MeterValues",
            "StatusNotifications",
          ].includes(name)
        )
          throw e;
      }
    }
  console.log("Loopback-only CSMS GraphQL provisioning API ready.");
})().catch((e) => {
  console.error(e.message);
  process.exitCode = 1;
});
