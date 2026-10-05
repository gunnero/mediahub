import React from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App.jsx";
import "./assets/fonts/inter.css";
import "./styles.css";
import "./cinema.css";

createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
