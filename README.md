# ShipIt 🚀

[![Latest Version on Packagist](https://img.shields.io/packagist/v/zerofyi/shipit.svg?style=flat-square)](https://packagist.org/packages/zerofyi/shipit)
[![Total Downloads](https://img.shields.io/packagist/dt/zerofyi/shipit.svg?style=flat-square)](https://packagist.org/packages/zerofyi/shipit)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHP Version Support](https://img.shields.io/badge/php-%5E8.1-blue.svg?style=flat-square)](https://php.net)

`ShipIt` is an enterprise-grade, zero-dependency deployment engine engineered specifically to bridge local development environments with **Hostinger Shared Hosting** architectures.

It cleanly handles the entire deployment pipeline in under a minute — staging your git changes, pushing code upstream, establishing secure trust handshakes, streaming compiled UI bundles, and firing remote Laravel optimizations over strict circuit-breaking SSH channels.

---

## 🔥 Key Features

- **Dual-Protocol Smart Switching:** Keeps your local machine on **HTTPS** while forcing the Hostinger server to communicate over **SSH** via private deployment identities — no conflicts, no credential clashes.
- **Agnostic Privacy Detection:** Pre-flights repository visibility automatically. Public repos clone and pull over HTTPS without any key setup. Private repos activate the token injection core.
- **Automated Deploy Key Injection:** Uses your `GITHUB_API_TOKEN` to handle remote RSA key creation and GitHub handshaking end-to-end. Falls back to a guided manual card if token access is unavailable.
- **Compressed Asset Streaming:** Skips slow FTP uploads entirely. Locally built Vite/Mix bundles are archived and streamed as compressed binaries directly over your active SSH session.
- **Circuit-Breaker Pipeline:** If any remote step fails, the engine halts immediately to protect your live site from partial deployments.

---

## 💾 Installation

```bash
composer require zerofyi/shipit
```

The package uses Laravel's auto-discovery — no manual provider registration needed.

---

## 🔑 SSH Prerequisite

> **ShipIt requires a passwordless SSH connection to your Hostinger server before first use.**
>
> Your local machine must be able to connect to Hostinger over SSH **without being asked for a password or passphrase**. If that's not set up yet, [see the SSH Setup Guide ↓](#-ssh-setup-guide).

---

## ⚙️ Environment Configuration

Add the following to your local `.env` file:

```env
# ------------------------------------------------------------------------------
# GitHub
# ------------------------------------------------------------------------------
GITHUB_REPO_URL=https://github.com/your-username/your-repo.git
GITHUB_API_TOKEN=ghp_yourPersonalAccessTokenHere

# ------------------------------------------------------------------------------
# Hostinger
# ------------------------------------------------------------------------------
HOSTINGER_SSH_HOST=12.34.56.78
HOSTINGER_SSH_USERNAME=u123456789
HOSTINGER_SSH_PORT=65002
HOSTINGER_SITE_DIR=yourdomain.com
```

> 🔒 `HOSTINGER_SITE_DIR` is validated against path traversal payloads (`..`, `/`, `\`) before any remote operation runs.

---

## 🚀 Usage

### Deploy to Hostinger

```bash
php artisan push:hostinger
```

The master deployment command. Builds assets locally, commits and pushes to GitHub, then syncs your server and runs all remote optimizations in one shot.

| Flag | Description |
|------|-------------|
| `--dry-run` | Simulates the entire pipeline — checks environments, builds assets, and tests the server connection — without touching your live server. Perfect for testing before a real deploy. |
| `--debug` | Prints raw network payloads, shell command statuses, and step-by-step error traces directly in your terminal. |

---

### Push to GitHub Only

```bash
php artisan push:github
```

Standalone Git command. Scans for uncommitted changes, prompts for a commit message, and pushes to your remote branch.

| Flag | Description |
|------|-------------|
| `--dry-run` | Checks your uncommitted changes and active branch, but halts before staging or committing anything. |
| `--debug` | Streams raw Git execution output directly to your terminal. |
| `--skip-assets` | Bypasses local asset compilation (`npm run build`). Used internally by `push:hostinger` to ensure assets are only built once per full run. |
| `--timeout=60` | Maximum execution time in seconds for the Git push operation. Defaults to `60` if not specified. |

---

## 📐 Deployment Pipeline

`push:hostinger` runs these phases in strict sequence:

1. **Environment Scan** — Validates and sanitizes all `.env` values before any network activity begins.
2. **Asset Compilation** — Detects `package.json`, installs dependencies if needed, and runs a production build.
3. **Git Commit & Push** — Stages your changes, collects a commit message, and pushes to your remote branch.
4. **Server Connection** — Opens a passwordless SSH tunnel to Hostinger and confirms the target site directory exists.
5. **GitHub Trust Check** — Evaluates repository visibility. Generates and registers an RSA deploy key for private repos, or skips for public ones.
6. **Sync & Optimize** — Pulls the latest code, streams compressed asset bundles, then runs the full remote chain:
   - Preserves your production `.env`
   - `composer install --no-dev --optimize-autoloader`
   - `php artisan migrate --force`
   - `php artisan storage:link`
   - `ln -sfn public public_html`
   - `php artisan optimize`

---

## 🛠 SSH Setup Guide

> One-time setup. Once done, ShipIt connects to your server automatically on every deploy.

**Official Hostinger reference:** [How to Set Up SSH Keys → hostinger.com](https://www.hostinger.com/tutorials/how-to-set-up-ssh-keys)

---

### Step 1 — Enable SSH in hPanel

Log into **hPanel → SSH Access** and make sure SSH is enabled for your plan. Your SSH host, username, and port are listed here — copy them into your `.env`.

---

### Step 2 — Generate an SSH Key Pair

Run **one** of these commands depending on your preferred key type:

**Ed25519** (recommended — modern, faster):
```bash
ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C "shipit"
```

**RSA 4096** (broader compatibility):
```bash
ssh-keygen -t rsa -b 4096 -N "" -f ~/.ssh/id_rsa -C "shipit"
```

The `-N ""` flag sets an **empty passphrase** automatically — no prompts, no hanging.

---

### Step 3 — Print & Copy Your Public Key

**Ed25519:**
```bash
cat ~/.ssh/id_ed25519.pub
```

**RSA:**
```bash
cat ~/.ssh/id_rsa.pub
```

Select and copy the entire output — it starts with `ssh-ed25519` or `ssh-rsa` and ends with `shipit`.

---

### Step 4 — Add the Key to Hostinger hPanel

1. In **hPanel → SSH Access**, click **Add SSH Key**.
2. Paste the copied key into the public key field.
3. Give it a name (e.g. `shipit`) and save.

---

### Step 5 — Test the Connection

```bash
ssh -p YOUR_SSH_PORT YOUR_SSH_USERNAME@YOUR_SSH_HOST
```

If you land in the remote shell **with no password prompt**, you're all set. ShipIt is ready to deploy.

> If it still asks for a password, confirm the correct key was saved in hPanel and that SSH is enabled on your plan.

---

## 📄 License

The MIT License (MIT). Please see the [License File](LICENSE) for more details.