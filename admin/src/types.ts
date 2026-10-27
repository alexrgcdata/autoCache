export interface Field {
  name: string;
  type: string;
  binary?: boolean;
  builtin?: boolean;
  global?: boolean;
  calculation?: boolean;
}
export interface Entity {
  entity: string;
  record_id_field: string;
  fields: Field[];
  declared_primary_keys?: string[];
  suggested_primary_key_field?: string | null;
}
export interface Metadata {
  metadata_version: string;
  server_version: string;
  tables: Record<string, Entity>;
}
export interface TableMap {
  entity: string;
  record_id_field: string;
  fields: string[];
  primary_key_field?: string;
  modified_at_field?: string;
}
export type Tables = Record<string, TableMap>;
export interface Mapping {
  id: string;
  status: "draft" | "active" | "retired";
  tables: Tables;
  hash?: string;
}
export interface Session {
  authenticated: boolean;
  username?: string;
  csrf_token?: string;
}
export interface AdminApi {
  importStatus(id: string): Promise<ImportProgress>;
  importStart(id: string): Promise<ImportProgress>;
  importStep(id: string): Promise<ImportProgress>;
  activate(id: string): Promise<{ mapping: Mapping }>;
  records(table: string, query: string, after?: string): Promise<RecordPage>;
  contactSession(): Promise<{ csrf_token: string }>;
  contact(
    details: ContactDetails,
    csrf: string,
  ): Promise<{ accepted: boolean }>;
  session(): Promise<Session>;
  login(username: string, password: string): Promise<Session>;
  logout(): Promise<Session>;
  discover(): Promise<Metadata>;
  mappings(): Promise<{ mappings: Mapping[] }>;
  save(id: string, tables: Tables): Promise<{ mapping: Mapping }>;
}
export interface ImportProgress {
  state: "not_started" | "importing" | "done";
  tables: { table_name: string; after_id: string; done: boolean | number | string }[];
  page_records?: number;
}
export interface CachedRecord {
  id: string;
  primary_key: string | null;
  fields: Record<string, unknown>;
  synced_at: string;
  omitted?: boolean;
}
export interface RecordPage {
  records: CachedRecord[];
  next_after_id: string | null;
  mapping_id: string;
}
export interface ContactDetails {
  name: string;
  email: string;
  phone: string;
  kind: "project" | "support";
  message: string;
  website: string;
}
