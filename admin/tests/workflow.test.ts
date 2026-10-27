import test from "node:test";
import assert from "node:assert/strict";
import { createSampleApi } from "../src/sample.ts";

test("demo import cannot activate early, resumes progress and switches scope only on activation", async () => {
  const api = createSampleApi();
  await api.login("", "");
  const original = (await api.mappings()).mappings[0];
  await api.save("review", original.tables);
  await assert.rejects(api.activate("review"), /Finish the import/);
  assert.equal((await api.importStatus("review")).state, "not_started");
  await api.importStart("review");
  const partial = await api.importStep("review");
  assert.equal(partial.state, "importing");
  assert.deepEqual(await api.importStart("review"), partial);
  assert.equal((await api.records("Contacts", "")).mapping_id, "sample_active");
  assert.equal((await api.importStep("review")).state, "done");
  await api.activate("review");
  assert.equal((await api.records("Contacts", "")).mapping_id, "review");
  assert.equal((await api.mappings()).mappings.filter(mapping => mapping.status === "active").length, 1);
});

test("demo searches selected contents across pages, supports pagination and never calls fetch", async () => {
  const savedFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error("Demo attempted network access"); };
  try {
    const api = createSampleApi();
    await assert.rejects(api.records("Contacts", ""));
    await api.login("", "");
    const first = await api.records("Contacts", "");
    assert.equal(first.records.length, 25);
    const second = await api.records("Contacts", "", first.next_after_id!);
    assert.equal(second.records[0].id, "26");
    const result = await api.records("Contacts", "PERSON77@");
    assert.equal(result.records[0].id, "77");
    assert.equal((await api.records("Contacts", "FirstName")).records.length, 0);
    assert.equal((await api.records("Contacts", "%_")).records.length, 0);
    await assert.rejects(api.records("Unselected", ""));
    await api.logout();
    await assert.rejects(api.records("Contacts", ""));
  } finally { globalThis.fetch = savedFetch; }
});
