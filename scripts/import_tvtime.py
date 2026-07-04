#!/usr/bin/env python3
import argparse
import csv
import hashlib
import json
import re
import sqlite3
import sys
import zipfile
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import Request, urlopen


DEFAULT_GDPR_ZIP = Path(
    "/Users/aleksandardimovski/Documents/tvtime-backup-2026-07-03/"
    "official-gdpr-export/gdpr-data.zip",
)
DEFAULT_PUBLIC_DIR = Path("/Users/aleksandardimovski/Documents/tvtime-backup-2026-07-03")
DEFAULT_SQLITE = Path("var/private/tvtime.sqlite")
DEFAULT_DASHBOARD_JSON = Path("public/data/dashboard-data.json")
DEFAULT_ASSET_CACHE = Path("public/assets/cache")

DATETIME_FORMATS = (
    "%Y-%m-%d %H:%M:%S",
    "%Y-%m-%dT%H:%M:%S",
    "%Y-%m-%d",
)


def parse_args():
    parser = argparse.ArgumentParser(
        description="Import a private TV Time GDPR export into local dashboard data.",
    )
    parser.add_argument("--gdpr-zip", type=Path, default=DEFAULT_GDPR_ZIP)
    parser.add_argument("--public-dir", type=Path, default=DEFAULT_PUBLIC_DIR)
    parser.add_argument("--sqlite", type=Path, default=DEFAULT_SQLITE)
    parser.add_argument("--dashboard-json", type=Path, default=DEFAULT_DASHBOARD_JSON)
    parser.add_argument("--asset-cache", type=Path, default=DEFAULT_ASSET_CACHE)
    return parser.parse_args()


def read_json(path, default):
    if not path.exists():
        return default
    return json.loads(path.read_text())


def read_csv_from_zip(zip_path, filename):
    with zipfile.ZipFile(zip_path) as archive:
        if filename not in archive.namelist():
            return []
        with archive.open(filename) as handle:
            text = (line.decode("utf-8-sig", errors="replace") for line in handle)
            return list(csv.DictReader(text))


def parse_dt(value):
    if not value:
        return None
    cleaned = value.strip().replace("Z", "").replace("T", " ")
    if "." in cleaned:
        cleaned = cleaned.split(".", 1)[0]
    for fmt in DATETIME_FORMATS:
        try:
            return datetime.strptime(cleaned, fmt)
        except ValueError:
            continue
    if len(cleaned) >= 19:
        try:
            return datetime.strptime(cleaned[:19], "%Y-%m-%d %H:%M:%S")
        except ValueError:
            return None
    return None


def iso_dt(value):
    parsed = parse_dt(value)
    return parsed.isoformat() if parsed else ""


def as_int(value, default=0):
    try:
        if value in (None, ""):
            return default
        return int(float(value))
    except (TypeError, ValueError):
        return default


def runtime_minutes(value):
    minutes = as_int(value)
    if minutes > 600:
        return round(minutes / 60)
    return minutes


def slugify(value):
    lowered = (value or "").lower()
    lowered = re.sub(r"[^a-z0-9]+", "-", lowered)
    return lowered.strip("-") or "item"


def title_key(value):
    return re.sub(r"\s+", " ", (value or "").strip()).casefold()


def first_image(images, image_type="poster"):
    if not isinstance(images, dict):
        return ""
    value = images.get(image_type)
    if isinstance(value, str):
        return value
    if isinstance(value, list):
        return next((item for item in value if item), "")
    if isinstance(value, dict):
        for key in ("0", "1", "1,3", "2", "3", "square"):
            if value.get(key):
                return value[key]
        return next((item for item in value.values() if item), "")
    return ""


def file_sha256(path):
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def local_asset_name(url, index):
    parsed = urlparse(url)
    suffix = Path(parsed.path).suffix.lower()
    if suffix not in (".jpg", ".jpeg", ".png", ".webp"):
        suffix = ".jpg"
    return f"remote-{index:03d}-{hashlib.sha1(url.encode()).hexdigest()[:10]}{suffix}"


def cache_remote_images(payload, cache_dir):
    cache_dir = Path(cache_dir)
    cache_dir.mkdir(parents=True, exist_ok=True)
    url_to_local = {}

    image_fields = []
    for section in (
        "hero",
        "recentlyWatched",
        "followedNewEpisodes",
        "moviesToCheckOut",
        "topShows",
    ):
        value = payload.get(section)
        if isinstance(value, dict):
            image_fields.append(value)
        elif isinstance(value, list):
            image_fields.extend(item for item in value if isinstance(item, dict))

    urls = []
    for item in image_fields:
        for field in ("poster", "backdrop"):
            url = item.get(field)
            if isinstance(url, str) and url.startswith("http") and url not in url_to_local:
                urls.append(url)
                url_to_local[url] = ""

    for index, url in enumerate(urls[:50], start=1):
        name = local_asset_name(url, index)
        local_path = cache_dir / name
        if not local_path.exists():
            try:
                request = Request(url, headers={"User-Agent": "tvtime-local-dashboard/1.0"})
                with urlopen(request, timeout=12) as response:
                    local_path.write_bytes(response.read())
            except Exception:
                continue
        url_to_local[url] = f"/assets/cache/{name}"

    for item in image_fields:
        for field in ("poster", "backdrop"):
            url = item.get(field)
            if isinstance(url, str) and url_to_local.get(url):
                item[field] = url_to_local[url]

    return payload


def prepare_database(sqlite_path):
    sqlite_path.parent.mkdir(parents=True, exist_ok=True)
    if sqlite_path.exists():
        sqlite_path.unlink()
    connection = sqlite3.connect(sqlite_path)
    connection.executescript(
        """
        create table shows (
            show_key text primary key,
            tvtime_id text,
            title text not null,
            poster_url text,
            fanart_url text,
            followed integer not null default 0,
            seen_episodes integer not null default 0,
            aired_episodes integer not null default 0,
            runtime integer not null default 0,
            latest_seen_at text
        );

        create table episode_watches (
            id integer primary key autoincrement,
            episode_id text,
            show_key text,
            show_title text not null,
            season_number integer,
            episode_number integer,
            watched_at text,
            runtime integer not null default 0
        );

        create table movies (
            uuid text primary key,
            title text not null,
            watched_at text,
            runtime integer not null default 0,
            watch_count integer not null default 1,
            is_to_watch integer not null default 0
        );

        create table alerts (
            id text primary key,
            category text not null,
            title text not null,
            subtitle text not null,
            due_text text not null,
            unread integer not null default 1
        );
        """
    )
    return connection


def public_show_lookup(public_shows):
    by_name = {}
    for show in public_shows:
        name = show.get("name")
        if name:
            by_name[title_key(name)] = show
    return by_name


def upsert_show(connection, show_key, title, public_show=None, latest_seen_at=""):
    public_show = public_show or {}
    images = public_show.get("all_images", {})
    poster = first_image(images, "poster")
    fanart = first_image(images, "fanart") or first_image(images, "banner")
    seen = as_int(public_show.get("seen_episodes"))
    aired = as_int(public_show.get("aired_episodes"))
    runtime = as_int(public_show.get("runtime"))
    followed = 1 if public_show else 0
    tvtime_id = str(public_show.get("id") or "")

    connection.execute(
        """
        insert into shows (
            show_key, tvtime_id, title, poster_url, fanart_url, followed,
            seen_episodes, aired_episodes, runtime, latest_seen_at
        )
        values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        on conflict(show_key) do update set
            tvtime_id = excluded.tvtime_id,
            title = excluded.title,
            poster_url = coalesce(nullif(excluded.poster_url, ''), shows.poster_url),
            fanart_url = coalesce(nullif(excluded.fanart_url, ''), shows.fanart_url),
            followed = max(shows.followed, excluded.followed),
            seen_episodes = max(shows.seen_episodes, excluded.seen_episodes),
            aired_episodes = max(shows.aired_episodes, excluded.aired_episodes),
            runtime = max(shows.runtime, excluded.runtime),
            latest_seen_at = max(coalesce(shows.latest_seen_at, ''), excluded.latest_seen_at)
        """,
        (
            show_key,
            tvtime_id,
            title,
            poster,
            fanart,
            followed,
            seen,
            aired,
            runtime,
            latest_seen_at,
        ),
    )


def import_shows_and_episodes(connection, rows, public_shows):
    by_public_name = public_show_lookup(public_shows)
    episode_rows = [row for row in rows if row.get("episode_id")]
    latest_by_show = defaultdict(str)

    for row in episode_rows:
        watched_at = iso_dt(row.get("created_at") or row.get("updated_at"))
        show_title = row.get("series_name") or "Unknown show"
        show_key = row.get("s_id") or slugify(show_title)
        latest_by_show[show_key] = max(latest_by_show[show_key], watched_at)
        public_show = by_public_name.get(title_key(show_title))
        runtime = runtime_minutes(row.get("runtime")) or runtime_minutes(
            public_show.get("runtime") if public_show else 0,
        )

        upsert_show(connection, show_key, show_title, public_show, latest_by_show[show_key])
        connection.execute(
            """
            insert into episode_watches (
                episode_id, show_key, show_title, season_number, episode_number,
                watched_at, runtime
            )
            values (?, ?, ?, ?, ?, ?, ?)
            """,
            (
                row.get("episode_id"),
                show_key,
                show_title,
                as_int(row.get("season_number")),
                as_int(row.get("episode_number")),
                watched_at,
                runtime,
            ),
        )

    existing_titles = {
        row[0]
        for row in connection.execute("select lower(title) from shows").fetchall()
    }
    for show in public_shows:
        name = show.get("name")
        if name and name.lower() not in existing_titles:
            upsert_show(connection, f"public-{show.get('id')}", name, show, "")


def import_movies(connection, rows):
    movies = {}
    to_watch = {}
    for row in rows:
        title = row.get("movie_name")
        uuid = row.get("uuid")
        entity_type = row.get("entity_type")
        event_type = row.get("type")
        if not title or not uuid or entity_type != "movie":
            continue
        watched_at = iso_dt(row.get("created_at") or row.get("watch_date"))
        runtime = runtime_minutes(row.get("runtime"))
        watch_count = max(1, as_int(row.get("watch_count"), 1))
        if event_type == "watch":
            current = movies.get(uuid)
            if not current or watched_at > current["watched_at"]:
                movies[uuid] = {
                    "uuid": uuid,
                    "title": title,
                    "watched_at": watched_at,
                    "runtime": runtime,
                    "watch_count": watch_count,
                }
        elif event_type == "towatch":
            to_watch[uuid] = {
                "uuid": uuid,
                "title": title,
                "watched_at": watched_at,
                "runtime": runtime,
                "watch_count": watch_count,
            }

    for movie in movies.values():
        connection.execute(
            """
            insert into movies (uuid, title, watched_at, runtime, watch_count, is_to_watch)
            values (?, ?, ?, ?, ?, 0)
            on conflict(uuid) do update set
                title = excluded.title,
                watched_at = excluded.watched_at,
                runtime = excluded.runtime,
                watch_count = excluded.watch_count
            """,
            (
                movie["uuid"],
                movie["title"],
                movie["watched_at"],
                movie["runtime"],
                movie["watch_count"],
            ),
        )

    for movie in to_watch.values():
        connection.execute(
            """
            insert into movies (uuid, title, watched_at, runtime, watch_count, is_to_watch)
            values (?, ?, ?, ?, ?, 1)
            on conflict(uuid) do update set is_to_watch = 1
            """,
            (
                movie["uuid"],
                movie["title"],
                movie["watched_at"],
                movie["runtime"],
                movie["watch_count"],
            ),
        )


def build_alerts(connection):
    show_rows = connection.execute(
        """
        select show_key, title, poster_url, seen_episodes, aired_episodes
        from shows
        where followed = 1 and aired_episodes > seen_episodes
        order by (aired_episodes - seen_episodes) desc, title
        limit 8
        """
    ).fetchall()

    alerts = []
    for index, row in enumerate(show_rows[:5], start=1):
        show_key, title, _poster, seen, aired = row
        missing = max(1, aired - seen)
        alerts.append(
            {
                "id": f"episodes-{slugify(show_key)}",
                "category": "new-episodes",
                "title": title,
                "subtitle": f"{missing} unwatched episode{'s' if missing != 1 else ''} in your library",
                "dueText": "Available now",
                "unread": index <= 3,
            }
        )

    movie_rows = connection.execute(
        """
        select title from movies
        where is_to_watch = 1
        order by watched_at desc, title
        limit 3
        """
    ).fetchall()
    for row in movie_rows:
        alerts.append(
            {
                "id": f"movie-{slugify(row[0])}",
                "category": "movies",
                "title": row[0],
                "subtitle": "Saved for later from your TV Time export",
                "dueText": "Movie shelf",
                "unread": True,
            }
        )

    if not alerts:
        alerts.append(
            {
                "id": "metadata-source-needed",
                "category": "upcoming",
                "title": "Release tracking ready",
                "subtitle": "Connect a metadata source later for live episode dates",
                "dueText": "Site alert",
                "unread": False,
            }
        )

    for alert in alerts:
        connection.execute(
            """
            insert into alerts (id, category, title, subtitle, due_text, unread)
            values (?, ?, ?, ?, ?, ?)
            """,
            (
                alert["id"],
                alert["category"],
                alert["title"],
                alert["subtitle"],
                alert["dueText"],
                1 if alert["unread"] else 0,
            ),
        )


def movie_asset(index):
    return f"/assets/generated/movie-poster-{(index % 8) + 1}.png"


def format_episode_meta(season, episode):
    if season and episode:
        return f"S{season} E{episode}"
    if season:
        return f"Season {season}"
    return "Episode"


def latest_anchor(connection):
    values = []
    for (value,) in connection.execute("select watched_at from episode_watches where watched_at != ''"):
        parsed = parse_dt(value)
        if parsed:
            values.append(parsed)
    for (value,) in connection.execute("select watched_at from movies where watched_at != ''"):
        parsed = parse_dt(value)
        if parsed:
            values.append(parsed)
    return max(values) if values else datetime.now()


def build_activity(connection):
    anchor = latest_anchor(connection).date()
    start = anchor - timedelta(days=6)
    totals = {start + timedelta(days=offset): 0 for offset in range(7)}

    for watched_at, runtime in connection.execute(
        "select watched_at, runtime from episode_watches where watched_at != ''",
    ):
        parsed = parse_dt(watched_at)
        if parsed and parsed.date() in totals:
            totals[parsed.date()] += as_int(runtime)

    for watched_at, runtime in connection.execute(
        "select watched_at, runtime from movies where watched_at != '' and is_to_watch = 0",
    ):
        parsed = parse_dt(watched_at)
        if parsed and parsed.date() in totals:
            totals[parsed.date()] += as_int(runtime)

    return [
        {
            "day": day.strftime("%a"),
            "date": day.isoformat(),
            "hours": round(minutes / 60, 1),
        }
        for day, minutes in totals.items()
    ]


def build_dashboard_payload(connection, profile, gdpr_zip):
    episodes_watched = connection.execute("select count(*) from episode_watches").fetchone()[0]
    movies_watched = connection.execute(
        "select count(*) from movies where is_to_watch = 0",
    ).fetchone()[0]
    shows_followed = connection.execute(
        "select count(*) from shows where followed = 1",
    ).fetchone()[0]
    minutes_watched = connection.execute(
        """
        select
            coalesce((select sum(runtime) from episode_watches), 0) +
            coalesce((select sum(runtime) from movies where is_to_watch = 0), 0)
        """
    ).fetchone()[0] or 0
    alerts_unread = connection.execute(
        "select count(*) from alerts where unread = 1",
    ).fetchone()[0]

    recent_movies = connection.execute(
        """
        select title, watched_at, runtime
        from movies
        where is_to_watch = 0
        order by watched_at desc
        limit 8
        """
    ).fetchall()
    recent_episodes = connection.execute(
        """
        select e.show_title, e.season_number, e.episode_number, e.watched_at,
               e.runtime, s.poster_url, s.fanart_url, s.seen_episodes, s.aired_episodes
        from episode_watches e
        left join shows s on s.show_key = e.show_key
        order by e.watched_at desc
        limit 8
        """
    ).fetchall()

    recently_watched = []
    for row in recent_episodes[:6]:
        title, season, episode, watched_at, runtime, poster, fanart, seen, aired = row
        progress = round((seen / aired) * 100) if aired else 100
        recently_watched.append(
            {
                "id": f"episode-{slugify(title)}-{season}-{episode}",
                "kind": "show",
                "title": title,
                "subtitle": format_episode_meta(season, episode),
                "meta": f"{runtime or 0} min episode",
                "poster": poster,
                "backdrop": fanart,
                "watchedAt": watched_at,
                "progress": min(100, progress),
                "badge": "watched",
            }
        )

    for index, row in enumerate(recent_movies[:6]):
        title, watched_at, runtime = row
        recently_watched.append(
            {
                "id": f"movie-{slugify(title)}",
                "kind": "movie",
                "title": title,
                "subtitle": "Movie",
                "meta": f"{runtime or 0} min movie",
                "poster": movie_asset(index),
                "backdrop": movie_asset(index),
                "watchedAt": watched_at,
                "progress": 100,
                "badge": "watched",
            }
        )

    recently_watched.sort(key=lambda item: item.get("watchedAt") or "", reverse=True)
    recently_watched = recently_watched[:10]

    followed_new = []
    for row in connection.execute(
        """
        select title, poster_url, fanart_url, seen_episodes, aired_episodes
        from shows
        where followed = 1 and aired_episodes > seen_episodes
        order by (aired_episodes - seen_episodes) desc, title
        limit 10
        """
    ):
        title, poster, fanart, seen, aired = row
        missing = aired - seen
        followed_new.append(
            {
                "id": f"show-gap-{slugify(title)}",
                "kind": "show",
                "title": title,
                "subtitle": f"{missing} episode{'s' if missing != 1 else ''} ready",
                "meta": f"{seen}/{aired} watched",
                "poster": poster,
                "backdrop": fanart,
                "progress": round((seen / aired) * 100) if aired else 0,
                "badge": "new",
                "unread": True,
            }
        )

    movies_to_check = []
    movie_candidates = connection.execute(
        """
        select title, watched_at, runtime, is_to_watch
        from movies
        order by is_to_watch desc, watched_at desc
        limit 10
        """
    ).fetchall()
    for index, row in enumerate(movie_candidates):
        title, watched_at, runtime, is_to_watch = row
        movies_to_check.append(
            {
                "id": f"movie-shelf-{slugify(title)}",
                "kind": "movie",
                "title": title,
                "subtitle": "Saved for later" if is_to_watch else "Recently watched",
                "meta": f"{runtime or 0} min",
                "poster": movie_asset(index),
                "watchedAt": watched_at,
                "progress": 0 if is_to_watch else 100,
                "badge": "watchlist" if is_to_watch else "watched",
            }
        )

    top_shows = []
    for row in connection.execute(
        """
        select show_title, count(*) as episodes, max(watched_at) as latest
        from episode_watches
        group by show_title
        order by episodes desc, show_title
        limit 12
        """
    ):
        title, count, latest = row
        show = connection.execute(
            "select poster_url, fanart_url from shows where title = ? limit 1",
            (title,),
        ).fetchone()
        top_shows.append(
            {
                "id": f"top-show-{slugify(title)}",
                "kind": "show",
                "title": title,
                "subtitle": f"{count} watched episodes",
                "meta": f"Last watched {latest[:10] if latest else 'unknown'}",
                "poster": show[0] if show else "",
                "backdrop": show[1] if show else "",
                "progress": 100,
                "badge": "top",
            }
        )

    hero = (recently_watched[0] if recently_watched else top_shows[0]) if (recently_watched or top_shows) else {}
    if hero:
        hero = {
            **hero,
            "eyebrow": "Continue watching" if hero.get("kind") == "show" else "Recent movie",
            "actionLabel": "Open details",
        }

    alert_rows = [
        {
            "id": row[0],
            "category": row[1],
            "title": row[2],
            "subtitle": row[3],
            "dueText": row[4],
            "unread": bool(row[5]),
        }
        for row in connection.execute(
            "select id, category, title, subtitle, due_text, unread from alerts order by unread desc, title",
        )
    ]

    return {
        "profile": {
            "id": profile.get("id"),
            "name": profile.get("name") or "gunner",
            "image": profile.get("image") or first_image(profile.get("all_images", {}), "square"),
            "cover": profile.get("cover") or hero.get("backdrop", ""),
        },
        "source": {
            "kind": "TV Time GDPR export",
            "gdprSha256": file_sha256(gdpr_zip),
            "generatedAt": datetime.now().isoformat(timespec="seconds"),
        },
        "stats": {
            "episodesWatched": episodes_watched,
            "moviesWatched": movies_watched,
            "hoursWatched": round(minutes_watched / 60),
            "showsFollowed": shows_followed,
            "alertsUnread": alerts_unread,
        },
        "hero": hero,
        "alerts": alert_rows,
        "recentlyWatched": recently_watched,
        "followedNewEpisodes": followed_new,
        "moviesToCheckOut": movies_to_check,
        "topShows": top_shows,
        "activity": build_activity(connection),
    }


def run_import(gdpr_zip, public_dir, sqlite_path, dashboard_json, asset_cache=None):
    gdpr_zip = Path(gdpr_zip)
    public_dir = Path(public_dir)
    sqlite_path = Path(sqlite_path)
    dashboard_json = Path(dashboard_json)

    if not gdpr_zip.exists():
        raise FileNotFoundError(f"Missing GDPR export: {gdpr_zip}")
    if not public_dir.exists():
        raise FileNotFoundError(f"Missing public backup directory: {public_dir}")

    episode_rows = read_csv_from_zip(gdpr_zip, "tracking-prod-records-v2.csv")
    tracking_rows = read_csv_from_zip(gdpr_zip, "tracking-prod-records.csv")
    profile = read_json(public_dir / "profile.json", {})
    public_shows = read_json(public_dir / "followed_shows.json", [])

    connection = prepare_database(sqlite_path)
    try:
        import_shows_and_episodes(connection, episode_rows, public_shows)
        import_movies(connection, tracking_rows)
        build_alerts(connection)
        connection.commit()
        payload = build_dashboard_payload(connection, profile, gdpr_zip)
    finally:
        connection.close()

    if asset_cache:
        payload = cache_remote_images(payload, asset_cache)

    dashboard_json.parent.mkdir(parents=True, exist_ok=True)
    dashboard_json.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n")

    return {
        "episodes_watched": payload["stats"]["episodesWatched"],
        "movies_watched": payload["stats"]["moviesWatched"],
        "shows_followed": payload["stats"]["showsFollowed"],
        "alerts_unread": payload["stats"]["alertsUnread"],
        "sqlite": str(sqlite_path),
        "dashboard_json": str(dashboard_json),
    }


def main():
    args = parse_args()
    summary = run_import(
        args.gdpr_zip,
        args.public_dir,
        args.sqlite,
        args.dashboard_json,
        args.asset_cache,
    )
    print(json.dumps(summary, indent=2))


if __name__ == "__main__":
    sys.exit(main())
