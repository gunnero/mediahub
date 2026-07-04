import csv
import importlib.util
import json
import sqlite3
import unittest
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
IMPORTER_PATH = ROOT / "scripts" / "import_tvtime.py"


def load_importer():
    spec = importlib.util.spec_from_file_location("import_tvtime", IMPORTER_PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def write_csv_to_zip(zip_path, name, rows):
    with zipfile.ZipFile(zip_path, "a", zipfile.ZIP_DEFLATED) as archive:
        if not rows:
            archive.writestr(name, "")
            return
        fieldnames = list(rows[0].keys())
        buffer = []
        buffer.append(",".join(fieldnames))
        for row in rows:
            buffer.append(",".join(str(row.get(field, "")) for field in fieldnames))
        archive.writestr(name, "\n".join(buffer) + "\n")


class ImportTvTimeTest(unittest.TestCase):
    def test_importer_builds_private_sqlite_and_public_dashboard_json(self):
        with self.subTest("build import outputs"):
            import tempfile

            with tempfile.TemporaryDirectory() as temp_dir:
                tmp_path = Path(temp_dir)
                importer = load_importer()
                gdpr_zip = tmp_path / "gdpr-data.zip"
                public_dir = tmp_path / "public"
                public_dir.mkdir()

                write_csv_to_zip(
                    gdpr_zip,
                    "tracking-prod-records-v2.csv",
                    [
                        {
                            "key": "watch-episode-show-ep1",
                            "runtime": "42",
                            "user_id": "private-user",
                            "ep_id": "",
                            "episode_number": "1",
                            "created_at": "2026-07-01 20:00:00",
                            "gsi": "",
                            "s_no": "",
                            "episode_id": "ep1",
                            "ep_no": "",
                            "s_id": "show-1",
                            "series_name": "Manifest",
                            "season_number": "4",
                            "updated_at": "",
                            "total_movies_runtime": "",
                            "total_series_runtime": "",
                            "movie_watch_count": "",
                            "ep_watch_count": "",
                            "series_follow_count": "",
                            "is_archived": "",
                            "most_recent_ep_watched": "",
                            "is_followed": "",
                            "uuid": "",
                            "is_for_later": "",
                            "followed_at": "",
                            "rewatch_count": "",
                            "is_unitary": "",
                            "is_special": "",
                            "bulk_type": "",
                        }
                    ],
                )
                write_csv_to_zip(
                    gdpr_zip,
                    "tracking-prod-records.csv",
                    [
                        {
                            "type-uuid-n": "",
                            "user_id": "private-user",
                            "created_at": "2026-07-02 21:30:00",
                            "watch_count": "1",
                            "updated_at": "",
                            "series_id": "",
                            "uuid": "movie-1",
                            "type": "watch",
                            "series_name": "",
                            "watches": "",
                            "follow_date_range_key": "",
                            "release_date": "",
                            "alpha_range_key": "",
                            "movie_name": "Frequency",
                            "release_date_range_key": "",
                            "entity_type": "movie",
                            "runtime": "118",
                            "rewatch_count": "",
                            "series_uuid": "",
                            "episode_id": "",
                            "episode_number": "",
                            "watch_date": "",
                            "season_number": "",
                            "total_series_runtime": "",
                            "total_movies_runtime": "",
                            "unitarian": "",
                            "watched_episode_range_key": "",
                            "watch_date_range_key": "",
                            "bulk_type": "",
                            "country": "",
                        }
                    ],
                )
                (public_dir / "profile.json").write_text(
                    json.dumps(
                        {"id": 44757574, "name": "gunner", "image": "avatar.png"},
                    ),
                )
                (public_dir / "followed_shows.json").write_text(
                    json.dumps(
                        [
                            {
                                "id": "show-1",
                                "name": "Manifest",
                                "seen_episodes": 1,
                                "aired_episodes": 2,
                                "runtime": 42,
                                "all_images": {
                                    "poster": {"0": "poster.jpg"},
                                    "fanart": {"0": "fanart.jpg"},
                                },
                            }
                        ],
                    ),
                )
                (public_dir / "watched_episodes.json").write_text("[]")
                (public_dir / "badges.json").write_text("[]")

                sqlite_path = tmp_path / "tvtime.sqlite"
                dashboard_json = tmp_path / "dashboard.json"
                summary = importer.run_import(
                    gdpr_zip,
                    public_dir,
                    sqlite_path,
                    dashboard_json,
                )

                self.assertEqual(summary["episodes_watched"], 1)
                self.assertEqual(summary["movies_watched"], 1)
                self.assertTrue(sqlite_path.exists())
                self.assertTrue(dashboard_json.exists())

                payload = json.loads(dashboard_json.read_text())
                self.assertEqual(payload["profile"]["name"], "gunner")
                self.assertEqual(payload["stats"]["episodesWatched"], 1)
                self.assertEqual(payload["stats"]["moviesWatched"], 1)
                self.assertEqual(payload["recentlyWatched"][0]["title"], "Frequency")

                with sqlite3.connect(sqlite_path) as connection:
                    count = connection.execute(
                        "select count(*) from episode_watches",
                    ).fetchone()[0]
                self.assertEqual(count, 1)
