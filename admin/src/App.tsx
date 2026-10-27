import { useEffect, useRef, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { ApiError } from "./api";
import { LearningPage } from "./WorkspacePages";
import { IdentityPicker } from "./IdentityPicker";
import { ImportPage } from "./ImportPage";
import { RecordsPage } from "./RecordsPage";
import { SchemaTree } from "./SchemaTree";
import { SecurityPage } from "./SecurityPage";
import { changedIdentities, withPrimaryKey } from "./model";
import {
  changes,
  explicitTables,
  fingerprint,
  requiredFields,
  validate,
} from "./model";
import type { AdminApi, Mapping, Metadata, Session, Tables } from "./types";

function Brand() {
  return (
    <a
      className="brand"
      href="#"
      onClick={(event) => event.preventDefault()}
      aria-label="autoCache administration"
    >
      <span className="brandmark" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M4 16a9 9 0 1 1 16 0M12 12l5-5" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg></span>auto<span>Cache</span>
      <small>COMMUNITY</small>
    </a>
  );
}
// Direct community credits keep the demo free of contact submissions.
function Footer() {
  return <><aside className="services-callout" aria-label="Custom integration services"><div><span className="eyebrow">FREE TOOL. REAL PEOPLE BEHIND IT.</span><h2>Need your systems to work together?</h2><p>We build custom FileMaker, web and mobile integrations. Use autoCache yourself—or bring us the part that needs a custom fit.</p></div><div className="service-links"><a className="button primary" href="https://rgcdata.com" target="_blank" rel="noreferrer">Custom work with RGC Data ↗</a><a className="button secondary" href="https://alexseidler.dev" target="_blank" rel="noreferrer">Meet Alex Seidler ↗</a><a className="text-button" href="mailto:hello@alexseidler.dev">Talk to Alex · hello@alexseidler.dev</a></div></aside><footer className="community-footer">
    <div><span className="eyebrow">MADE FOR THE FILEMAKER COMMUNITY</span><strong>Built by <a href="https://alexseidler.dev" target="_blank" rel="noreferrer">Alex Seidler ↗</a></strong><p>Explore it. Understand it. Make it your own.</p><p><a href="https://github.com/alexrgcdata/autoCache" target="_blank" rel="noreferrer">Source &amp; docs on GitHub ↗</a></p><a href="mailto:hello@alexseidler.dev">hello@alexseidler.dev</a></div>
    <div className="footer-partner"><span className="eyebrow">IN COLLABORATION WITH</span><a href="https://rgcdata.com" target="_blank" rel="noreferrer"><strong>RGC Data Solutions ↗</strong></a><p>FileMaker, web &amp; mobile integration.</p><span className="pill">Community preview · 0.3.1</span></div>
  </footer></>;
}
function Modal({
  title,
  children,
  onClose,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = ref.current!;
    dialog.showModal();
    return () => dialog.close();
  }, []);
  return (
    <dialog
      aria-label={title}
      ref={ref}
      onCancel={(event) => {
        event.preventDefault();
        onClose();
      }}
    >
      <div className="dialog-heading">
        <h2>{title}</h2>
        <button
          className="icon-button"
          aria-label="Close dialog"
          onClick={onClose}
        >
          ×
        </button>
      </div>
      {children}
    </dialog>
  );
}

export function App({ api, sample }: { api: AdminApi; sample: boolean }) {
  const [session, setSession] = useState<Session>({ authenticated: false });
  const [booting, setBooting] = useState(true),
    [busy, setBusy] = useState(false);
  const [error, setError] = useState(""),
    [notice, setNotice] = useState("");
  const [metadata, setMetadata] = useState<Metadata | null>(null);
  const [mappings, setMappings] = useState<Mapping[]>([]);
  const [tables, setTables] = useState<Tables>({}),
    [baseline, setBaseline] = useState("{}");
  const [selected, setSelected] = useState(""),
    [search, setSearch] = useState(""),
    [fieldSearch, setFieldSearch] = useState("");
  const [view, setView] = useState("allowlist");
  const [previewMode, setPreviewMode] = useState<"tree" | "json">("tree");
  const [confirm, setConfirm] = useState<"save" | "logout" | null>(null);
  const [draftName, setDraftName] = useState("");
  const draftId = draftName.trim().replace(/\s+/g, "-");
  const [saved, setSaved] = useState<Mapping | null>(null);
  const [loadId, setLoadId] = useState("");
  const dirty = fingerprint(tables) !== baseline && metadata !== null;
  const active = mappings.find((mapping) => mapping.status === "active");
  const identityChanges = changedIdentities(active?.tables ?? {}, tables);
  const fieldCount = Object.values(tables).reduce(
    (sum, table) => sum + table.fields.length,
    0,
  );
  const problems = metadata ? validate(tables, metadata) : [];
  const diff = metadata
    ? changes(explicitTables(active?.tables ?? {}, metadata), tables)
    : { added: [], removed: [] };

  function report(caught: unknown) {
    if (
      caught instanceof ApiError &&
      caught.status === 401 &&
      session.authenticated
    ) {
      setSession({ authenticated: false });
      setTables({});
      setMetadata(null);
      setSaved(null);
      setConfirm(null);
      setError(
        "Your session ended. Sign in again. Unsaved selections were cleared.",
      );
    } else
      setError(
        caught instanceof Error ? caught.message : "Something went wrong.",
      );
  }
  function loadMapping(mapping: Mapping | undefined, source: Metadata) {
    const next = explicitTables(mapping?.tables ?? {}, source);
    setTables(next);
    setBaseline(fingerprint(next));
    setLoadId(mapping?.id ?? "");
    setSelected(Object.keys(next)[0] ?? Object.keys(source.tables)[0] ?? "");
    setSaved(mapping?.status === "draft" ? mapping : null);
    setNotice(
      mapping &&
        Object.values(mapping.tables).some((table) => !table.fields.length)
        ? "This mapping used all-fields rules. The editor has expanded them into today’s fields. Save a new draft to adopt explicit selections; the active mapping is unchanged."
        : "",
    );
  }
  async function workspace() {
    const [sourceResult, mappingResult] = await Promise.allSettled([
      api.discover(),
      api.mappings(),
    ]);
    // Cached records and help remain usable if source discovery is temporarily
    // unavailable. Never discard a successful mapping response with it.
    if (mappingResult.status === "fulfilled") setMappings(mappingResult.value.mappings);
    if (sourceResult.status === "rejected") throw sourceResult.reason;
    if (mappingResult.status === "rejected") throw mappingResult.reason;
    const source = sourceResult.value, result = mappingResult.value;
    setMetadata(source);
    setMappings(result.mappings);
    loadMapping(
      result.mappings.find((mapping) => mapping.status === "active"),
      source,
    );
  }
  useEffect(() => {
    let mounted = true;
    api
      .session()
      .then(async (identity) => {
        if (!mounted) return;
        setSession(identity);
        if (identity.authenticated) await workspace();
      })
      .catch((caught) => {
        if (mounted)
          setError(
            caught instanceof Error
              ? caught.message
              : "Could not restore session.",
          );
      })
      .finally(() => {
        if (mounted) setBooting(false);
      });
    return () => {
      mounted = false;
    };
  }, [api]);
  useEffect(() => {
    function warn(event: BeforeUnloadEvent) {
      if (dirty) event.preventDefault();
    }
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [dirty]);

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const values = new FormData(form);
    setBusy(true);
    setError("");
    try {
      setSession(
        await api.login(
          String(values.get("username") ?? ""),
          String(values.get("password") ?? ""),
        ),
      );
      form.reset();
      await workspace();
    } catch (caught) {
      report(caught);
    } finally {
      setBusy(false);
    }
  }
  function update(next: Tables) {
    setTables(next);
    setSaved(null);
    setNotice("");
  }
  function toggleTable(name: string) {
    if (!metadata) return;
    const next = { ...tables };
    if (next[name]) delete next[name];
    else {
      const source = metadata.tables[name];
      next[name] = {
        entity: source.entity,
        record_id_field: source.record_id_field,
        fields: [source.record_id_field],
      };
    }
    update(next);
  }
  function toggleField(name: string, field: string) {
    const table = tables[name];
    if (!table || requiredFields(table).includes(field)) return;
    update({
      ...tables,
      [name]: {
        ...table,
        fields: table.fields.includes(field)
          ? table.fields.filter((item) => item !== field)
          : [...table.fields, field],
      },
    });
  }
  async function save() {
    setBusy(true);
    setError("");
    try {
      const result = await api.save(draftId, tables);
      setMappings((current) => [
        result.mapping,
        ...current.filter((item) => item.id !== result.mapping.id),
      ]);
      setSaved(result.mapping);
      setBaseline(fingerprint(tables));
      setLoadId(result.mapping.id);
      setConfirm(null);
      setNotice(
        sample
          ? "Sample draft saved in memory. No database was contacted."
          : "Draft saved. Open Import to bring across your records, then explicitly activate this selection.",
      );
    } catch (caught) {
      setConfirm(null);
      report(caught);
    } finally {
      setBusy(false);
    }
  }
  async function logout() {
    setBusy(true);
    setError("");
    try {
      setSession(await api.logout());
      setMetadata(null);
      setTables({});
      setMappings([]);
      setSaved(null);
      setNotice("");
      setConfirm(null);
    } catch (caught) {
      setConfirm(null);
      report(caught);
    } finally {
      setBusy(false);
    }
  }
  function download() {
    const blob = new Blob([JSON.stringify({ tables }, null, 2) + "\n"], {
      type: "application/json",
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "autocache-field-mapping.json";
    anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  const source =
    metadata &&
    (metadata.tables[selected] ??
      Object.values(metadata.tables).find(
        (entity) => entity.entity === tables[selected]?.entity,
      ));
  const tableNames = metadata
    ? [
        ...new Set([...Object.keys(metadata.tables), ...Object.keys(tables)]),
      ].sort()
    : [];
  const selectedTable = tables[selected];
  const fields = source
    ? [
        ...source.fields,
        ...(selectedTable?.fields
          .filter((name) => !source.fields.some((field) => field.name === name))
          .map((name) => ({ name, type: "Unavailable", binary: false })) ?? []),
      ]
    : [];

  return (
    <div className={`app-shell ${session.authenticated ? "signed-in" : ""}`}>
      {sample && (
        <div className="sample-banner">
          DEMO WORKSPACE{" "}
          <span>Fictional data · no API requests · resets on refresh</span>
        </div>
      )}
      <header>
        <Brand />
        {session.authenticated && (
          <div className="account">
            <span className="avatar">
              {session.username?.slice(0, 1).toUpperCase()}
            </span>
            <span>{session.username}</span>
            <button
              className="text-button"
              disabled={busy}
              onClick={() => (dirty ? setConfirm("logout") : void logout())}
            >
              Sign out ↗
            </button>
          </div>
        )}
      </header>
      <main className={session.authenticated ? "workspace-layout" : ""}>
        {error && (
          <div className="alert error" role="alert">
            {error}
            <button aria-label="Dismiss error" onClick={() => setError("")}>
              ×
            </button>
          </div>
        )}
        {booting ? (
          <div className="loading" role="status">
            Restoring your workspace…
          </div>
        ) : !session.authenticated ? (
          <section className="login-layout">
            <div className="login-story">
              <span className="eyebrow">FILEMAKER, CONNECTED.</span>
              <h1>
                Your data.
                <br />
                Your boundaries.
              </h1>
              <p>
                A clear view of what leaves FileMaker.
                <br />
                Choose the tables and fields your cache needs,
                <br className="desktop-break" /> and keep everything else at the
                source.
              </p>
              <div className="connection-art" aria-hidden="true">
                <span>FileMaker</span>
                <i>······</i>
                <b>autoCache</b>
                <i>······</i>
                <span>Your app</span>
              </div>
              <div className="story-note">
                <span className="dot" /> Purposeful selection. Predictable
                delivery.
              </div>
            </div>
            <form className="login-card panel" onSubmit={login}>
              <span className="eyebrow">YOUR WORKSPACE</span>
              <h2>{sample ? "Take it for a spin." : "Welcome back."}</h2>
              <p>{sample ? "Explore the mapping workflow. No account needed." : "Sign in to manage your cache mappings."}</p>
              {!sample && <>
                <label>
                  Username
                  <input
                    name="username"
                    autoComplete="username"
                    required
                    maxLength={80}
                    autoFocus
                  />
                </label>
                <label>
                  Password
                  <input
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    maxLength={1024}
                  />
                </label>
              </>}
              <button className="button primary full" disabled={busy}>
                {busy
                  ? "Opening workspace…"
                  : sample
                    ? "Demo login →"
                    : "Sign in →"}
              </button>
              <small>
                {sample
                  ? "No password. No connection to FileMaker. Sample drafts stay in memory."
                  : "PHP verifies your password and remembers your login using a protected session cookie."}
              </small>
              {!sample && <a className="button secondary full" href="?demo=1">Try Demo login →</a>}
            </form>
          </section>
        ) : (
          <>
            <nav className="workspace-nav" aria-label="Administration">
              <span className="nav-label">DATA WORKSPACE</span>
              {[
                ["allowlist", "01", "Fields", "Choose your data"],
                ["import", "02", "Import", "Bring it across"],
                ["records", "03", "Cached records", "Find what you need"],
                ["security", "◇", "Security", "Know the boundaries"],
                ["learn", "?", "Help", "Quick answers"],
                ["settings", "⚙", "Connection", "Source & policy"],
              ].map(([id, icon, title, subtitle]) => <button key={id} className={view === id ? "active" : ""} aria-current={view === id ? "page" : undefined} onClick={() => setView(id)}><span aria-hidden="true">{icon}</span><span>{title}<small>{subtitle}</small></span></button>)}
              <div className="sidebar-note"><span className="dot" /><strong>{sample ? "Safe to explore" : "Explicit by design"}</strong><p>{sample ? "Sample data only. Your experiment ends on refresh." : "A working copy does not change the active cache."}</p></div>
            </nav>
            <div className="workspace-content">
            {view === "security" ? <SecurityPage sample={sample} /> : view === "learn" ? <LearningPage /> : view === "records" ? <RecordsPage key={active?.id ?? "none"} api={api} active={active} sample={sample} /> : view === "import" ? <ImportPage api={api} mappings={mappings} sample={sample} onActivated={async () => { const result = await api.mappings(); setMappings(result.mappings); }} onRecords={() => setView("records")} /> : !metadata ? (
              <section className="panel empty">
                <h2>Workspace unavailable</h2>
                <p>Check the error above, then reload the metadata.</p>
                <button
                  className="button secondary"
                  disabled={busy}
                  onClick={async () => {
                    setBusy(true);
                    setError("");
                    try {
                      await workspace();
                    } catch (caught) {
                      report(caught);
                    } finally {
                      setBusy(false);
                    }
                  }}
                >
                  Retry loading
                </button>
              </section>
            ) : view === "settings" ? (
              <>
                <div className="page-heading">
                  <div>
                    <span className="eyebrow">WORKSPACE</span>
                    <h1>Keep it simple.</h1>
                    <p>The essentials behind your connection.</p>
                  </div>
                  <span className="pill">Read only</span>
                </div>
                <section className="panel settings">
                  <h2>Connection & policy</h2>
                  <dl>
                    <div>
                      <dt>Signed in as</dt>
                      <dd>{session.username}</dd>
                    </div>
                    <div>
                      <dt>Active allow list</dt>
                      <dd>{active?.id ?? "None"}</dd>
                    </div>
                    <div>
                      <dt>Available source tables</dt>
                      <dd>{Object.keys(metadata.tables).length}</dd>
                    </div>
                    <div>
                      <dt>OData metadata version</dt>
                      <dd>{metadata.metadata_version}</dd>
                    </div>
                    <div>
                      <dt>Server version</dt>
                      <dd>{metadata.server_version || "Not supplied"}</dd>
                    </div>
                    <div>
                      <dt>New field policy</dt>
                      <dd>Explicit selection required</dd>
                    </div>
                  </dl>
                  <p>
                    Credentials are managed in your private PHP configuration.
                    New source fields stay excluded from drafts created here
                    until selected.
                  </p>
                  <p>
                    Pause and resume controls are deferred. Pausing FileMaker
                    writers during import remains an operator step.
                  </p>
                </section>
              </>
            ) : (
              <>
                <div className="page-heading">
                  <div>
                    <span className="eyebrow">DEFINE WHAT FLOWS</span>
                    <h1>Choose your fields.</h1>
                    <p>
                      Tables, identity and fields. Your selection, made explicit.
                    </p>
                  </div>
                  <div className="status">
                    <span className="dot" />
                    {active ? `Active: ${active.id}` : "No active mapping"}
                  </div>
                </div>
                {notice && (
                  <div className="alert" role="status">
                    {notice}
                  </div>
                )}
                <details className="quick-help"><summary>New here? Choose a table, check its identity, then select fields.</summary><p><strong>ROWID</strong> is FileMaker’s internal record address. It is included automatically so the cache can find updates and deletes. A <strong>primary key</strong> is your own unique field, such as a UUID or customer number. Keep ROWID fallback for a simple start, or choose a stable, unique, non-empty field. Save a draft, then open Import.</p></details>
                <section className="summary-grid">
                  <div>
                    <span>SELECTED TABLES</span>
                    <strong>
                      {Object.keys(tables).length.toString().padStart(2, "0")}
                    </strong>
                    <small>of {tableNames.length} available</small>
                  </div>
                  <div>
                    <span>SELECTED FIELDS</span>
                    <strong>{fieldCount.toString().padStart(2, "0")}</strong>
                    <small>including record identity</small>
                  </div>
                  <div>
                    <span>SELECTION POLICY</span>
                    <strong className="policy">
                      Explicit fields <span>↗</span>
                    </strong>
                    <small>Future fields require your approval</small>
                  </div>
                </section>
                <div className="section-heading">
                  <div>
                    <span className="step">01</span>
                    <h2>Build your selection</h2>
                  </div>
                  <span>Changes stay local until you save</span>
                </div>
                <section className="selector panel" id="field-editor">
                  <div className="tables-pane">
                    <div className="pane-heading">
                      <h3>Source tables</h3>
                      <span>{tableNames.length}</span>
                    </div>
                    <label className="search">
                      <span className="sr-only">Search tables</span>
                      <input
                        placeholder="Search tables…"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                      />
                    </label>
                    <div className="table-list">
                      {tableNames
                        .filter((name) =>
                          name.toLowerCase().includes(search.toLowerCase()),
                        )
                        .map((name) => (
                          <div
                            className={`table-row ${selected === name ? "current" : ""}`}
                            key={name}
                          >
                            <input
                              type="checkbox"
                              aria-label={`Include ${name}`}
                              checked={Boolean(tables[name])}
                              onChange={() => toggleTable(name)}
                            />
                            <button
                              onClick={() => {
                                setSelected(name);
                                setFieldSearch("");
                              }}
                            >
                              <span>{name}</span>
                              <small>
                                {tables[name]?.fields.length ?? 0} selected
                              </small>
                            </button>
                            <span>›</span>
                          </div>
                        ))}
                      {!tableNames.some((name) =>
                        name.toLowerCase().includes(search.toLowerCase()),
                      ) && <p className="empty">No matching tables.</p>}
                    </div>
                    <div className="pane-note">
                      Select a table to include it.
                      <br />
                      Click its name to explore fields.
                    </div>
                  </div>
                  <div className="fields-pane">
                    <div className="pane-heading">
                      <div>
                        <span className="eyebrow">FIELDS IN</span>
                        <h3>{selected || "Choose a table"}</h3>
                      </div>
                      <span className="pill">
                        {selectedTable?.fields.length ?? 0} selected
                      </span>
                    </div>
                    {selectedTable && source && <IdentityPicker table={selectedTable} entity={source} onChange={(key) => {
                      try { update({ ...tables, [selected]: withPrimaryKey(selectedTable, source, key) }); } catch (caught) { report(caught); }
                    }} />}
                    <div className="field-toolbar">
                      <label className="search">
                        <span className="sr-only">Search fields</span>
                        <input
                          placeholder="Find a field…"
                          value={fieldSearch}
                          onChange={(event) =>
                            setFieldSearch(event.target.value)
                          }
                        />
                      </label>
                      <button
                        className="text-button"
                        disabled={!selectedTable || !source}
                        onClick={() => {
                          if (selectedTable && source)
                            update({
                              ...tables,
                              [selected]: {
                                ...selectedTable,
                                fields: [
                                  ...new Set([
                                    ...source.fields
                                      .filter((field) => !field.binary)
                                      .map((field) => field.name),
                                    ...requiredFields(selectedTable),
                                  ]),
                                ],
                              },
                            });
                        }}
                      >
                        Select all current
                      </button>
                      <button
                        className="text-button"
                        disabled={!selectedTable}
                        onClick={() =>
                          selectedTable &&
                          update({
                            ...tables,
                            [selected]: {
                              ...selectedTable,
                              fields: requiredFields(selectedTable),
                            },
                          })
                        }
                      >
                        Clear optional
                      </button>
                    </div>
                    {!selectedTable && (
                      <p className="inline-hint">
                        Include this table on the left to select its fields.
                      </p>
                    )}
                    <div className="field-grid">
                      {fields
                        .filter((field) =>
                          field.name
                            .toLowerCase()
                            .includes(fieldSearch.toLowerCase()),
                        )
                        .map((field) => {
                          const checked = Boolean(
                            selectedTable?.fields.includes(field.name),
                          );
                          const locked = Boolean(
                            selectedTable &&
                            requiredFields(selectedTable).includes(field.name),
                          );
                          return (
                            <label
                              key={field.name}
                              className={`field ${checked ? "checked" : ""} ${field.binary ? "unsupported" : ""}`}
                            >
                              <input
                                type="checkbox"
                                checked={checked}
                                disabled={
                                  !selectedTable || locked || field.binary
                                }
                                onChange={() =>
                                  toggleField(selected, field.name)
                                }
                              />
                              <span>
                                <strong>{field.name}</strong>
                                <small>
                                  {locked
                                    ? "Required identity"
                                    : field.binary
                                      ? "Binary · unsupported"
                                      : field.type.replace("Edm.", "")}
                                </small>
                              </span>
                              <span className="field-end" aria-hidden="true">
                                {locked ? "◆" : checked ? "×" : "+"}
                              </span>
                            </label>
                          );
                        })}
                    </div>
                    {fields.length > 0 && !fields.some(field => field.name.toLowerCase().includes(fieldSearch.toLowerCase())) && <p className="empty">No fields match your search.</p>}
                    {!fields.length && (
                      <p className="empty">No fields available.</p>
                    )}
                  </div>
                </section>
                <div className="section-heading">
                  <div>
                    <span className="step">02</span>
                    <h2>Review your structure</h2>
                  </div>
                  <button
                    className="text-button"
                    disabled={!Object.keys(tables).length}
                    onClick={download}
                  >
                    ↓ Export JSON
                  </button>
                </div>
                <SchemaTree metadata={metadata} tables={tables} onEdit={name => { setSelected(name); setFieldSearch(""); document.getElementById("field-editor")?.scrollIntoView({ behavior: "smooth", block: "start" }); }} />
                <section className="panel preview">
                  <div className="preview-heading">
                    <div>
                      <h3>Allow list preview</h3>
                      <p>
                        This is the exact field selection your new draft will
                        contain.
                      </p>
                    </div>
                    <span className={`pill ${dirty ? "amber" : ""}`}>
                      {dirty
                        ? "Unsaved changes"
                        : saved
                          ? "Saved draft"
                          : "Working copy"}
                    </span>
                  </div>
                  <div className="preview-tabs" aria-label="Preview format"><button className={previewMode === "tree" ? "active" : ""} aria-pressed={previewMode === "tree"} onClick={() => setPreviewMode("tree")}>Tree view</button><button className={previewMode === "json" ? "active" : ""} aria-pressed={previewMode === "json"} onClick={() => setPreviewMode("json")}>JSON document</button><small>Mapping only · no records or secrets</small></div>
                  {previewMode === "json" && <pre className="json-preview" tabIndex={0} aria-label="Mapping JSON">{JSON.stringify({ tables }, null, 2)}</pre>}
                  <div className="tree" hidden={previewMode !== "tree"}>
                    {Object.entries(tables).map(([name, table]) => (
                      <details key={name} open>
                        <summary>
                          <span>▤ {name}</span>
                          <small>{table.primary_key_field || "ROWID fallback"} · {table.fields.length} fields</small>
                        </summary>
                        <div className="tree-fields">
                          {table.fields.map((field) => (
                            <span key={field}>
                              {field}
                              {requiredFields(table).includes(field) ? (
                                <small> ID</small>
                              ) : (
                                <button
                                  aria-label={`Remove ${name}.${field}`}
                                  onClick={() => toggleField(name, field)}
                                >
                                  ×
                                </button>
                              )}
                            </span>
                          ))}
                        </div>
                      </details>
                    ))}
                    {!Object.keys(tables).length && (
                      <div className="empty">
                        Your selected tables and fields will appear here.
                      </div>
                    )}
                  </div>
                  {identityChanges.length > 0 && <p className="identity-warning" role="status">Identity changed for {identityChanges.join(", ")}. Save a new draft, import it and explicitly activate it. The active mapping is unchanged.</p>}
                  <div className="diff">
                    <span>
                      <b>+{diff.added.length}</b> fields added
                    </span>
                    <span>
                      <b>−{diff.removed.length}</b> fields removed
                    </span>
                    <small>
                      Compared with the active mapping’s current fields
                    </small>
                  </div>
                  <div className="save-bar">
                    <div>
                      <strong>
                        {saved ? `Draft: ${saved.id}` : "Ready for review?"}
                      </strong>
                      <p>Saving a draft does not change the active cache.</p>
                    </div>
                    <button
                      className="button primary"
                      disabled={busy || problems.length > 0}
                      onClick={() => {
                        setDraftName("");
                        setConfirm("save");
                      }}
                    >
                      Save Draft →
                    </button>
                  </div>
                  {problems.length > 0 && (
                    <ul className="validation">
                      {problems.map((problem) => (
                        <li key={problem}>{problem}</li>
                      ))}
                    </ul>
                  )}
                </section>
                <section className="saved-section">
                  <div>
                    <h3>Continue a saved selection</h3>
                    <p>
                      Load a mapping as a working copy. Every save creates a new
                      draft.
                    </p>
                  </div>
                  <div className="actions">
                    <label>
                      <span className="sr-only">Mapping to load</span>
                      <select
                        value={loadId}
                        onChange={(event) => setLoadId(event.target.value)}
                      >
                        <option value="">Empty selection</option>
                        {mappings.map((mapping) => (
                          <option key={mapping.id} value={mapping.id}>
                            {mapping.id} · {mapping.status}
                          </option>
                        ))}
                      </select>
                    </label>
                    <button
                      className="button secondary"
                      disabled={dirty || busy}
                      onClick={() =>
                        loadMapping(
                          mappings.find((mapping) => mapping.id === loadId),
                          metadata,
                        )
                      }
                    >
                      Load
                    </button>
                    <button
                      className="text-button"
                      disabled={busy}
                      onClick={async () => {
                        setBusy(true);
                        try {
                          setMappings((await api.mappings()).mappings);
                          setNotice(
                            "Saved mapping list refreshed. Your selections were preserved.",
                          );
                        } catch (caught) {
                          report(caught);
                        } finally {
                          setBusy(false);
                        }
                      }}
                    >
                      Refresh list
                    </button>
                  </div>
                  {dirty && (
                    <small>
                      Save your work or reload the page to discard it before
                      loading another mapping.
                    </small>
                  )}
                </section>
              </>
            )}
            </div>
          </>
        )}
      </main>
      <Footer />
      {confirm === "save" && (
        <Modal
          title="Save this allow list as a draft?"
          onClose={() => !busy && setConfirm(null)}
        >
          <p>
            You’re submitting {Object.keys(tables).length} tables and{" "}
            {fieldCount} explicit fields as one package. The active mapping will
            remain unchanged.
          </p>
          <label>
            Draft name
            <input
              value={draftName}
              maxLength={80}
              required
              placeholder="e.g. Customer portal"
              onChange={(event) => setDraftName(event.target.value)}
              disabled={busy}
            />
          </label>
          <p className="inline-hint">
            Next steps are a controlled import and explicit activation. Those
            operations are available in the Import tab. Saving here does not
            start an import or change your active cache.
          </p>
          <div className="actions dialog-actions">
            <button
              className="button secondary"
              disabled={busy}
              onClick={() => setConfirm(null)}
            >
              Keep editing
            </button>
            <button
              className="button primary"
              disabled={
                busy ||
                !/^[A-Za-z0-9_-]{1,80}$/.test(draftId) ||
                mappings.some((mapping) => mapping.id === draftId)
              }
              onClick={() => void save()}
            >
              {busy ? "Saving…" : "Confirm & save draft"}
            </button>
          </div>
          <small>
            Choose a unique name using letters, numbers, spaces, underscores or
            hyphens. Spaces become hyphens in the saved name.
          </small>
          {draftId && (
            <p className="draft-name-preview" role="status">
              {mappings.some((mapping) => mapping.id === draftId)
                ? "That name is already in use. Choose another name."
                : `Saved as: ${draftId}`}
            </p>
          )}
        </Modal>
      )}
      {confirm === "logout" && (
        <Modal
          title="Discard unsaved changes?"
          onClose={() => !busy && setConfirm(null)}
        >
          <p>
            Signing out clears your local selections. Your saved mappings are
            safe.
          </p>
          <div className="actions dialog-actions">
            <button
              className="button secondary"
              disabled={busy}
              onClick={() => setConfirm(null)}
            >
              Keep editing
            </button>
            <button
              className="button primary"
              disabled={busy}
              onClick={() => void logout()}
            >
              Discard & sign out
            </button>
          </div>
        </Modal>
      )}
    </div>
  );
}
