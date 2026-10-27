import { useEffect, useRef, useState } from "react";
import type { AdminApi, ImportProgress, Mapping } from "./types";

export function ImportPage({ api, mappings, sample, onActivated, onRecords }: {
  api: AdminApi; mappings: Mapping[]; sample: boolean;
  onActivated: () => Promise<void>; onRecords: () => void;
}) {
  const drafts = mappings.filter(item => item.status === "draft");
  const [id, setId] = useState(drafts[0]?.id ?? "");
  const [progress, setProgress] = useState<ImportProgress | null>(null);
  const [paused, setPaused] = useState(false), [reviewed, setReviewed] = useState(false);
  const [busy, setBusy] = useState(false), [checking, setChecking] = useState(false);
  const [error, setError] = useState(""), [message, setMessage] = useState("");
  const [refresh, setRefresh] = useState(0);
  const running = useRef(false), mounted = useRef(true);
  const generation = useRef(0);
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; running.current = false; generation.current++; }; }, []);
  useEffect(() => {
    const ticket = ++generation.current;
    setProgress(null); setPaused(false); setReviewed(false); setError(""); setMessage("");
    if (!id) return;
    setChecking(true);
    api.importStatus(id).then(value => { if (ticket === generation.current) setProgress(value); })
      .catch(caught => { if (ticket === generation.current) setError(caught instanceof Error ? caught.message : "Could not load import status."); })
      .finally(() => { if (ticket === generation.current) setChecking(false); });
  }, [api, id, refresh]);
  async function run() {
    if (!id || !paused || busy || checking || !progress) return;
    running.current = true; setBusy(true); setError(""); setMessage("");
    try {
      let state = await api.importStatus(id);
      if (!mounted.current) return;
      if (state.state === "not_started" && running.current) state = await api.importStart(id);
      if (mounted.current) setProgress(state);
      while (running.current && state.state === "importing") {
        state = await api.importStep(id);
        if (mounted.current) setProgress(state);
      }
      if (mounted.current) setMessage(state.state === "done" ? "Import complete. Review the result before making this mapping active." : "Paused after the current batch. Progress is saved; resume when ready.");
    } catch (caught) {
      if (mounted.current) setError(`${caught instanceof Error ? caught.message : "Import stopped."} Progress already saved is retained. Recheck the source and keep writers paused before resuming.`);
    } finally { running.current = false; if (mounted.current) setBusy(false); }
  }
  async function activate() {
    if (!reviewed || !paused || progress?.state !== "done" || busy) return;
    setBusy(true); setError("");
    try { await api.activate(id); setMessage("Mapping activated. You can now browse its cached records."); await onActivated(); setId(""); onRecords(); }
    catch (caught) { setError(`${caught instanceof Error ? caught.message : "Activation could not be confirmed."} Activation may already have completed. Refresh the workspace to check the active mapping before retrying.`); }
    finally { if (mounted.current) setBusy(false); }
  }
  const complete = progress?.tables.filter(table => Number(table.done) === 1).length ?? 0;
  return <>
    <div className="page-heading"><div><span className="eyebrow">02 / INITIAL IMPORT</span><h1>Bring your selection across.</h1><p>One import, handled in small resumable batches. Your current active cache stays in place until you activate this draft.</p></div></div>
    <section className="panel flow-panel">
      <div className="step-heading"><span className="step-number">1</span><div><h2>Choose a saved draft</h2><p>Save your field selection first. An active mapping cannot be replaced in place.</p></div></div>
      <label>Draft to import<select value={id} disabled={busy} onChange={event => setId(event.target.value)}><option value="">Choose a draft…</option>{drafts.map(draft => <option key={draft.id} value={draft.id}>{draft.id}</option>)}</select></label>
      {!drafts.length && <p className="empty">No drafts yet. Open Fields, choose what to include, then Save Draft.</p>}
      <div className="step-heading"><span className="step-number">2</span><div><h2>Import the selected records</h2><p>{sample ? "This demonstration copies fictional records in memory. No FileMaker connection is made." : "Pause all writers to the selected source tables before importing. Keep them paused until activation and reconciliation are complete."}</p></div></div>
      <label className="check-line"><input type="checkbox" checked={paused} disabled={busy || !id} onChange={event => setPaused(event.target.checked)} />{sample ? "I understand how the paused-writers step works." : "All writers to these source tables are paused, and will remain paused through activation."}</label>
      <div className="actions"><button className="button primary" onClick={run} disabled={!paused || busy || checking || !progress || progress.state === "done"}>{busy ? "Importing…" : progress?.state === "importing" ? "Resume import" : "Start import"}</button>{busy && <button className="button secondary" onClick={() => { running.current = false; setMessage("Pausing after the current request finishes…"); }}>Pause after this batch</button>}</div>
      <button className="text-button" disabled={busy || checking || !id} onClick={() => setRefresh(value => value + 1)}>Check saved progress</button>
      {checking && <p role="status">Checking saved progress…</p>}
      {progress && <div className="import-progress" aria-live="polite"><p><strong>{progress.state === "done" ? "Ready to activate" : progress.state === "importing" ? "Import in progress" : "Not imported yet"}</strong> · {complete} of {progress.tables.length} tables complete</p><progress value={complete} max={Math.max(progress.tables.length, 1)} aria-label="Tables imported" /><div className="bounded-progress">{progress.tables.map(table => <div key={table.table_name}><strong>{table.table_name}</strong><span>{Number(table.done) === 1 ? "Complete" : `Last processed ROWID: ${table.after_id}`}</span></div>)}</div></div>}
      {message && <p role="status" className="inline-hint">{message}</p>}{error && <p role="alert" className="alert error">{error}</p>}
      <div className="step-heading"><span className="step-number">3</span><div><h2>Make this mapping active</h2><p>Switch the website reader to this imported selection. This is separate from saving or importing.</p></div></div>
      <label className="check-line"><input type="checkbox" checked={reviewed} disabled={busy || progress?.state !== "done"} onChange={event => setReviewed(event.target.checked)} />I have reviewed the completed import and want this draft to replace the active mapping.</label>
      <button className="button primary" onClick={activate} disabled={!paused || !reviewed || busy || progress?.state !== "done"}>Activate imported draft</button>
      <details className="quick-help"><summary>What if I close this tab or the connection drops?</summary><p>The current server request may finish. Reopen Import and choose the same draft to inspect its saved progress. Resume only after confirming writers remain paused. Do not create a replacement draft just to retry.</p></details>
    </section>
  </>;
}
