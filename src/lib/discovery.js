export function discoveryFilters(values = {}) {
  const mode = values.mode === "library" ? "library" : "discover";
  const types = mode === "library" ? ["all", "movie", "show", "episode"] : ["all", "movie", "show"];
  return {
    mode,
    ...Object.fromEntries(["genre", "year", "language", "max_runtime", "min_rating", "hide_watched"].filter(key => values[key] !== undefined && values[key] !== null && values[key] !== "").map(key => [key, String(values[key]).slice(0, 40)])),
    query: String(values.query || "").slice(0, 120),
    type: types.includes(values.type) ? values.type : "all",
    category: ["trending", "popular", "now_playing", "upcoming", "top_rated", "recommended"].includes(values.category) ? values.category : "trending",
    page: mode === "library" ? 1 : Math.max(1, Math.min(500, Number.parseInt(values.page, 10) || 1)),
  };
}

export function discoveryHref(values) {
  const filters = discoveryFilters(values);
  const params = new URLSearchParams();
  if (filters.mode !== "discover") params.set("source", filters.mode);
  if (filters.query) params.set("query", filters.query);
  if (filters.type !== "all") params.set("type", filters.type);
  if (filters.category !== "trending") params.set("category", filters.category);
  if (filters.page > 1) params.set("page", filters.page);
  for (const key of ["genre", "year", "language", "max_runtime", "min_rating", "hide_watched"]) if (filters[key]) params.set(key, filters[key]);
  return `/discover${params.size ? `?${params}` : ""}`;
}
