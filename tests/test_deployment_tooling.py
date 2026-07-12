import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class DeploymentToolingTest(unittest.TestCase):
    def read(self, relative_path):
        return (ROOT / relative_path).read_text(encoding="utf-8")

    def test_scripts_exist_and_have_valid_shell_syntax(self):
        for relative_path in ("deploy-mediahub.sh", "rollback-mediahub.sh"):
            script = ROOT / relative_path
            self.assertTrue(script.exists(), f"{relative_path} should exist")
            subprocess.run(["bash", "-n", str(script)], check=True)

    def test_deploy_script_contains_required_safety_gates(self):
        script = self.read("deploy-mediahub.sh")
        required_phrases = [
            "git status --porcelain",
            "git rev-parse --abbrev-ref HEAD",
            "BatchMode=yes",
            "IdentitiesOnly=yes",
            "composer install --no-dev --optimize-autoloader",
            "php artisan migrate --force",
            "php artisan config:cache",
            "php artisan route:cache",
            "php artisan view:cache",
            "npm ci",
            "npm run build",
            "apachectl configtest",
            "X-Robots-Tag",
            "Unexpected Apache Basic Auth challenge",
            "/api/v1/me",
            "stream_url",
            "provider_url",
            "playlist_url",
            "api_key",
            "secret",
        ]

        for phrase in required_phrases:
            self.assertIn(phrase, script)

    def test_deploy_script_safely_syncs_react_build_into_laravel_public(self):
        script = self.read("deploy-mediahub.sh")

        required_phrases = [
            'FRONTEND_PUBLIC_DIR="${MEDIAHUB_FRONTEND_PUBLIC_DIR:-$SERVER_APP_DIR/backend/public}"',
            "sync_frontend_build()",
            'cp "$SERVER_APP_DIR/dist/index.html" "$FRONTEND_PUBLIC_DIR/index.html"',
            'mkdir -p "$FRONTEND_PUBLIC_DIR/assets"',
            'cp -a "$SERVER_APP_DIR/dist/assets/." "$FRONTEND_PUBLIC_DIR/assets/"',
            "for public_asset in favicon.svg mediahub-pinned-tab.svg site.webmanifest",
            '[[ -f "$FRONTEND_PUBLIC_DIR/index.php" ]]',
            '[[ -f "$FRONTEND_PUBLIC_DIR/.htaccess" ]]',
            '[[ -f "$FRONTEND_PUBLIC_DIR/index.html" ]]',
            'find "$FRONTEND_PUBLIC_DIR/assets" -maxdepth 1 -type f',
            "php artisan route:list --path=api/v1/status",
        ]

        for phrase in required_phrases:
            self.assertIn(phrase, script)

        self.assertNotIn('find "$FRONTEND_PUBLIC_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +', script)

    def test_browser_identity_and_domain_migration_are_prepared_without_cutover(self):
        index = self.read("index.html")
        self.assertIn("<title>MediaHub</title>", index)
        self.assertIn('href="/favicon.svg"', index)
        self.assertIn('href="/mediahub-pinned-tab.svg"', index)
        self.assertNotIn("Prototype", index)

        migration = self.read("docs/infrastructure/MEDIAHUB_DOMAIN_MIGRATION.md")
        vhost = self.read("deploy/mediahub-domain/mediahub.razbudise.mk.conf.example")
        redirect = self.read("deploy/mediahub-domain/ccc-redirect.conf.example")
        for phrase in ["mediahub.razbudise.mk", "TLS", "canonical", "sitemap", "Search Console", "Rollback"]:
            self.assertIn(phrase, migration)
        self.assertIn("X-Robots-Tag", vhost)
        self.assertIn("SSLCertificateFile", vhost)
        self.assertIn("ccc.razbudise.mk", redirect)
        self.assertIn("R=308", redirect)

    def test_rollback_script_restores_latest_backup_and_verifies(self):
        script = self.read("rollback-mediahub.sh")
        required_phrases = [
            "MEDIAHUB_ROLLBACK_BACKUP",
            "ls -1dt",
            "database.sqlite",
            "apachectl configtest",
            "systemctl reload apache2",
            "X-Robots-Tag",
            "BatchMode=yes",
            "IdentitiesOnly=yes",
        ]

        for phrase in required_phrases:
            self.assertIn(phrase, script)

    def test_infrastructure_docs_cover_required_stack_and_ssh(self):
        docs = {
            "docs/infrastructure/DEPLOYMENT_ARCHITECTURE.md": [
                "GitHub",
                "web01",
                "SSH",
                "Apache",
                "PHP",
                "Node",
                "Laravel",
                "React",
                "public login",
                "Laravel authentication",
                "noindex",
            ],
            "docs/infrastructure/SSH_SETUP.md": [
                "~/.ssh/config",
                "authorized_keys",
                "IdentityFile",
                "Host web01",
                "Host razbudise",
                "~/.ssh/123url_ed25519",
                "ssh-add --apple-use-keychain",
            ],
            "docs/infrastructure/DEPLOYMENT_CHECKLIST.md": [
                "Before deploy",
                "During deploy",
                "After deploy",
                "Rollback",
            ],
            "docs/infrastructure/MONITORING_AND_HEALTH.md": [
                "/api/v1/status",
                "/api/v1/media-events",
                "401",
                "X-Robots-Tag",
                "public login",
                "stream/provider URLs",
            ],
        }

        for relative_path, expected_terms in docs.items():
            content = self.read(relative_path)
            for term in expected_terms:
                self.assertIn(term, content)


if __name__ == "__main__":
    unittest.main()
