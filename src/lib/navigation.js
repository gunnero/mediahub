import { useEffect, useState } from "react";
import { discoveryFilters } from "./discovery.js";

const sections = new Set(["home", "discover", "movies", "shows", "history", "calendar", "alerts", "stats", "lists", "settings", "player", "profile", "friends", "invite-friends"]);

export function readAppRoute() {
  const { pathname, search } = window.location;
  const params = new URLSearchParams(search);
  const detailMatch = pathname.match(/^\/(movies|shows|episodes)\/([1-9]\d*)\/?$/);
  const segment = pathname.replace(/^\/|\/$/g, "") || "home";
  const background = window.history.state?.mediahubSection;
  let detail = null;
  if (detailMatch) {
    const kind = { movies: "movie", shows: "show", episodes: "episode" }[detailMatch[1]];
    detail = { kind, id: detailMatch[2], [`${kind}Id`]: detailMatch[2], title: "Loading title…" };
  }
  return {
    href: pathname + search,
    section: detail ? (sections.has(background) ? background : detail.kind === "movie" ? "movies" : "shows") : sections.has(segment) ? segment : "home",
    detail,
    profileMode: params.get("view") === "edit" ? "edit" : "view",
    settingsTab: ["privacy", "import-export"].includes(params.get("tab")) ? params.get("tab") : "profile",
    discovery: discoveryFilters(detail && background === "discover" ? window.history.state?.mediahubDiscovery : {
      mode: params.get("source"), query: params.get("query"), type: params.get("type"), category: params.get("category"), page: params.get("page"),
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
