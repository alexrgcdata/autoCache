import test from "node:test";
import assert from "node:assert/strict";
import { primaryKeyCandidates, withPrimaryKey, requiredFields, changedIdentities, validate } from "../src/model.ts";
import { createSampleApi } from "../src/sample.ts";
import type { Entity, TableMap } from "../src/types.ts";

const entity: Entity = {
  entity: "Products", record_id_field: "ROWID", declared_primary_keys: ["ID"], suggested_primary_key_field: "ID",
  fields: [
    { name: "ROWID", type: "Edm.Int64", builtin: true },
    { name: "ID", type: "Edm.Guid" },
    { name: "SKU", type: "Edm.String" },
    { name: "Photo", type: "Edm.Binary", binary: true },
    { name: "GlobalID", type: "Edm.String", global: true },
    { name: "CalculatedID", type: "Edm.String", calculation: true },
  ],
};
const table: TableMap = { entity: "Products", record_id_field: "ROWID", fields: ["ROWID"] };

test("eligible identity choices include overrides, not binary/global/calculation fields or ROWID", () => {
  assert.deepEqual(primaryKeyCandidates(entity).map(field => field.name), ["ID", "SKU"]);
  assert.equal(table.primary_key_field, undefined, "metadata never automatically changes the draft");
});
test("key selection preserves source ID, locks selected key and does not mutate the original", () => {
  const next = withPrimaryKey(table, entity, "ID");
  assert.deepEqual(next.fields, ["ID", "ROWID"]);
  assert.deepEqual(requiredFields(next), ["ROWID", "ID"]);
  assert.deepEqual(table.fields, ["ROWID"]);
  assert.equal(validate({ Products: next }, { metadata_version: "4", server_version: "sample", tables: { Products: entity } }).length, 0);
});
test("override and ROWID fallback preserve previously selected fields and remove only the business-key option", () => {
  const next = withPrimaryKey(withPrimaryKey(table, entity, "ID"), entity, "SKU");
  assert.deepEqual(next.fields, ["ID", "ROWID", "SKU"]);
  const fallback = withPrimaryKey(next, entity, "");
  assert.equal(Object.hasOwn(fallback, "primary_key_field"), false);
  assert.deepEqual(fallback.fields, next.fields);
  assert.deepEqual(requiredFields(fallback), ["ROWID"]);
});
test("unknown and unsupported key choices fail instead of silently falling back", () => {
  for (const key of ["Missing", "Photo", "GlobalID", "CalculatedID", "ROWID"]) assert.throws(() => withPrimaryKey(table, entity, key));
});
test("identity-only edits are detected independently of field selection", () => {
  const before = withPrimaryKey(table, entity, "ID");
  const after = withPrimaryKey(before, entity, "");
  assert.deepEqual(before.fields, after.fields);
  assert.deepEqual(changedIdentities({ Products: before }, { Products: after }), ["Products"]);
});
test("no-key metadata supports explicit ROWID fallback", () => {
  const bare = { ...entity, fields: [entity.fields[0]], declared_primary_keys: [], suggested_primary_key_field: null };
  assert.deepEqual(primaryKeyCandidates(bare), []);
  assert.equal(withPrimaryKey(table, bare, "").primary_key_field, undefined);
});
test("demo login requires no credentials and saved drafts cannot alter the active sample", async () => {
  const api = createSampleApi();
  assert.equal((await api.session()).authenticated, false);
  assert.equal((await api.login("", "")).authenticated, true);
  const original = (await api.mappings()).mappings[0];
  const draft = structuredClone(original.tables);
  draft.Contacts.primary_key_field = "ID"; draft.Contacts.fields.push("ID");
  await api.save("demo-draft", draft);
  await assert.rejects(api.save("demo-draft", draft), /new draft/);
  assert.deepEqual((await api.mappings()).mappings.find(mapping => mapping.status === "active"), original);
  await api.logout();
  assert.equal((await api.session()).authenticated, false);
  assert.equal((await createSampleApi().mappings()).mappings.length, 1, "refresh starts clean");
});
