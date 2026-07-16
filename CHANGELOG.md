# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-07-17

### Added
- Introduced an intelligent **Token-Based HTTPS Rescue Fallback Wrapper** (`x-access-token`) to automatically bypass GitHub API `422` public key collisions on multi-tenant shared servers.
- Added visual interactive processing indicators (`... Processing: [Task] ...`) providing real-time execution feedback during long-running async terminal commands.

### Changed
- Refactored all console outputs to utilize terminal-safe standard ASCII/ANSI indicators (`[->]`, `[o]`, `[+]`, `[!]`, `[x]`) to eliminate broken multibyte rendering blocks on classic command prompt shells.

### Fixed
- Resolved a runtime crash inside `PushHostinger` by aligning verification confirmations strictly with the inherited `$this->confirmYN()` engine layout.
- Documented operational recovery paths for handling SQLite config dialect crashes during forced pipeline migration interruptions.

---

## [1.2.0] - 2026-06-11

### Added
- Added official support for Laravel 13 framework environments.
- Introduced an interactive, real-time native OS fallback wizard loop for the storage link command block.

### Changed
- Re-architected error tracking parameters within deployment queues to catch silent server exceptions.

---

## [1.1.1] - 2026-06-09

### Fixed
- Fixed an issue where `storage:link` failures crashed the pipeline silently on restricted shared hosting nodes.
- Resolved a missing trace visibility bug by forcing standard error channels (`2>&1`) down into the `--debug` log stream output buffer.

---

## [1.0.1] - 2026-06-08

### Added
- Created an extensive, enterprise-grade `README.md` complete with architecture flowcharts, setup parameters, and CLI execution flag maps.

---

## [1.0.0] - 2026-06-08

### Added
- Initial stable production release of the **ShipIt** deployment execution engine.
- Implemented smart dual-protocol URL switching (local HTTPS to remote SSH).
- Integrated automated GitHub Deploy Key creation routines using private API access tokens.
- Established a circuit-breaking remote performance optimizer loop to automate server file links, environment updates, code dependency building, and Laravel system caching.