import { describe, expect, it } from "vitest";
import {
  buildActivityBars,
  filterCollections,
  getUnreadCount,
} from "./dashboard.js";

describe("dashboard helpers", () => {
  it("counts unread site alerts only", () => {
    const alerts = [
      { id: "a", unread: true },
      { id: "b", unread: false },
      { id: "c", unread: true },
    ];

    expect(getUnreadCount(alerts)).toBe(2);
  });

  it("filters shelves by title, kind, and show metadata", () => {
    const data = {
      recentlyWatched: [
        { title: "Manifest", kind: "show", meta: "S4 E1" },
        { title: "Frequency", kind: "movie", meta: "Watched movie" },
      ],
      moviesToCheckOut: [{ title: "Don't Look Up", kind: "movie" }],
    };

    expect(filterCollections(data, "freq").recentlyWatched).toHaveLength(1);
    expect(filterCollections(data, "movie").moviesToCheckOut).toHaveLength(1);
    expect(filterCollections(data, "s4").recentlyWatched[0].title).toBe(
      "Manifest",
    );
  });

  it("normalizes weekly activity bars against the largest day", () => {
    const bars = buildActivityBars([
      { day: "Mon", hours: 0 },
      { day: "Tue", hours: 3 },
      { day: "Wed", hours: 6 },
    ]);

    expect(bars).toEqual([
      { day: "Mon", hours: 0, height: 8 },
      { day: "Tue", hours: 3, height: 50 },
      { day: "Wed", hours: 6, height: 100 },
    ]);
  });
});

