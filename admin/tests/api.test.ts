import test from "node:test";
import assert from "node:assert/strict";
import { createApi, ApiError } from "../src/api.ts";

test("same-origin API uses session CSRF and sends a complete draft only on explicit save", async () => {
  const originalFetch = globalThis.fetch;
  const originalWindow = Object.getOwnPropertyDescriptor(globalThis, "window");
  Object.defineProperty(globalThis, "window", {
    value: { location: { pathname: "/rgccache/admin/index.html" } },
    configurable: true,
  });
  const calls: { url: string; options?: RequestInit }[] = [];
  globalThis.fetch = async (url, options) => {
    calls.push({ url: String(url), options });
    if (String(url).endsWith("login"))
      return Response.json({
        authenticated: true,
        username: "test",
        csrf_token: "csrf-fixture",
      });
    if (String(url).endsWith("mappings"))
      return Response.json(
        { error: "admin_authentication_required" },
        { status: 401 },
      );
    return Response.json({ mapping: { id: "draft_test", status: "draft" } });
  };
  try {
    const api = createApi();
    assert.equal(calls.length, 0);
    await api.login("test", "fixture-password");
    const tables = {
      Contacts: {
        entity: "Contacts",
        record_id_field: "ROWID",
        fields: ["ROWID", "Name"],
      },
    };
    assert.equal(calls.length, 1);
    await api.save("draft_test", tables);
    assert.equal(calls[1].url, "/rgccache/api/v1/admin/mapping/draft");
    assert.equal(calls[1].options?.credentials, "same-origin");
    assert.equal(
      (calls[1].options?.headers as Record<string, string>)["X-CSRF-Token"],
      "csrf-fixture",
    );
    assert.deepEqual(JSON.parse(String(calls[1].options?.body)), {
      id: "draft_test",
      tables,
    });
    assert.equal(
      String(calls[1].options?.body).includes("fixture-password"),
      false,
    );
    await api.records("Contacts", "person@example.test", "9007199254740993");
    assert.equal(calls[2].url, "/rgccache/api/v1/admin/records/query");
    assert.equal(calls[2].options?.method, "POST");
    assert.deepEqual(JSON.parse(String(calls[2].options?.body)), { table: "Contacts", query: "person@example.test", after: "9007199254740993" });
    assert.equal((calls[2].options?.headers as Record<string,string>)["X-CSRF-Token"], "csrf-fixture");
    await api.importStart("draft_test");
    assert.deepEqual(JSON.parse(String(calls[3].options?.body)), { id: "draft_test", source_writes_paused: true });
    await api.activate("draft_test");
    assert.equal(calls[4].url, "/rgccache/api/v1/admin/mapping/activate");
    await assert.rejects(
      api.mappings(),
      (error) => error instanceof ApiError && error.status === 401,
    );
  } finally {
    globalThis.fetch = originalFetch;
    if (originalWindow)
      Object.defineProperty(globalThis, "window", originalWindow);
    else Reflect.deleteProperty(globalThis, "window");
  }
});
