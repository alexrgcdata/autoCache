import test from "node:test";
import assert from "node:assert/strict";
import {
  explicitTables,
  fingerprint,
  validate,
  changes,
} from "../src/model.ts";
import type { Metadata, Tables } from "../src/types.ts";

const metadata: Metadata = {
  metadata_version: "4",
  server_version: "test",
  tables: {
    Contacts: {
      entity: "Contacts",
      record_id_field: "ROWID",
      fields: [
        { name: "ROWID", type: "Edm.Int64" },
        { name: "Name", type: "Edm.String" },
        { name: "Photo", type: "Edm.Binary", binary: true },
      ],
    },
  },
};
const legacy: Tables = {
  Contacts: { entity: "Contacts", record_id_field: "ROWID", fields: [] },
};
test("legacy wildcard becomes an explicit snapshot without mutating original or including binary data", () => {
  const result = explicitTables(legacy, metadata);
  assert.deepEqual(result.Contacts.fields, ["Name", "ROWID"]);
  assert.deepEqual(legacy.Contacts.fields, []);
  metadata.tables.Contacts.fields.push({
    name: "PrivateNote",
    type: "Edm.String",
  });
  assert.equal(result.Contacts.fields.includes("PrivateNote"), false);
  metadata.tables.Contacts.fields.pop();
});
test("validation rejects missing identities, wildcard drafts and missing or binary source fields", () => {
  for (const fields of [
    [],
    ["Name"],
    ["ROWID", "Missing"],
    ["ROWID", "Photo"],
  ]) {
    assert.ok(
      validate({ Contacts: { ...legacy.Contacts, fields } }, metadata).length,
    );
  }
  assert.equal(validate(explicitTables(legacy, metadata), metadata).length, 0);
  assert.ok(validate({}, metadata).length);
});
test("identity options survive loading and remain selected", () => {
  const tables = {
    Contacts: {
      ...legacy.Contacts,
      fields: ["ROWID"],
      primary_key_field: "Name",
    },
  };
  assert.deepEqual(explicitTables(tables, metadata).Contacts.fields, [
    "Name",
    "ROWID",
  ]);
});
test("fingerprint ignores selection order and diff reports actual additions and removals", () => {
  const left = explicitTables(legacy, metadata);
  const right = structuredClone(left);
  right.Contacts.fields.reverse();
  assert.equal(fingerprint(left), fingerprint(right));
  right.Contacts.fields = ["ROWID", "Email"];
  assert.deepEqual(changes(left, right), {
    added: ["Contacts.Email"],
    removed: ["Contacts.Name"],
  });
});
