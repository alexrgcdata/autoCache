import type { AdminApi, CachedRecord, ImportProgress, Mapping, Metadata } from "./types";

const field = (name: string, type = "Edm.String") => ({ name, type });
const metadata: Metadata = {
  metadata_version: "4.0",
  server_version: "Sample workspace",
  tables: Object.fromEntries(
    [
      [
        "Contacts",
        [
          "FirstName",
          "LastName",
          "Company",
          "Email",
          "Phone",
          "City",
          "CreatedAt",
        ],
      ],
      [
        "Invoices",
        ["InvoiceNumber", "ContactID", "Amount", "Status", "DueDate"],
      ],
      [
        "Projects",
        ["ProjectName", "ContactID", "Status", "Budget", "StartDate"],
      ],
      ["LineItems", ["InvoiceID", "Description", "Quantity", "UnitPrice"]],
    ].map(([name, names]) => [
      name,
      {
        entity: name,
        record_id_field: "ROWID",
        declared_primary_keys: ["ID"],
        suggested_primary_key_field: "ID",
        fields: [
          field("ROWID", "Edm.Int64"),
          field("ID", "Edm.Guid"),
          ...(names as string[]).map((name) => field(name)),
        ],
      },
    ]),
  ) as Metadata["tables"],
};

export function createSampleApi(): AdminApi {
  let authenticated = false;
  const mappings: Mapping[] = [
    {
      id: "sample_active",
      status: "active",
      tables: {
        Contacts: {
          entity: "Contacts",
          record_id_field: "ROWID",
          fields: ["ROWID", "FirstName", "LastName", "Company", "Email"],
        },
        Invoices: {
          entity: "Invoices",
          record_id_field: "ROWID",
          fields: ["ROWID", "InvoiceNumber", "Amount", "Status"],
        },
      },
    },
  ];
  const session = async () => ({
    authenticated,
    username: authenticated ? "Sample administrator" : undefined,
  });
  const imports = new Map<string, ImportProgress>();
  function requireDemo() { if (!authenticated) throw new Error("Open the demo workspace first."); }
  function draft(id: string) { requireDemo(); const found = mappings.find(mapping => mapping.id === id && mapping.status === "draft"); if (!found) throw new Error("Choose a saved draft."); return found; }
  const progress = (id: string): ImportProgress => structuredClone(imports.get(id) ?? { state: "not_started", tables: [] });
  function rows(table: string, fields: string[]): CachedRecord[] {
    return Array.from({length: 83}, (_, index) => {
      const id = String(index + 1);
      const values: Record<string, unknown> = { ROWID: id, ID: `sample-${table.toLowerCase()}-${id}`, FirstName: ["Maya", "Theo", "Nora", "Eli", "Sofia"][index % 5], LastName: ["Chen", "Rivera", "Patel", "Morgan", "Brooks"][index % 5], Company: index % 3 ? "Cedar Studio" : "Northwind Workshop", Email: `person${id}@example.test`, City: index % 2 ? "Portland" : "Madison", Phone: `555-01${String(index).padStart(2,"0")}`, CreatedAt: "2026-10-01", InvoiceNumber: `INV-${1000 + index}`, ContactID: String(index % 20 + 1), Amount: 125 + index * 10, Status: index % 2 ? "Open" : "Complete", DueDate: "2026-11-01", ProjectName: `Community project ${id}`, Budget: 2000 + index * 100, StartDate: "2026-10-01", InvoiceID: id, Description: index === 0 ? "A literal <script> example, 100% text" : "Consultation", Quantity: index + 1, UnitPrice: 125 };
      return { id, primary_key: null, fields: Object.fromEntries(fields.map(field => [field, values[field] ?? ""])), synced_at: "2026-10-27 10:00:00 UTC" };
    });
  }
  return {
    importStatus: async id => { draft(id); return progress(id); },
    importStart: async id => { const mapping = draft(id); if (!imports.has(id)) imports.set(id, { state: "importing", tables: Object.keys(mapping.tables).map(table => ({ table_name: table, after_id: "0", done: 0 })) }); return progress(id); },
    importStep: async id => { draft(id); const state = imports.get(id); if (!state) throw new Error("Start the import first."); const next = state.tables.find(table => !Number(table.done)); if (next) { next.done = 1; next.after_id = "83"; } if (state.tables.every(table => Number(table.done))) state.state = "done"; return progress(id); },
    activate: async id => { const mapping = draft(id); if (progress(id).state !== "done") throw new Error("Finish the import before activation."); mappings.forEach(item => { if (item.status === "active") item.status = "retired"; }); mapping.status = "active"; return { mapping: structuredClone(mapping) }; },
    records: async (table, query, after = "0") => { requireDemo(); const active = mappings.find(mapping => mapping.status === "active"); const selected = active?.tables[table]; if (!active || !selected) throw new Error("Choose a table in the active mapping."); const matching = rows(table, selected.fields).map(row => ({ ...row, primary_key: selected.primary_key_field ? String(row.fields[selected.primary_key_field]) : null })).filter(row => BigInt(row.id) > BigInt(after) && Object.values(row.fields).some(value => String(value).toLowerCase().includes(query.toLowerCase()))); return { mapping_id: active.id, records: matching.slice(0,25), next_after_id: matching.length > 25 ? matching[24].id : null }; },
    contactSession: async () => ({ csrf_token: "sample-contact" }),
    contact: async () => ({ accepted: true }),
    session,
    // Local simulation only. No password, cookies, network or production session.
    login: async () => {
      authenticated = true;
      return session();
    },
    logout: async () => {
      authenticated = false;
      return session();
    },
    discover: async () => structuredClone(metadata),
    mappings: async () => ({ mappings: structuredClone(mappings) }),
    save: async (id, tables) => {
      if (!authenticated) throw new Error("Open the demo workspace first.");
      if (mappings.some(mapping => mapping.id === id)) throw new Error("Choose a new draft name.");
      const mapping: Mapping = {
        id,
        status: "draft",
        tables: structuredClone(tables),
      };
      mappings.unshift(mapping);
      return { mapping };
    },
  };
}
