import { useEffect, useRef, useState } from "react";
import type { AdminApi, CachedRecord, Mapping, RecordPage } from "./types";

export const displayValue = (value: unknown): string => value === null ? "null" : typeof value === "object" ? JSON.stringify(value) : String(value);

export function RecordsPage({ api, active, sample }: { api: AdminApi; active?: Mapping; sample: boolean }) {
  const names = Object.keys(active?.tables ?? {});
  const [table, setTable] = useState(names[0] ?? ""), [query, setQuery] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState<RecordPage | null>(null), [error, setError] = useState("");
  const [busy, setBusy] = useState(false), [cursor, setCursor] = useState("0");
  const [history, setHistory] = useState<string[]>([]), [selected, setSelected] = useState<CachedRecord | null>(null);
  const request = useRef(0);
  const detail = useRef<HTMLElement>(null);
  useEffect(() => { if (selected) { detail.current?.scrollIntoView({ block: "nearest" }); detail.current?.focus({ preventScroll: true }); } }, [selected]);
  useEffect(() => {
    const timer = setTimeout(() => { setSearch(query.trim()); setCursor("0"); setHistory([]); }, 300);
    return () => clearTimeout(timer);
  }, [query]);
  useEffect(() => {
    const ticket = ++request.current;
    setPage(null); setSelected(null); setError("");
    if (!table) return;
    setBusy(true);
    api.records(table, search, cursor).then(result => {
      if (ticket === request.current) {
        if (!Array.isArray(result.records) || !result.mapping_id || !result.records.every(row => typeof row.id === "string" && row.fields && typeof row.fields === "object" && !Array.isArray(row.fields))) throw new Error("Unexpected record response.");
        if (result.mapping_id !== active?.id) throw new Error("The active mapping changed. Refresh this workspace before browsing its records.");
        setPage(result);
      }
    }).catch(caught => { if (ticket === request.current) setError(caught instanceof Error ? caught.message : "Could not load records."); })
      .finally(() => { if (ticket === request.current) setBusy(false); });
    return () => { request.current++; };
  }, [api, table, search, cursor, active?.id]);
  const fields = active?.tables[table]?.fields ?? [];
  return <>
    <div className="page-heading"><div><span className="eyebrow">03 / CACHED RECORDS</span><h1>Find the record you need.</h1><p>Search field values across this table’s active cache. Try a name, company, email or number.</p></div><span className="pill">{sample ? "Fictional records" : "Read only"}</span></div>
    {!active ? <section className="panel empty">No active mapping. Import a saved draft and activate it first.</section> : <>
      <section className="panel record-browser">
        <div className="record-toolbar"><label>Table<select value={table} onChange={event => { setTable(event.target.value); setCursor("0"); setHistory([]); setQuery(""); setSearch(""); }}>{names.map(name => <option key={name}>{name}</option>)}</select></label><label className="record-search">Search record contents<input type="search" value={query} maxLength={160} onChange={event => setQuery(event.target.value)} placeholder="Name, email, company, amount…" aria-describedby="record-search-help" /></label></div>
        <p id="record-search-help" className="toolbar-hint">Searches all selected field values in this table, not just this page. {query !== search && "Updating search…"} Names of fields are not searched.</p>
        {error && <p className="alert error" role="alert">{error} Try a shorter search or check your connection.</p>}
        <div className={`records-scroll ${page && page.records.length < 8 ? "few-records" : ""}`} tabIndex={0} role="region" aria-label="Cached records table" aria-busy={busy}>
          <table className="records-table"><thead><tr><th scope="col">Record</th>{fields.map(field => <th scope="col" key={field}>{field}</th>)}</tr></thead><tbody>{page?.records.map(record => <tr key={record.id}><th scope="row"><button className="text-button" onClick={() => setSelected(record)} aria-label={`Open record ${record.id}`}>#{record.id} ↗</button></th>{fields.map(field => <td key={field}><span title={displayValue(record.fields[field] ?? "")}>{record.omitted ? "Too large to preview" : displayValue(record.fields[field] ?? "—")}</span></td>)}</tr>)}</tbody></table>
          {busy && <p className="empty" role="status">Searching cached records…</p>}{!busy && !error && !page?.records.length && <p className="empty">{search ? `No records match “${search}”. Try another value or clear the search.` : "No cached records in this table yet."}</p>}
        </div>
        <div className="record-pagination"><span role="status">{page ? `${page.records.length} records on this page · mapping ${page.mapping_id}` : ""}</span><div className="actions"><button className="button secondary" disabled={busy || !history.length || query.trim() !== search} onClick={() => { setCursor(history.at(-1)!); setHistory(history.slice(0,-1)); }}>Previous</button><button className="button secondary" disabled={busy || !page?.next_after_id || query.trim() !== search} onClick={() => { setHistory([...history, cursor]); setCursor(page!.next_after_id!); }}>Next 25 →</button></div></div>
      </section>
      {selected && <section ref={detail} tabIndex={-1} className="panel record-detail" aria-label="Record details"><div className="preview-heading"><div><span className="eyebrow">RECORD #{selected.id}</span><h2>{selected.primary_key ? `Primary key: ${selected.primary_key}` : "ROWID identity"}</h2><p>Cached at {selected.synced_at}. This is the cached snapshot, not a fresh FileMaker read.</p></div><button className="button secondary" onClick={() => setSelected(null)}>Close details</button></div>{selected.omitted ? <p className="empty">This record exceeds the 1 MB browser preview limit. Use an authorized server-side reader to inspect it.</p> : <dl>{Object.entries(selected.fields).map(([key,value]) => <div key={key}><dt>{key}</dt><dd>{displayValue(value)}</dd></div>)}</dl>}</section>}
      <details className="quick-help"><summary>What am I searching, and can I edit these records?</summary><p>This view reads the active MySQL cache, not live FileMaker. Matching is case-insensitive and treats % and _ as literal characters. Record edits belong in your source database. A search scans the table’s selected fields; very large tables may reach the server’s query time limit. No total count is guessed.</p></details>
    </>}
  </>;
}
