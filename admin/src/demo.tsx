/** Standalone offline demo. This entry never constructs the real PHP API adapter. */
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { createSampleApi } from "./sample";
import "./styles.css";
import "./community.css";
import "./fields-workspace.css";
import "./autocache-theme.css";
createRoot(document.getElementById("root")!).render(<App api={createSampleApi()} sample />);
