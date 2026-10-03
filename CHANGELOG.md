# Changelog

Notable public-facing repository and product changes are recorded here. MediaHub is currently pre-release; version headings may describe release candidates.

## Unreleased

### Added

- Discovery pagination and URL-based search, category, media type, and page restoration.
- A local, application-owner deployment package with immutable source/assets, private recovery backups, and HTTPS asset checks before publishing the index.
- Bookmarkable sections and movie, show, and episode details, with browser Back/Forward navigation and direct links after sign-in.
- Log a past movie or episode watch with a local date and time; watch history and diary entries retain the chosen time.

### Fixed

- Cancel stale discovery searches and previews, restore keyboard focus when previews close, and support Escape and Tab navigation.
- Show save status and recoverable errors for discovery, lists, alerts, and notification settings; prevent duplicate submissions and retry refreshes without repeating successful writes.
- Validate complete compatibility-import snapshots before writing, add a no-write preview, and require explicit replacement with a private backup. Protect existing annotations and media relationships from orphaning.
- Cancel obsolete media-detail requests and ignore late responses, errors, and watch-action refreshes after the selected title changes or closes.

### Changed

- Reframed the repository around MediaHub and prepared a safe GitHub rename.
- Replaced operational runbooks with public deployment principles.
- Added security, contribution, CI, dependency, architecture, asset, and public-evidence documentation.
- Removed unproven generated poster assets from Git and documented a synthetic-first asset policy.
- Updated Vite to address a high-severity development-server advisory.

### Security

- Updated locked frontend test/build dependencies and Laravel, Filament, Livewire, CommonMark, and Flysystem within the existing supported version ranges to resolve dependency-audit advisories.
- Removed real staging hosts, SSH guidance, server paths, deployment topology, and rollback details from the current public tree.

## 1.0.0-rc1.1

- Stabilized profile avatar delivery and privacy-aware sharing behavior.

## 1.0.0-rc1

- Established the first MediaHub Web V1 release candidate.
