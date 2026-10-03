"""Validate the exact additive migrations reviewed for a release."""
import hashlib
import json
import subprocess
import sys


def validate(before, target):
    diff = subprocess.check_output(["git", "diff", "--name-status", before, target, "--", "backend/database/migrations"], text=True)
    if not diff.strip():
        return []
    try:
        plan = json.loads(subprocess.check_output(["git", "show", f"{target}:scripts/release-migrations.json"], text=True))
    except (subprocess.CalledProcessError, json.JSONDecodeError) as error:
        raise ValueError("Schema changes require a separately reviewed migration plan") from error
    paths = []
    for row in diff.splitlines():
        status, path = row.split("\t", 1)
        if status != "A" or path not in plan.get("additive", {}):
            raise ValueError("Only explicitly reviewed new migration files may deploy")
        content = subprocess.check_output(["git", "show", f"{target}:{path}"])
        if hashlib.sha256(content).hexdigest() != plan["additive"][path]:
            raise ValueError("Migration differs from its reviewed checksum")
        paths.append(path)
    return paths


if __name__ == "__main__":
    try:
        for path in validate(*sys.argv[1:]):
            print(path)
    except (ValueError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
