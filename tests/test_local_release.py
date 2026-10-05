import importlib.util
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('release_assets', ROOT / 'scripts/release-assets.py')
assets = importlib.util.module_from_spec(spec)
spec.loader.exec_module(assets)


class ReleaseAssetsTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.frontend = self.root / 'dist'
        (self.frontend / 'assets').mkdir(parents=True)
        (self.frontend / 'index.html').write_text('<script src="/assets/new.js"></script><link href="/assets/new.css">')
        (self.frontend / 'assets/new.js').write_text('new script')
        (self.frontend / 'assets/new.css').write_text('new styles')
        self.public = self.root / 'public'
        (self.public / 'assets').mkdir(parents=True)
        (self.public / 'assets/old.js').write_text('old script')
        for name, text in [('index.php', 'php entry'), ('.htaccess', 'rewrite'), ('index.html', 'old index')]:
            (self.public / name).write_text(text)
        self.commit = 'a' * 40

    def test_service_worker_switches_after_the_index_and_is_readable(self):
        (self.frontend / 'sw.js').write_text('new worker')
        (self.public / 'sw.js').write_text('old worker')
        assets.stage(self.frontend, self.public, self.commit)
        self.assertEqual((self.public / 'sw.js').read_text(), 'old worker')
        assets.publish(self.public, self.commit)
        self.assertEqual((self.public / 'sw.js').read_text(), 'new worker')
        self.assertEqual((self.public / 'sw.js').stat().st_mode & 0o777, 0o644)

    def test_verification_uses_the_browser_worker_url_when_cdn_caches_the_old_worker(self):
        version = '0123456789abcdef'
        html = (self.frontend / 'index.html').read_text()
        (self.frontend / 'index.html').write_text(f'<meta name="mediahub-build" content="{version}" />' + html)
        (self.frontend / 'sw.js').write_text('new worker')
        requested = []

        def fetch(command):
            path = command[-1].removeprefix('https://example.test')
            requested.append(path)
            if path == '/sw.js':
                return b'cached old worker'
            if path == f'/sw.js?build={version}':
                return b'new worker'
            return (self.frontend / (path.lstrip('/') or 'index.html')).read_bytes()

        with patch.object(assets.subprocess, 'check_output', side_effect=fetch):
            assets.verify(self.frontend, 'https://example.test', '/')
        self.assertIn(f'/sw.js?build={version}', requested)
        self.assertNotIn('/sw.js', requested)

    def test_private_umask_never_reaches_public_files_and_index_switch_is_separate(self):
        private = self.root / 'private.env'
        private.write_text('private configuration')
        private.chmod(0o600)
        (self.public / 'storage').symlink_to(self.root / 'uploads')
        previous = os.umask(0o077)
        try:
            staged = assets.stage(self.frontend, self.public, self.commit)
        finally:
            os.umask(previous)
        self.assertEqual((self.public / 'index.html').read_text(), 'old index')
        for path in [staged, self.public / 'assets/new.js', self.public / 'assets/new.css']:
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
        self.assertEqual((self.public / 'assets').stat().st_mode & 0o777, 0o755)
        self.assertEqual(private.stat().st_mode & 0o777, 0o600)
        assets.publish(self.public, self.commit)
        self.assertEqual((self.public / 'index.html').read_bytes(), (self.frontend / 'index.html').read_bytes())
        self.assertEqual((self.public / 'index.php').read_text(), 'php entry')
        self.assertEqual((self.public / '.htaccess').read_text(), 'rewrite')
        self.assertEqual((self.public / 'assets/old.js').read_text(), 'old script')
        self.assertTrue((self.public / 'storage').is_symlink())

    def test_rejects_symlinks_and_unexpected_frontend_files_before_writing(self):
        (self.frontend / '.env').write_text('must not be public')
        with self.assertRaises(ValueError):
            assets.stage(self.frontend, self.public, self.commit)
        self.assertFalse((self.public / 'assets/new.js').exists())
        (self.frontend / '.env').unlink()
        (self.public / 'assets/new.js').symlink_to(self.root / 'outside')
        with self.assertRaises(ValueError):
            assets.stage(self.frontend, self.public, self.commit)
        self.assertFalse((self.root / 'outside').exists())


class LocalReleaseTest(unittest.TestCase):
    def run_command(self, args, cwd=None, check=True, env=None):
        return subprocess.run(args, cwd=cwd, env=env or self.env, check=check, text=True, capture_output=True)

    def git(self, *args, cwd=None):
        return self.run_command(['git', *args], cwd=cwd or self.source).stdout.strip()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'source'
        self.production = self.root / 'production'
        self.release = self.root / 'release'
        self.source.mkdir()
        (self.source / 'scripts').mkdir()
        for name in ['deploy-mediahub.sh', 'scripts/prepare-release.sh', 'scripts/deploy-release.sh', 'scripts/release-assets.py', 'scripts/migration-plan.py']:
            shutil.copy2(ROOT / name, self.source / name)
        (self.source / '.gitignore').write_text('.mediahub-deploy.env\nnode_modules/\ndist/\nbackend/.env\nbackend/vendor/\nbackend/storage/\nbackend/bootstrap/cache/\nbackend/database/*.sqlite\nbackend/public/index.html\nbackend/public/assets/\n')
        (self.source / 'backend/public').mkdir(parents=True)
        (self.source / 'backend/public/index.php').write_text('php entry')
        (self.source / 'backend/public/.htaccess').write_text('rewrite')
        (self.source / 'version').write_text('old')
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'FAKE_PUBLIC': str(self.production / 'backend/public')}
        self.write_tool('npm', '''#!/usr/bin/env python3
from pathlib import Path
import sys
if sys.argv[1] == 'run':
 p=Path('dist'); (p/'assets').mkdir(parents=True)
 (p/'index.html').write_text('<script src="/assets/new.js"></script><link href="/assets/new.css">')
 (p/'assets/new.js').write_text('new script')
 (p/'assets/new.css').write_text('new styles')
''')
        self.write_tool('composer', '#!/bin/sh\nexit 0\n')
        self.write_tool('php', '''#!/usr/bin/env python3
from pathlib import Path
import sys
if 'down' in sys.argv: Path('backend/storage/framework/down').touch()
if 'up' in sys.argv: Path('backend/storage/framework/down').unlink(missing_ok=True)
''')
        self.write_tool('curl', '''#!/usr/bin/env python3
import os, sys
from pathlib import Path
from urllib.parse import urlparse
path=urlparse(sys.argv[-1]).path
if '-w' in sys.argv:
 print('401' if path == '/api/v1/me' else '200',end='')
else:
 file=Path(os.environ['FAKE_PUBLIC']) / (path.lstrip('/') or 'index.html')
 if not file.is_file() or file.stat().st_mode & 4 == 0: sys.exit(22)
 data=file.read_bytes()
 if os.environ.get('FAKE_BAD_ASSET') and path.endswith('.js'): data=b'wrong bytes'
 sys.stdout.buffer.write(data)
''')
        self.git('init', '-b', 'main')
        self.git('config', 'user.name', 'Release Test')
        self.git('config', 'user.email', 'release@example.test')
        self.git('add', '.')
        self.git('commit', '-m', 'Initial')
        self.before = self.git('rev-parse', 'HEAD')
        remote = self.root / 'remote.git'
        self.run_command(['git', 'init', '--bare', str(remote)])
        self.git('remote', 'add', 'origin', str(remote))
        self.git('push', '-u', 'origin', 'main')
        self.run_command(['git', 'clone', '-b', 'main', str(remote), str(self.production)])
        for directory in ['vendor', 'storage/framework', 'bootstrap/cache', 'database', 'public/assets']:
            (self.production / 'backend' / directory).mkdir(parents=True, exist_ok=True)
        (self.production / 'backend/.env').write_text('APP_KEY=synthetic-private-test-key\n')
        (self.production / 'backend/.env').chmod(0o600)
        (self.production / 'backend/vendor/example').write_text('old vendor')
        (self.production / 'backend/public/index.html').write_text('old index')
        with sqlite3.connect(self.production / 'backend/database/database.sqlite') as connection:
            connection.execute('create table sample (value text)')
            connection.execute("insert into sample values ('preserved')")
        (self.source / 'version').write_text('new')
        self.git('add', 'version'); self.git('commit', '-m', 'Release'); self.git('push')
        self.target = self.git('rev-parse', 'HEAD')
        import pwd
        profile = {
            'MEDIAHUB_DEPLOY_TRANSPORT': 'local', 'MEDIAHUB_SERVER_HOSTNAME': socket.gethostname(),
            'MEDIAHUB_SERVER_USER': pwd.getpwuid(os.getuid()).pw_name, 'MEDIAHUB_SERVER_APP_DIR': str(self.production),
            'MEDIAHUB_SERVER_BACKUP_ROOT': str(self.root / 'backups'), 'MEDIAHUB_LIVE_URL': 'https://mediahub.example.com',
        }
        import shlex
        (self.source / '.mediahub-deploy.env').write_text('\n'.join(key + '=' + shlex.quote(value) for key, value in profile.items()))
        self.prepare()

    def write_tool(self, name, text):
        file = self.bin / name
        file.write_text(text)
        file.chmod(0o755)

    def prepare(self):
        return self.run_command(['bash', 'deploy-mediahub.sh', '--output=' + str(self.release)], cwd=self.source)

    def deploy(self, *args, env=None):
        return self.run_command(['bash', str(self.release / 'deploy.sh'), *args], check=False, env=env)

    def test_preflight_and_complete_release_preserve_database_and_private_config(self):
        check = self.deploy('--check')
        self.assertEqual(check.returncode, 0, check.stdout + check.stderr)
        self.assertEqual(self.git('rev-parse', 'HEAD', cwd=self.production), self.before)
        self.assertFalse((self.root / 'backups').exists())
        result = self.deploy()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('DEPLOYED_COMMIT=' + self.target, result.stdout)
        self.assertEqual(self.git('status', '--porcelain', cwd=self.production), '')
        self.assertEqual((self.production / 'backend/.env').stat().st_mode & 0o777, 0o600)
        self.assertIn('synthetic-private-test-key', (self.production / 'backend/.env').read_text())
        self.assertNotIn('synthetic-private-test-key', result.stdout + result.stderr)
        self.assertFalse((self.production / 'backend/storage/framework/down').exists())
        backup = next((self.root / 'backups').iterdir())
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup / 'database.sqlite').stat().st_mode & 0o777, 0o600)
        for db in [self.production / 'backend/database/database.sqlite', backup / 'database.sqlite']:
            with sqlite3.connect(db) as connection:
                self.assertEqual(connection.execute('select value from sample').fetchone(), ('preserved',))
        self.assertEqual(self.deploy().returncode, 0)

    def test_reviewed_additive_migration_can_deploy_and_changed_hash_is_rejected(self):
        import hashlib
        folder = self.source / 'backend/database/migrations'
        folder.mkdir(parents=True)
        migration = folder / 'new.php'
        migration.write_text('reviewed additive migration')
        plan = {'additive': {'backend/database/migrations/new.php': hashlib.sha256(migration.read_bytes()).hexdigest()}}
        (self.source / 'scripts/release-migrations.json').write_text(json.dumps(plan))
        self.git('add', '.'); self.git('commit', '-m', 'Reviewed additive schema'); self.git('push')
        shutil.rmtree(self.release); self.prepare()
        self.assertEqual(self.deploy('--check').returncode, 0)
        migration.write_text('changed migration not reviewed')
        self.git('add', '.'); self.git('commit', '-m', 'Changed schema'); self.git('push')
        shutil.rmtree(self.release); self.prepare()
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Migration differs', result.stderr)
        self.assertFalse((self.root / 'backups').exists())

    def test_corrupt_package_and_dirty_checkout_stop_before_mutation(self):
        (self.production / 'version').write_text('local work')
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('local changes', result.stderr)
        self.assertFalse((self.root / 'backups').exists())
        (self.production / 'version').write_text('old')
        (self.release / 'COMMIT').write_text('b' * 40)
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.git('rev-parse', 'HEAD', cwd=self.production), self.before)

    def test_failed_http_asset_probe_keeps_old_index_and_recovery_backup(self):
        result = self.deploy(env={**self.env, 'FAKE_BAD_ASSET': '1'})
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual((self.production / 'backend/public/index.html').read_text(), 'old index')
        self.assertTrue((self.production / 'backend/storage/framework/down').exists())
        self.assertTrue(next((self.root / 'backups').iterdir()).joinpath('database.sqlite').is_file())
        self.assertIn('HTTP content mismatch', result.stderr)

    def test_migration_changes_stop_before_backup_or_maintenance(self):
        folder = self.source / 'backend/database/migrations'
        folder.mkdir(parents=True)
        (folder / 'new.php').write_text('migration requiring review')
        self.git('add', '.'); self.git('commit', '-m', 'Migration'); self.git('push')
        shutil.rmtree(self.release)
        self.prepare()
        result = self.deploy()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Schema changes require', result.stderr)
        self.assertEqual(self.git('rev-parse', 'HEAD', cwd=self.production), self.before)
        self.assertFalse((self.root / 'backups').exists())
        self.assertFalse((self.production / 'backend/storage/framework/down').exists())


if __name__ == '__main__':
    unittest.main()
