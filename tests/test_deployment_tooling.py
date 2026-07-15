import re
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PUBLIC_FILES = [
    ROOT / "deploy-mediahub.sh",
    ROOT / "rollback-mediahub.sh",
    ROOT / ".mediahub-deploy.env.example",
    ROOT / "docs/infrastructure/DEPLOYMENT.md",
]


class DeploymentToolingTest(unittest.TestCase):
    def test_shell_scripts_are_valid(self):
        for script in ("deploy-mediahub.sh", "rollback-mediahub.sh"):
            result = subprocess.run(
                ["bash", "-n", str(ROOT / script)],
                check=False,
                capture_output=True,
                text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)

    def test_public_tooling_contains_no_private_infrastructure(self):
        combined = "\n".join(path.read_text() for path in PUBLIC_FILES)
        self.assertIsNone(re.search(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", combined))
        self.assertNotIn("/Users/", combined)
        targets = re.findall(r"^MEDIAHUB_SSH_TARGET=(\S+)$", combined, re.MULTILINE)
        self.assertEqual(targets, ["deploy@example-host"])

    def test_frontend_sync_is_additive(self):
        deploy = (ROOT / "deploy-mediahub.sh").read_text()
        self.assertIn('cp "$SERVER_APP_DIR/dist/index.html" "$PUBLIC_DIR/index.html"', deploy)
        self.assertIn('cp -a "$SERVER_APP_DIR/dist/assets/." "$PUBLIC_DIR/assets/"', deploy)
        self.assertIn('[[ -f "$PUBLIC_DIR/index.php"', deploy)
        self.assertNotIn("rm -rf", deploy)

    def test_preflight_checks_runtime_and_private_configuration(self):
        deploy = (ROOT / "deploy-mediahub.sh").read_text()
        self.assertIn("git runuser php composer npm sqlite3 apachectl systemctl", deploy)
        self.assertIn("Laravel APP_KEY missing", deploy)
        self.assertIn("PRAGMA quick_check", deploy)
        self.assertIn("Git objects are not writable by site user", deploy)
        self.assertIn("Build path has files not owned by site user", deploy)
        self.assertIn("TMPDIR=/tmp PATH=/usr/local/bin:/usr/bin:/bin", deploy)
        self.assertNotIn('$(git -C "$SERVER_APP_DIR" status --porcelain)', deploy)
        self.assertGreaterEqual(
            deploy.count('$(run_as_site git -C "$SERVER_APP_DIR" status --porcelain)'),
            2,
        )

    def test_rollback_is_explicit_and_verified(self):
        rollback = (ROOT / "rollback-mediahub.sh").read_text()
        self.assertIn('CONFIRMED="true"', rollback)
        self.assertIn("sha256sum --check SHA256SUMS", rollback)
        self.assertIn('reset --hard "$target_commit"', rollback)
        self.assertNotIn("rm -rf", rollback)

    def test_private_profile_is_ignored(self):
        result = subprocess.run(
            ["git", "check-ignore", ".mediahub-deploy.env"],
            cwd=ROOT,
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == "__main__":
    unittest.main()
