# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.2.0] - 2026-11-20

The first-deploy release. A new Laravel app used to discover its missing `.env.production` halfway through the deploy — after assets were built, code was pushed and a backup was taken. Now ShipIt asks for it up front, in two steps, before any of that happens.

### Added
- **`.env.production` setup wizard (two steps).** On the first deploy — when the server has no `.env` yet and there is no local `.env.production` — ShipIt runs the wizard right after the SSH handshake, before assets, git push and backup:
  - **Step 1 — create the file:** (1) copy the local `.env` and make it production-ready (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<site>`, existing `APP_KEY` kept, a fresh one generated only when missing, local-only toggles like `DEBUGBAR_ENABLED`/`TELESCOPE_ENABLED` switched off, comments and ordering preserved), (2) copy `.env.example`, or (3) stop. The changed keys are shown (never values) and confirmed before writing; an existing `.env.production` is never overwritten without confirmation.
  - **Step 2 — set up the values:** scans the created file for the production values a deploy needs — database first (`DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`; on Hostinger the host is `localhost` and the values come from hPanel → Databases), then `APP_URL` and `APP_KEY`, everything else (mail, log level) as warnings — and pauses so you can edit the file and press ENTER to re-check, type `continue` to deploy anyway, or `stop` to quit.
- **Gitignore guard.** The wizard makes sure `.env.production` is in `.gitignore` (appending or creating the file when needed), so production secrets can never be committed by `push:github`.
- **Doctor checks `.env.production` on first deploys.** When the server has no `.env` yet, `shipit:doctor` warns when the local file is missing, scans an existing file for production safety (`APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY`, database credentials), and offers to run the wizard inline.
- **`shipit:env` offers the wizard instead of a hard error** when no local `.env.production` exists (unless `--yes` is passed, which keeps the old fail-fast error for scripts).
- **Non-interactive safety.** The wizard never prompts in `--dry-run` (it reports what it would do) or in a piped/scripted run (the deploy fails with a clear message telling you to create the file first).
- **Tests.** 24 new tests (14 unit, 10 feature) covering the transform, the placeholder/scan rules, the gitignore guard, and the wizard flows through `push:hostinger`, `shipit:doctor` and `shipit:env`. Suite is now 103 tests / 500 assertions, green on Laravel 10, 11, 12 and 13.

### Changed
- **Handshake before asset build.** `push:hostinger` now verifies the SSH connection before compiling frontend assets, so connection problems fail fast instead of after a minute of local compilation. The deploy lock is taken after the wizard (phase 5). The pipeline is now 15 phases — see the README.
- The test `FakeServer` now simulates the SCP upload of `.env.production`, so first-deploy tests can assert what actually lands on the server (test-only change).

### Fixed
- **First deploy no longer discovers the missing `.env.production` late.** Previously it was only found at the env-provisioning phase — after assets were built, code was pushed to GitHub, a backup was taken and code was synced — and the fallback was to copy `.env.example` on the server and hand-edit it there. The wizard catches it at phase 3, before any of that work happens. (The server-side fallback remains as a safety net for a failed upload.)

## [2.1.0] - 2026-10-20

The safety release. ShipIt can still deploy in under a minute — but now, when a deploy goes wrong, you have a way back.

### Added
- **Pre-deploy backup snapshots.** Before `push:hostinger` changes anything on the server, it snapshots the site: database dump (mysqldump `--single-transaction` fast path, automatic PHP/PDO fallback supporting MySQL/MariaDB and SQLite), `storage/app` uploads, `public/build` assets, `.env` copy and a manifest. Server-side credentials are read from the site's own `.env` and never travel or print. A failed backup aborts the deploy before production is touched. Disable per deploy with `--skip-backup` or globally via `SHIPIT_BACKUP=false`.
- **`shipit:rollback` command.** Returns a site to any snapshot: exact git commit, `composer install`, captured assets, optional `--with-storage`, `--with-env`, and database recovery via `--with-db` (full restore; always requires typing the site directory to confirm) or `--migrate-rollback` (reverse last batch). A safety snapshot of the pre-rollback state is taken first, so rollbacks are reversible.
- **`shipit:backup` and `shipit:backups` commands.** On-demand snapshots, plus list / delete / prune management. Snapshots live per site at `~/shipit-backups/<site>/`, outside the web root, with retention (default 3) and optional automatic local download.
- **Deploy journal.** An append-only, per-site `journal.json` on the server recording every deploy (commits, branch, partial file lists, backup id, failed step), rollback, backup and env sync.
- **`shipit:doctor` command.** Local + server pre-flight (PHP versions, git/composer/mysqldump, `exec`/`symlink`/`proc_open`/`shell_exec`, directory writability, disk + inode headroom, database reachability) with the exact fix printed per problem and a CI-friendly exit code.
- **`shipit:env` command.** Diff (keys only, values never shown) and sync the server `.env` from local `.env.production`, backing up the old `.env` on the server first and journaling the change.
- **Selective deployments.** `push:github` and `push:hostinger` accept `--only` (interactive numbered picker) and `--files="a.php,b.php"`: only the chosen files are staged, committed and deployed through the normal pipeline; all other work-in-progress stays local and untouched. Partial deploys are recorded as such in the journal.
- **Site profiles for multi-website accounts.** Publish `config/shipit.php` (`--tag=shipit-config`) and define named `sites`; every command accepts `--site=<name>`. Values fall back to `.env`, and backups/journals/locks/rollbacks are strictly per-site.
- **Maintenance-mode migration window.** The site shows the maintenance page while migrations run, and always comes back up — even when a critical step fails.
- **Post-deploy health check with instant rollback offer.** The deployed site (plus optional configured URLs) must answer 2xx/3xx; on failure ShipIt offers a one-step rollback to the previous known-good deploy.
- **Failure recovery report.** A failed deploy ends with a printed report: steps completed, whether migrations ran, maintenance-mode state, the backup snapshot id, and the exact recovery command.
- **Deploy lock.** Per-site lock prevents concurrent ShipIt operations against the same site (stale after 2 hours).
- **Deploy hooks.** `shipit.hooks.before` (failure aborts) and `shipit.hooks.after` (warnings only) shell commands executed on the server; `php artisan queue:restart` joined the optimization loop.
- **`--branch`, `--message`, `--timeout` on `push:hostinger`** (timeout is now forwarded to the GitHub wizard), `--skip-assets` on `push:hostinger`, and `--message` / `--site` on both push commands.
- **Test suite + CI.** 79 tests (unit, fake-server command flows, real database dump/restore roundtrips, real-git selective-commit integration) and a GitHub Actions matrix across PHP 8.1–8.4 × Laravel 10–13.

### Changed
- **Asset delivery is atomic.** The new `public/build` is extracted to a staging directory and moved into place only when complete; a failed stream leaves the previous production assets untouched instead of a deleted build directory.
- **`APP_KEY` is never rotated silently.** `key:generate` after an `.env.production` upload now runs only when the uploaded file has no `APP_KEY=base64:` value.
- **Dry-run output is honest.** Simulated steps are labelled as what *would* happen (no more "safely staged and committed" for actions not taken), and a dry run writes no journal, takes no lock and creates no backup.
- `composer.json` now declares the Illuminate components the code actually uses (`illuminate/http`, `illuminate/process`) alongside console/support.

### Fixed
- **Token no longer persists on the server.** When the HTTPS rescue fallback is used, the server's git remote is rewritten to the clean token-free URL immediately after the sync; previously the `x-access-token` URL (with the Administration-privileged token) stayed in the server's `.git/config` indefinitely.
- **Repository URL validation is real.** `FILTER_DEFAULT` performed no validation at all; URLs are now shape-validated for both HTTPS and SSH forms.
- **Origin mismatch is detected.** The local `origin` is compared to `GITHUB_REPO_URL` (protocol-insensitively); a mismatch stops the push and asks, instead of silently pushing to the wrong repository.
- **SSH port hint.** Connection failures now point out that Hostinger's SSH port is usually 65002, not 22.
- **Remote command construction is consistently quoted**, and server configuration values (host, username, site directory) are strictly validated against shell metacharacters and path traversal before any interpolation.

## [2.0.2] - 2026-10-01

### Added
- Introduced automated local `.env.production` detection and secure SCP transfer pipelines directly into the remote deployment sequence.
- Integrated an interactive execution pause safeguard when copying `.env.example`, allowing developers to set up production database credentials before migrations run.

### Changed
- Extracted `.env` provisioning out of the standard optimization loop into a dedicated pre-flight phase inside `PushHostinger.php`.


## [2.0.1] - 2026-07-17

### Fixed
- Forced manual cache clearance on upstream Composer package listings by introducing an explicit structural repository layout patch.
- Resolved an internal repository anchor tracking issue inside project configuration documentation.

## [2.0.0] - 2026-07-17

### Added
- Standardized documentation structural architecture, correcting multi-byte anchor link layout alignments across Markdown preview surfaces.

### Changed
- Refactored token configuration guidelines to specify explicit Fine-Grained token recreate-and-replace workflows.
- Bumped production distribution manifest blocks to clear upstream composer repository cache pipelines.

---

## [1.3.0] - 2026-07-17

### Added
- Introduced an intelligent **Token-Based HTTPS Rescue Fallback Wrapper** (`x-access-token`) to automatically bypass GitHub API `422` public key collisions on multi-tenant shared servers.
- Integrated an intelligent **SSH Remote Diagnostic Engine** into pre-flight checks to automatically differentiate between disabled server SSH configurations and missing target domain directory paths.
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