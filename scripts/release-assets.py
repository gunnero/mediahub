"""Validate, stage, and publish public frontend files with explicit permissions."""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

PUBLIC_FILES = {"index.html", "favicon.svg", "mediahub-pinned-tab.svg", "site.webmanifest", "sw.js"}


def validate(source):
    source = Path(source)
    if source.is_symlink() or not source.is_dir():
        raise ValueError("Frontend must be a real directory")
    for file in source.rglob("*"):
        relative = file.relative_to(source)
        if file.is_symlink() or not (file.is_file() or file.is_dir()):
            raise ValueError(f"Unsupported frontend entry: {relative}")
        if relative.parts[0] != "assets" and str(relative) not in PUBLIC_FILES:
            raise ValueError(f"Unexpected frontend entry: {relative}")
    html = (source / "index.html").read_text()
    assets = re.findall(r'(?:src|href)="(/assets/[^"?#]+)"', html)
    if not any(name.endswith(".js") for name in assets) or not any(name.endswith(".css") for name in assets):
        raise ValueError("Built JavaScript/CSS references missing")
    for name in assets:
        path = source / name.lstrip("/")
        if ".." in Path(name).parts or not path.is_file():
            raise ValueError("Referenced frontend asset missing or invalid")
    return assets


def atomic_copy(source, destination):
    if destination.is_symlink():
        raise ValueError(f"Refusing public symlink: {destination.name}")
    fd, temporary = tempfile.mkstemp(prefix=".mediahub-", dir=destination.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(source.read_bytes())
            stream.flush()
            os.fchmod(stream.fileno(), 0o644)
        os.replace(temporary, destination)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def stage(source, public, commit):
    source, public = Path(source), Path(public)
    validate(source)
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Invalid release commit")
    if public.is_symlink() or not public.is_dir():
        raise ValueError("Public directory must be a real directory")
    for name in ["index.php", ".htaccess"]:
        if not (public / name).is_file():
            raise ValueError("Laravel public entry points missing")
    # Reject destination symlinks before writing any files; never follow storage.
    for file in source.rglob("*"):
        relative = file.relative_to(source)
        for path in [public / relative, *(public / relative).parents]:
            if path == public:
                break
            if path.is_symlink():
                raise ValueError(f"Refusing public symlink: {relative}")
    for file in sorted(source.rglob("*")):
        relative = file.relative_to(source)
        destination = public / relative
        if file.is_dir():
            destination.mkdir(exist_ok=True)
            destination.chmod(0o755)
        elif relative not in [Path("index.html"), Path("sw.js")]:
            destination.parent.mkdir(parents=True, exist_ok=True)
            atomic_copy(file, destination)
    if (source / "sw.js").is_file():
        atomic_copy(source / "sw.js", public / "assets" / f"mediahub-sw-{commit}.js")
    staged = public / "assets" / f"mediahub-release-check-{commit}.html"
    atomic_copy(source / "index.html", staged)
    # Probe the actual files through HTTP before switching the active index.
    return staged


def verify(source, url, index_path):
    source = Path(source)
    assets = validate(source)
    if (source / "sw.js").is_file():
        worker = "/sw.js" if index_path == "/" else index_path.replace("mediahub-release-check-", "mediahub-sw-").replace(".html", ".js")
        assets.append(worker)
    for remote, local in [(index_path, source / "index.html"), *[(name, source / ("sw.js" if "mediahub-sw-" in name else name.lstrip("/"))) for name in assets]]:
        data = subprocess.check_output(["curl", "--noproxy", "*", "--fail", "--silent", "--show-error", "--max-time", "30", url.rstrip("/") + remote])
        if hashlib.sha256(data).digest() != hashlib.sha256(local.read_bytes()).digest():
            raise ValueError(f"HTTP content mismatch: {remote}")


def publish(public, commit):
    public = Path(public)
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Invalid release commit")
    staged = public / "assets" / f"mediahub-release-check-{commit}.html"
    if staged.is_symlink() or (public / "index.html").is_symlink() or staged.stat().st_mode & 0o777 != 0o644:
        raise ValueError("Unsafe staged index permissions or link")
    os.replace(staged, public / "index.html")
    worker = public / "assets" / f"mediahub-sw-{commit}.js"
    if worker.is_file():
        atomic_copy(worker, public / "sw.js")
        worker.unlink()


if __name__ == "__main__":
    commands = {"validate": validate, "stage": stage, "verify": verify, "publish": publish}
    try:
        commands[sys.argv[1]](*sys.argv[2:])
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        sys.exit(f"Frontend verification failed: {error}")
