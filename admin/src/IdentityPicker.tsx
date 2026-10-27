/** SCREEN: identity selection. Only changes the local draft; never writes source
 * records or silently changes a deployed mapping. Metadata suggestions are optional. */
import { primaryKeyCandidates } from "./model";
import type { Entity, TableMap } from "./types";

export function IdentityPicker({ table, entity, onChange }: {
  table: TableMap; entity: Entity; onChange: (key: string) => void;
}) {
  const candidates = primaryKeyCandidates(entity);
  const key = table.primary_key_field ?? "";
  const unknown = key && !candidates.some(field => field.name === key);
  const suggestion = entity.suggested_primary_key_field;
  const suggested = candidates.find(field => field.name === suggestion);
  const chosen = entity.fields.find(field => field.name === key);
  return <section className="identity-picker" aria-label="Record identity">
    <div className="identity-heading"><span className="identity-icon" aria-hidden="true">◇</span><div><strong>A stable identity for every record</strong><p>Choose a business key, or explicitly use the source ROWID.</p></div><span className={`key-chip ${key ? "" : "fallback"}`}>{key ? "Business key" : "ROWID fallback"}</span></div>
    <label htmlFor="record-key">Primary key field</label>
    <select id="record-key" value={key} onChange={event => onChange(event.target.value)} aria-describedby="key-help">
      <option value="">ROWID fallback — FileMaker internal record ID</option>
      {unknown && <option value={key}>{key} — current key; verify eligibility</option>}
      {candidates.map(field => <option value={field.name} key={field.name}>{field.name} · {field.type.replace("Edm.", "")}{entity.declared_primary_keys?.includes(field.name) ? " · declared key" : ""}</option>)}
    </select>
    <p id="key-help">{key ? `${key} (${chosen?.type.replace("Edm.", "") || "type unavailable"}) is kept in the field selection. Confirm its values are unique and non-empty.` : "ROWID is always fetched for change notifications. A business key is optional; no field is chosen automatically."}</p>
    <details className="quick-help identity-help"><summary>Record identity vs primary key</summary><p><strong>Record identity: {entity.record_id_field}.</strong> FileMaker uses this internal ID to locate a record. It always stays selected. ROWID fallback means you use this address without adding a business key.</p><p><strong>Primary key: your unique field.</strong> A UUID or customer number can give your website a stable lookup value. Choose one field that is never empty and unique for every record. A name or email is only suitable if your database enforces those rules.</p><p>Multiple choices do not mean they are all valid keys. Metadata cannot prove uniqueness. Changing keys requires a new import. ROWIDs can change when data is recreated or moved between files.</p></details>
    {suggested && key !== suggestion && <button className="text-button" onClick={() => onChange(suggested.name)}>Use metadata suggestion: {suggested.name} →</button>}
    {unknown && <p className="identity-warning">The saved key has been preserved. Verify its metadata, or choose an eligible key before creating a new identity mapping.</p>}
    {entity.declared_primary_keys && entity.declared_primary_keys.length > 1 && <p className="identity-warning">Multiple declared key fields. This editor stores one business key, not a composite key. Only choose one if it is independently unique.</p>}
    {!candidates.length && <p className="identity-warning">No eligible business-key fields in the loaded metadata. ROWID fallback remains available.</p>}
  </section>;
}
