import { createRoot } from "react-dom/client";
import { App } from "./App";
import { createApi } from "./api";
import "./styles.css";
import "./community.css";
import "./fields-workspace.css";
import "./autocache-theme.css";

const sample =
  new URLSearchParams(location.search).get("demo") === "1" ||
  (import.meta.env.DEV && new URLSearchParams(location.search).has("preview"));
const api = sample ? (await import("./sample")).createSampleApi() : createApi();
createRoot(document.getElementById("root")!).render(
  <App api={api} sample={sample} />,
);
