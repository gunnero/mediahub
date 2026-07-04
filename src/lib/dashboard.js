export function getUnreadCount(alerts = []) {
  return alerts.filter((alert) => alert.unread).length;
}

function matchesQuery(item, query) {
  if (!query) {
    return true;
  }

  const haystack = [
    item.title,
    item.subtitle,
    item.meta,
    item.kind,
    item.status,
    item.badge,
  ]
    .filter(Boolean)
    .join(" ")
    .toLowerCase();

  return haystack.includes(query.toLowerCase().trim());
}

export function filterCollections(data, query) {
  const filterList = (items = []) => items.filter((item) => matchesQuery(item, query));

  return {
    recentlyWatched: filterList(data.recentlyWatched),
    followedNewEpisodes: filterList(data.followedNewEpisodes),
    moviesToCheckOut: filterList(data.moviesToCheckOut),
    topShows: filterList(data.topShows),
  };
}

export function buildActivityBars(activity = []) {
  const maxHours = Math.max(...activity.map((item) => Number(item.hours) || 0), 0);

  return activity.map((item) => {
    const hours = Number(item.hours) || 0;
    return {
      ...item,
      hours,
      height: maxHours === 0 ? 8 : Math.max(8, Math.round((hours / maxHours) * 100)),
    };
  });
}

