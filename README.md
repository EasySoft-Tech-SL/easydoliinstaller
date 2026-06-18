<h1 align="center">EasyDoliInstaller 🐦</h1>

<p align="center">
  <b>One-file automatic installer for <a href="https://www.dolibarr.org">Dolibarr ERP/CRM</a>.</b><br>
  Upload a single PHP file, pick a version, and it unpacks and runs the <i>entire</i> installation for you — Duplicator-style.
</p>

<p align="center">
  <a href="https://github.com/EasySoft-Tech-SL/easydoliinstaller/releases/latest"><img src="https://img.shields.io/github/v/release/EasySoft-Tech-SL/easydoliinstaller?sort=semver&display_name=tag&style=for-the-badge&color=43ff7d&label=release" alt="Release"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License: MIT"></a>
  <img src="https://img.shields.io/badge/PHP-7.4%20%E2%86%92%208.3-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 7.4 to 8.3">
  <img src="https://img.shields.io/badge/DB-MySQL%20%7C%20MariaDB%20%7C%20PostgreSQL-F29111?style=for-the-badge" alt="Databases">
  <img src="https://img.shields.io/badge/i18n-EN%20ES%20DE%20FR%20IT-43ff7d?style=for-the-badge" alt="Languages">
  <a href="https://github.com/easySoft-Tech-SL"><img src="https://img.shields.io/badge/Built%20by-Easysoft%20Tech-8a2be2?style=for-the-badge" alt="Built by Easysoft Tech"></a>
</p>

---

**EasyDoliInstaller** turns the multi-step Dolibarr setup into a single, self-contained `easydoliinstaller.php`. Drop it on your hosting, open it in a browser, and a guided wizard checks requirements, fetches the package (or uses one you uploaded), unpacks `htdocs`, creates the database, tables, reference data and administrator, locks the install and then **deletes itself**. No SSH, no manual config files, no clicking through the native wizard.

It ships with a retro **terminal/CRT interface** and a **real live log** of every download/extraction block and install step — not a fake progress bar.

```text
 ┌─[ EasyDoliInstaller ]──────────────────────────────────┐
 │  EN  ES  DE  FR  IT                  2026-06-17 18:42   │
 │  [x] start  [x] package  [x] requirements  [>] install │
 │  ┌──────────────────────────────────────────────────┐ │
 │  │ 18:42:01  > step1: create config and database  OK │ │
 │  │ 18:42:02  > step2: create tables ... working (21s)│ │
 │  │ 18:42:23  [ OK ] 272 tables created               │ │
 │  │ 18:42:24  > step5: create admin and lock       OK_│ │
 │  └──────────────────────────────────────────────────┘ │
 └────────────────────────────────────────────────────────┘
```

## ✨ Features

<table>
<tr><td>📦 <b>Single file</b></td><td>Everything lives in one <code>easydoliinstaller.php</code>. Upload it alone.</td></tr>
<tr><td>📥 <b>Autonomous download</b></td><td>Pick a Dolibarr version and it downloads the official package from SourceForge in blocks (real progress bar). Version list pulled live from GitHub. Or use a ZIP you already uploaded.</td></tr>
<tr><td>⚡ <b>Native-speed unpack</b></td><td><code>ZipArchive::extractTo</code> in C, by blocks — ~17,000 files in seconds, like 7-Zip.</td></tr>
<tr><td>🤖 <b>Two modes</b></td><td><b>Automatic</b> (DB + tables + admin + lock, zero clicks) or <b>Extract-only</b> (unpack and hand off to Dolibarr's native wizard).</td></tr>
<tr><td>⬆️ <b>Upgrade existing</b></td><td>Detects an installed Dolibarr and upgrades it: downloads a newer version, <b>preserves</b> <code>conf/</code>, <code>custom/</code> and <code>documents/</code>, and runs every native migration <b>one major at a time in a single pass</b> with a live log (e.g. v6 → v23). No downgrades; auto DB restore point before migrating.</td></tr>
<tr><td>🛠️ <b>Repair / verify</b></td><td>Checks an install <b>file-by-file</b> against the official package of the <b>same version</b>; reports <b>changed, missing and unexpected/injected</b> files (excluding <code>conf/</code>, <code>custom/</code>, <code>documents/</code>), downloads a ZIP of the affected files, restores from the official package and (optionally) deletes the unexpected ones — all with confirmation. A real integrity/anti-tamper check.</td></tr>
<tr><td>🗄️ <b>MySQL &amp; PostgreSQL</b></td><td>Choose the engine; default port and verification adapt automatically.</td></tr>
<tr><td>🌐 <b>5 languages</b></td><td>English, Español, Deutsch, Français, Italiano — self-contained, with a switcher and browser auto-detection.</td></tr>
<tr><td>🖥️ <b>CRT UI + live log</b></td><td>Phosphor-green terminal aesthetic; every chunk and step is logged in real time.</td></tr>
<tr><td>🔒 <b>Hardened</b></td><td>TLS-verified download with SHA-256 check, anti-CSRF install token, secret purging, self-destruct.</td></tr>
<tr><td>🐘 <b>PHP 7.4 → 8.3</b></td><td>Tested across versions; no 8-only syntax.</td></tr>
<tr><td>🔁 <b>Resilient</b></td><td>Resumes after an F5, retries failed blocks/steps, detects an already-installed instance.</td></tr>
</table>

## 📦 Requirements

- **PHP 7.4 – 8.3** (absolute minimum 7.1).
- Extensions: `zip`, `json` (required); a DB driver — `mysqli`/`pdo_mysql` **or** `pgsql`/`pdo_pgsql`; `curl` **or** `allow_url_fopen`. Recommended: `gd`, `mbstring`, `xml`.
- The installation directory must be **writable**.
- A server that handles **more than one request at a time** (normal Apache/nginx). The single-threaded `php -S` dev server deadlocks on the automatic mode's self-call.

## 🚀 Getting started

1. Upload to the folder that will become your Dolibarr **web root**:
   - `easydoliinstaller.php` *(and nothing else)*
   - *Optional:* a `dolibarr-*.zip` if you prefer not to download it (otherwise the wizard fetches it).
2. Open it in your browser: `https://your-domain/easydoliinstaller.php`
3. Follow the wizard. In automatic mode, press **Clean up** at the end — the installer deletes itself and drops you into your fresh Dolibarr.

## 🎛️ Modes

| Mode | What it does | Flow |
|------|--------------|------|
| **Automatic** | Unpack → create database + tables + reference data + administrator → lock → self-destruct. **Zero clicks** in the native wizard. | `Start → Package → Requirements → Config → Extract → Install → Done` |
| **Extract-only** *(expert)* | Just unpack `htdocs` and redirect you to Dolibarr's native `install/` wizard to configure it yourself. | `Start → Package → Extract → native wizard` |
| **Update** *(existing install)* | Upgrade an installed Dolibarr: replace files (keeping `conf/`, `custom/`, `documents/`) and run **all native migrations in one pass** — or hand off to the native wizard step by step. | `Start → Update → Extract → Migrate → Done` |
| **Repair** *(existing install)* | Verify integrity against the official same-version package and restore changed/missing core files. | `Start → Repair → Verify → Report → Repair` |

## ⚙️ How it works (automatic mode)

1. Detects (or downloads) the ZIP and extracts **only the `htdocs/` subtree** as the web root.
2. Writes `install/install.forced.php` (Dolibarr unattended install, `noedit=2`) and an empty, writable `conf/conf.php`.
3. Drives Dolibarr's native steps over HTTP: `step1` (config + database) → `step2` (tables + reference data) → `step5` (admin + `install.lock`).
4. Verifies against the database (core tables, the specific admin user, the lock file).
5. Cleans up: removes `install/`, the ZIP, the temp dir, and **deletes itself**.

## ⬆️ Upgrading an existing Dolibarr

Drop `easydoliinstaller.php` into the root of an **already installed** Dolibarr and open it: it detects the installation and offers **Open / Update / Reinstall**. Choose **Update** and pick a newer version (the picker only lists versions **≥** the one installed — no downgrades).

- **Automatic (all at once)** — replaces the files **preserving** `conf/conf.php`, your `custom/` modules and `documents/`, then drives Dolibarr's own upgrade across every intermediate major version in a single run (`upgrade.php` → `upgrade2.php` per jump, `step5` to finish), showing a **live log** of each migration. A direct **v6 → v23** jump runs all 17 major migrations in one pass. Per-statement SQL notices are treated as warnings (exactly as the native wizard does); success is confirmed against the version recorded in the database.
- **Step by step** — replaces the files and hands you off to Dolibarr's native upgrade wizard.

> Upgrades modify the database. The wizard offers a downloadable `.sql` dump and auto-saves a restore point in `documents/` before migrating — **MySQL/MariaDB** natively, and **PostgreSQL** when `pg_dump` is available on the server.

## 🛠️ Repairing an existing Dolibarr

Detects an installed Dolibarr and offers **Repair / verify integrity**. Pick the **official package of the same version** (download or local ZIP) and it:

1. Compares the install **file-by-file** against the official package (by hash), in chunks with a live log.
2. Shows a **detailed report by sections** — **modified** (`~`), **missing** (`+`) and **unexpected/injected** (`!`, present locally but not in the official package, e.g. a planted webshell). `conf/`, `custom/`, `documents/` and the post-install `install/` directory are excluded, so your data, modules and the (normally removed) installer files are never flagged. Each **modified file expands to a line-by-line diff** (red = official, green = your install).
3. Lets you **download a ZIP** of the affected files, then **restores** changed/missing files from the official package and (optionally) **deletes the unexpected** ones — each on confirmation (a backup zip is kept first). This makes it a lightweight **integrity / anti-tamper** check.

Together with install and upgrade, this gives you the full lifecycle: **install · upgrade · repair**.

## 🔒 Security

EasyDoliInstaller is a **single-use** tool and is hardened accordingly:

- **Verified download** — package fetched over **HTTPS with certificate validation**, HTTPS-only redirects, and **SHA-256 integrity check** for known versions before extraction (defeats MITM / poisoned mirrors).
- **Anti-CSRF install token** — the installation is bound to the browser that started it (`HttpOnly` cookie); any third party gets **403** on extract/install/download/cleanup.
- **Secret lifecycle** — `install.forced.php` (with the DBMS root password) is deleted right after the admin is created; DB/admin passwords are purged from the temp config on completion; the temp dir expires (2 h) and is physically removed.
- **Robust self-destruct** — the file is overwritten with an inert `HTTP 410` stub before `unlink`, in case deletion fails (e.g. SFTP owner ≠ web user).
- Host-header allow-listing, security headers (anti-clickjacking / `no-store`), `0640` config permissions.

> **Note:** It still has no login (by design — it's a one-shot installer). It self-destructs when finished; if you interrupt the process, delete `easydoliinstaller.php`, the `.zip` and `__doli_installer_tmp__/` manually. Never leave it on a public server longer than needed.

## 🌐 Languages

Interface available in **English · Español · Deutsch · Français · Italiano**, with an in-page switcher (remembered via cookie) and `Accept-Language` auto-detection. UI, labels, validations, error messages and the live log are all translated.

## 🗄️ Compatibility

- **The installer (this PHP file):** runs on PHP 7.4 → 8.3 (lint-verified on 7.4, 8.1, 8.3).
- **Dolibarr versions:** installs and upgrades the **v3 – v23** range. The installer adapts to each version's contract (old `etapeN.php` vs `stepN.php`, POST-only vs forced install). Whether a given version actually runs depends on its own PHP requirement, so…
- **Version-aware requirements step:** before installing, the wizard reads the **chosen package's own** `install/check.php` and checks your PHP against that version's declared minimum/maximum — blocking if it's below the minimum and warning if your PHP is newer than tested or the package is from the PHP-5 era (e.g. Dolibarr 3.6 won't run on PHP 7+). No surprises mid-install.
- **Databases:** MySQL / MariaDB and PostgreSQL.
- **Packages:** the official Dolibarr ZIP (`dolibarr-x.y.z/htdocs/...`), a ZIP whose root is `htdocs/`, or a ZIP that already *is* the `htdocs` content.

## 🤝 Contributing

Issues and pull requests are welcome. The whole tool is one auditable PHP file — keep it self-contained, PHP 7.4-compatible, and free of external dependencies. See **[CONTRIBUTING.md](CONTRIBUTING.md)** for the architecture, how to test, how to run lint, and how to add SHA-256 hashes or a UI language.

Found a security issue? See **[SECURITY.md](SECURITY.md)**.

## 👤 Author

**Alberto Luque Rivas** — [aluquerivasdev@gmail.com](mailto:aluquerivasdev@gmail.com)

## 📄 License

[MIT](LICENSE) © [Alberto Luque Rivas](mailto:aluquerivasdev@gmail.com) / [Easysoft Tech S.L.](https://github.com/easySoft-Tech-SL)

> EasyDoliInstaller is an independent tool, MIT-licensed. Dolibarr itself is distributed separately under GPL-3.0; this installer downloads/installs it but does not bundle its source.
