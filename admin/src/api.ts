import type { AdminApi, Session } from "./types.ts";

export class ApiError extends Error {
  status: number;
  constructor(message: string, status: number) {
    super(message);
    this.status = status;
  }
}

export function createApi(): AdminApi {
  let csrf = "";
  const path = window.location.pathname.replace(/\/admin(?:\/.*)?$/, "");
  const base = `${path}/api/v1/admin/`;
  async function request<T>(
    route: string,
    body?: unknown,
    token = csrf,
  ): Promise<T> {
    let response: Response;
    try {
      response = await fetch(base + route, {
        method: body === undefined ? "GET" : "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers:
          body === undefined
            ? {}
            : { "Content-Type": "application/json", "X-CSRF-Token": token },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(45000),
      });
    } catch {
      throw new Error(
        "The server could not be reached. If you were saving, check saved drafts before trying again; the request may have completed.",
      );
    }
    const data = await response.json().catch(() => null);
    if (!response.ok)
      throw new ApiError(
        response.status === 401 ? (route === "login" ? "The username or password is incorrect." : "Your session ended. Reload this page and sign in again.") : data?.error?.replaceAll("_", " ") ??
          `Server returned HTTP ${response.status}.`,
        response.status,
      );
    if (!data) throw new Error("The server returned an unexpected response.");
    return data as T;
  }
  async function identity(route: string, body?: unknown) {
    const session = await request<Session>(route, body);
    csrf = session.csrf_token ?? "";
    return session;
  }
  return {
    importStatus: id => request(`import/status?id=${encodeURIComponent(id)}`),
    importStart: id => request("import/start", { id, source_writes_paused: true }),
    importStep: id => request("import/step", { id }),
    activate: id => request("mapping/activate", { id }),
    records: (table, query, after = "0") => request("records/query", { table, query, after }),
    contactSession: () => request("contact/session"),
    contact: (details, token) => request("contact/send", details, token),
    session: () => identity("session"),
    login: (username, password) => identity("login", { username, password }),
    logout: () => identity("logout", {}),
    discover: () => request("discover"),
    mappings: () => request("mappings"),
    save: (id, tables) => request("mapping/draft", { id, tables }),
  };
}
