/** Community workspace: presentation derived from saved mappings, never invented
 * health/record counts. The learning page describes the actual PHP cache contract. */
import type { Mapping, Metadata, Tables } from "./types";

export function Overview({ metadata, mappings, tables, onEdit, onLearn }: {
  metadata: Metadata; mappings: Mapping[]; tables: Tables;
  onEdit: (table?: string) => void; onLearn: () => void;
}) {
  const active = mappings.find(mapping => mapping.status === "active");
  const selected = Object.entries(tables);
  const keyed = selected.filter(([, table]) => Boolean(table.primary_key_field)).length;
  return <>
    <div className="page-heading"><div><span className="eyebrow">YOUR DATA, WITH INTENTION</span><h1>A clearer connection.</h1><p>FileMaker at the source. A faster experience on the web.</p></div><button className="button primary" onClick={() => onEdit()}>Build your mapping ↗</button></div>
    <section className="journey panel" aria-label="How data flows">
      <div><span className="journey-symbol">01</span><small>SOURCE OF TRUTH</small><h2>FileMaker</h2><p>Read through OData</p></div><span className="journey-arrow" aria-hidden="true">→</span>
      <div className="journey-core"><span className="journey-symbol">r</span><small>YOUR SELECTION</small><h2>autoCache</h2><p>PHP + MySQL / MariaDB</p></div><span className="journey-arrow" aria-hidden="true">→</span>
      <div><span className="journey-symbol">03</span><small>BUILT FOR YOUR USERS</small><h2>Your website</h2><p>Read cached records</p></div>
    </section>
    <section className="overview-metrics" aria-label="Mapping summary">
      <article className="panel"><small>SOURCE TABLES</small><strong>{Object.keys(metadata.tables).length}</strong><p>Available in loaded metadata</p></article>
      <article className="panel"><small>WORKING SELECTION</small><strong>{selected.length}</strong><p>{keyed} business keys · {selected.length - keyed} ROWID fallbacks</p></article>
      <article className="panel"><small>SAVED DRAFTS</small><strong>{mappings.filter(mapping => mapping.status === "draft").length}</strong><p>Review before import and activation</p></article>
    </section>
    <div className="overview-columns">
      <section className="panel overview-selection"><div className="preview-heading"><div><span className="eyebrow">THE WORKING COPY</span><h2>Small choices. Better data.</h2></div><button className="text-button" onClick={() => onEdit()}>Edit selection →</button></div>
        <div className="mapping-matrix" role="region" aria-label="Selected table identities" tabIndex={0}>
          <table><thead><tr><th scope="col">Table</th><th scope="col">Record identity</th><th scope="col">Fields</th><th scope="col"><span className="sr-only">Edit</span></th></tr></thead><tbody>{selected.map(([name, table]) => <tr key={name}><th scope="row">{name}</th><td><span className={`key-chip ${table.primary_key_field ? "" : "fallback"}`}>{table.primary_key_field || "ROWID fallback"}</span></td><td>{table.fields.length}</td><td><button className="text-button" onClick={() => onEdit(name)} aria-label={`Edit ${name} mapping`}>Open ↗</button></td></tr>)}</tbody></table>
          {!selected.length && <p className="empty">No selected tables yet. Start with one table and a few fields.</p>}
        </div>
      </section>
      <aside className="panel next-step"><span className="eyebrow">A DELIBERATE WORKFLOW</span><h2>Draft first.<br/>Publish with care.</h2><ol><li>Choose tables, fields and identity.</li><li>Save a named draft.</li><li>Pause writers, import, then activate.</li></ol><p>Active mapping: <strong>{active?.id || "None"}</strong>. A saved mapping is not evidence that the cache is fresh.</p><button className="button secondary" onClick={onLearn}>Understand the flow →</button></aside>
    </div>
    <div className="honest-status"><span aria-hidden="true">◎</span><p><strong>Scope, not a health claim.</strong> This overview shows mapping data. Live queue counts, cache freshness and record totals are not reported by this admin view.</p></div>
  </>;
}

export function LearningPage() {
  return <>
    <div className="page-heading"><div><span className="eyebrow">BUILT TO BE UNDERSTOOD</span><h1>Make it yours.</h1><p>A small cache engine. Clear boundaries. No magic to maintain.</p></div><span className="pill">Community edition</span></div>
    <section className="learning-grid">
      {[
        ["01 / Choose", "Map only what you need", "A mapping is an explicit list of tables and fields. Select the content your website uses; leave private data at the source. New fields do not silently join this selection."],
        ["02 / Identify", "Give every record a stable address", "Choose a unique, non-empty business key from the dropdown. Metadata suggestions are helpful, but you must confirm uniqueness. Use ROWID fallback when a suitable key is unavailable. Composite keys are not assembled by this editor."],
        ["03 / Import", "Save, import, then activate", "Save your field selection as a named draft. Open Import, choose that draft and confirm source writers are paused. The import runs in resumable batches. Review completion, then explicitly activate the draft."],
        ["04 / Review", "See the complete schema", "The schema tree includes all tables and fields in the loaded metadata. Search any field name, expand a table, and look for the highlighted selections. Use Choose fields to return to that table’s editor."],
        ["05 / Find", "Search the values, not just the ID", "Open Cached records and choose a table. Search names, email addresses, companies or numbers across its selected fields. Results are paged, and each record opens into a readable field list. This is a read-only cached snapshot."],
        ["06 / Explore", "Try it without touching a database", "The public demo uses fictional records and resets on refresh. No connection details are needed. For a connected installation, read INSTALLATION.md and SECURITY.md before configuring your own private accounts."],
      ].map(([number, title, body]) => <article className="panel learning-card" key={number}><span className="eyebrow">{number}</span><h2>{title}</h2><p>{body}</p></article>)}
    </section>
    <section className="panel learn-files"><h2>Open the package. Follow the files.</h2><dl><div><dt>START-HERE.md</dt><dd>Installation, demo and deployment boundaries.</dd></div><div><dt>PROJECT-GUIDE.md</dt><dd>A map of the files and where to change each behavior.</dd></div><div><dt>AGENTIC_HANDOFF.md</dt><dd>What another agent needs to continue safely.</dd></div><div><dt>private/config.example.php</dt><dd>Your private connection settings template. Never publish a configured copy.</dd></div></dl><p>These documents are included in the downloadable package, not fetched from your server.</p></section>
  </>;
}
