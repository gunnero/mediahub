import { useEffect, useState } from "react";
import { discoveryFilters } from "./discovery.js";

const sections = new Set(["home", "discover", "movies", "shows", "history", "calendar", "alerts", "stats", "lists", "settings", "player", "profile", "friends", "invite-friends"]);

export function readAppRoute() {
  const { pathname, search } = window.location;
  const params = new URLSearchParams(search);
  const detailMatch = pathname.match(/^\/(movies|shows|episodes)\/([1-9]\d*)\/?$/);
  const previewMatch = pathname.match(/^\/discover\/(movie|show)\/([1-9]\d*)\/?$/);
  const segment = pathname.replace(/^\/|\/$/g, "") || "home";
  const background = window.history.state?.mediahubSection;
  const previewItem = window.history.state?.mediahubPreviewItem;
  const previewSummary = previewMatch && previewItem?.media_type === previewMatch[1] && String(previewItem?.tmdb_id) === previewMatch[2] ? previewItem : { title: "Loading title…" };
  let detail = null;
  if (detailMatch) {
    const kind = { movies: "movie", shows: "show", episodes: "episode" }[detailMatch[1]];
    const initialTab = ["overview", "episodes", "activity", "notes", "history", "provider"].includes(params.get("tab")) ? params.get("tab") : kind === "show" ? "episodes" : "overview";
    const initialSeason = /^\d+$/.test(params.get("season") || "") ? Number(params.get("season")) : null;
    detail = { kind, id: detailMatch[2], [`${kind}Id`]: detailMatch[2], title: "Loading title…", initialTab, initialSeason };
  }
  return {
    href: pathname + search,
    section: detail ? (sections.has(background) ? background : detail.kind === "movie" ? "movies" : "shows") : previewMatch ? "discover" : sections.has(segment) ? segment : "home",
    detail,
    discoveryPreview: previewMatch ? { ...previewSummary, media_type: previewMatch[1], tmdb_id: Number(previewMatch[2]) } : null,
    profileMode: params.get("view") === "edit" ? "edit" : "view",
    settingsTab: ["privacy", "import-export"].includes(params.get("tab")) ? params.get("tab") : "profile",
    discovery: discoveryFilters(detail && background === "discover" ? window.history.state?.mediahubDiscovery : {
      ...Object.fromEntries(["genre", "year", "language", "max_runtime", "min_rating", "hide_watched"].map(key => [key, params.get(key)])), mode: params.get("source"), query: params.get("query"), type: params.get("type"), category: params.get("category"), page: params.get("page"),
    }),
  };
}

export function useAppRoute() {
  const [route, setRoute] = useState(readAppRoute);
  useEffect(() => {
    const handlePopState = () => setRoute(readAppRoute());
    window.addEventListener("popstate", handlePopState);
    return () => window.removeEventListener("popstate", handlePopState);
  }, []);

  function navigate(href, { replace = false, state = {} } = {}) {
    if (href !== window.location.pathname + window.location.search) {
      window.history[replace ? "replaceState" : "pushState"](state, "", href);
    }
    setRoute(readAppRoute());
  }

  function closeMedia() {
    if (window.history.state?.mediahubDetailParent) {
      window.history.back();
    } else {
      navigate(route.detail?.kind === "movie" ? "/movies" : "/shows", { replace: true });
    }
  }

  return { route, navigate, closeMedia };
}
