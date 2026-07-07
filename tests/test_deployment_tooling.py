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
            "stream_url",
            "provider_url",
            "playlist_url",
            "api_key",
            "secret",
        ]

        for phrase in required_phrases:
            self.assertIn(phrase, script)

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
                "Basic Auth",
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
                "stream/provider URLs",
            ],
        }

        for relative_path, expected_terms in docs.items():
            content = self.read(relative_path)
            for term in expected_terms:
                self.assertIn(term, content)


if __name__ == "__main__":
    unittest.main()
