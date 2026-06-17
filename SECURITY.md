# Security Policy

## Reporting a vulnerability

Please report security issues privately to **aluquerivasdev@gmail.com** (subject: `EasyDoliInstaller security`). Do not open a public issue for undisclosed vulnerabilities. We'll acknowledge and work on a fix as soon as possible.

## Security model

EasyDoliInstaller is a **single-use** tool that runs **before authentication exists** (there is no Dolibarr user yet during a fresh install). It is hardened accordingly:

- **Verified download** — the Dolibarr package is fetched over **HTTPS with certificate validation**, HTTPS-only redirects, and a **SHA-256 integrity check** against embedded hashes for known versions before extraction.
- **Per-install token** — the wizard binds mutating actions (extract / install / download / migrate / repair / cleanup) to the browser that started the process via an `HttpOnly`, `SameSite=Lax` cookie. A third party gets **HTTP 403**.
- **No accidental overwrite** — extracting/downloading onto an already-installed Dolibarr is refused (HTTP 409) unless it's an explicit update or a confirmed reinstall. Destructive actions (reinstall, repair-restore, delete unexpected files, cleanup) require confirmation, and backups are kept first.
- **Secret lifecycle** — `install.forced.php` (with the DB root password) is deleted right after the admin is created; DB/admin passwords are purged from the temp config on completion; the temp dir expires and is physically removed. Backups and the temp config live in a web-protected, `0600`/`0700` location.
- **Anti Host-header poisoning** — `HTTP_HOST` is validated before being used as the base URL.
- **Self-destruct** — on completion the file overwrites itself with an inert `HTTP 410` stub and then `unlink`s.
- **Integrity / anti-tamper** — *Repair* mode compares the install file-by-file against the official package and flags modified, missing **and unexpected/injected** files (e.g. a planted webshell), excluding user data (`conf/`, `custom/`, `documents/`).

## Hardening guidance for users

- **Remove the installer when done.** It self-destructs on success; if you interrupt the process, delete `easydoliinstaller.php`, any `dolibarr-*.zip` and the `__doli_installer_tmp__/` folder manually.
- **Never leave it on a public server longer than needed** — by design it has no login.
- After an upgrade, delete the rollback dump (`documents/easydoliinstaller-rollback-*.sql`) once you've confirmed the result, since it contains a full copy of your database.
