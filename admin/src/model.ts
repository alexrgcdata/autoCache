import type { Entity, Metadata, Tables, TableMap } from "./types.ts";

// A metadata key is a suggestion, not proof that current record values are unique.
// Keep the source ROWID separate from the optional business identity.
export function primaryKeyCandidates(entity: Entity) {
  const scalarTypes = new Set(["Edm.String", "Edm.Guid", "Edm.Int16", "Edm.Int32", "Edm.Int64", "Edm.Decimal", "Edm.Byte", "Edm.SByte"]);
  return entity.fields.filter(field => field.name !== entity.record_id_field && !field.binary && !field.global && !field.calculation && scalarTypes.has(field.type));
}

export function withPrimaryKey(table: TableMap, entity: Entity, key: string): TableMap {
  if (key && !primaryKeyCandidates(entity).some(field => field.name === key)) {
    throw new Error("Choose an available scalar field, or use ROWID fallback.");
  }
  const next = { ...table, fields: [...table.fields] };
  delete next.primary_key_field;
  if (key) next.primary_key_field = key;
  // Former keys remain ordinary selected fields; switching identity never drops data.
  next.fields = [...new Set([...next.fields, ...requiredFields(next)])].sort();
  return next;
}

export function changedIdentities(before: Tables, after: Tables): string[] {
  return Object.keys(after).filter(name => before[name] && (before[name].primary_key_field ?? "") !== (after[name].primary_key_field ?? ""));
}

export const requiredFields = (table: TableMap) =>
  [
    table.record_id_field,
    table.primary_key_field,
    table.modified_at_field,
  ].filter((name): name is string => Boolean(name));

// A loaded legacy wildcard becomes an explicit snapshot in the editor only.
export function explicitTables(tables: Tables, metadata: Metadata): Tables {
  return Object.fromEntries(
    Object.entries(tables).map(([name, table]) => {
      const entity = Object.values(metadata.tables).find(
        (item) => item.entity === table.entity,
      );
      const fields = table.fields.length
        ? table.fields
        : (entity?.fields
            .filter((field) => !field.binary)
            .map((field) => field.name) ?? []);
      return [
        name,
        {
          ...table,
          fields: [...new Set([...fields, ...requiredFields(table)])].sort(),
        },
      ];
    }),
  );
}

export function fingerprint(tables: Tables): string {
  return JSON.stringify(
    Object.entries(tables)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([name, table]) => [
        name,
        table.entity,
        table.record_id_field,
        table.primary_key_field ?? "",
        table.modified_at_field ?? "",
        [...table.fields].sort(),
      ]),
  );
}

export function validate(tables: Tables, metadata: Metadata): string[] {
  if (!Object.keys(tables).length) return ["Choose at least one table."];
  return Object.entries(tables).flatMap(([name, table]) => {
    const entity = Object.values(metadata.tables).find(
      (item) => item.entity === table.entity,
    );
    if (!entity)
      return [
        `${name}: the source table is no longer available. Remove it before saving.`,
      ];
    const errors = table.fields
      .filter(
        (field) =>
          !entity.fields.some((item) => item.name === field && !item.binary),
      )
      .map(
        (field) =>
          `${name}.${field}: unavailable or unsupported. Remove it before saving.`,
      );
    if (
      !table.fields.length ||
      requiredFields(table).some((field) => !table.fields.includes(field))
    )
      errors.push(`${name}: required identity fields must be selected.`);
    return errors;
  });
}

export function changes(
  before: Tables,
  after: Tables,
): { added: string[]; removed: string[] } {
  const paths = (tables: Tables) =>
    new Set(
      Object.entries(tables).flatMap(([name, table]) =>
        table.fields.map((field) => `${name}.${field}`),
      ),
    );
  const left = paths(before),
    right = paths(after);
  return {
    added: [...right].filter((path) => !left.has(path)),
    removed: [...left].filter((path) => !right.has(path)),
  };
}
