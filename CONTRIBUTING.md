# Contributing to EasyDoliInstaller

Thanks for your interest! EasyDoliInstaller is a **single, self-contained PHP file** (`easydoliinstaller.php`) that installs, upgrades and repairs [Dolibarr](https://www.dolibarr.org). Issues and pull requests are welcome.

## Golden rules

1. **One file, zero dependencies.** Everything lives in `easydoliinstaller.php`. No Composer, no external libraries, no extra assets. The user uploads one file.
2. **PHP 7.4 → 8.3 compatible.** No PHP 8-only syntax. Handle the PDO/mysqli/pgsql differences of 8.1+. Avoid functions removed in 8.x.
3. **Keep it auditable.** Plain, readable procedural PHP with `di_*`-prefixed functions. Comment the *why*, not the *what*.
4. **Self-cleaning & safe.** Anything that writes secrets must be purged; anything that can overwrite/destroy data must ask for confirmation and (where it applies) keep a backup first.

## Architecture at a glance

The file is organized top-to-bottom as: constants → i18n dictionary → helpers → DB layer → HTTP client (drives Dolibarr's native `install/`) → backup/repair → AJAX router → form handlers → page views.

**Modes** (`$cfg['mode']`):

| Mode | Flow |
|------|------|
| `full` | bienvenida → paquete → requisitos → config → extraer → instalar → finalizar |
| `simple` | bienvenida → paquete → extraer → redir (native wizard) |
| `update` | bienvenida → actualizar → [descargar] → extraer → migrar → finalizar |
| `update_manual` | bienvenida → actualizar → extraer → redir (native upgrade wizard) |
| `repair` | bienvenida → reparar → [descargar] → verificar → informe |

**Key functions**

- `di_t($key, $rep)` / `di_dict()` — i18n (EN/ES/DE/FR/IT).
- `di_http()` — drives Dolibarr's native `install/` pages over HTTP (self-call) with a cookie jar.
- `di_run_substep()` — install steps (`step1`/`step2`/`step5`, resolving `etapeN.php` vs `stepN.php`).
- `di_run_upgrade_substep()` — upgrade chain (`backup`, `up`/`up2` per major, `step5`).
- `di_finalize_extraction()` / `di_finalize_upgrade()` — move/replace files (upgrade preserves `conf/`, `custom/`, `documents/`).
- `di_dump_db_to()` / `di_dump_mysql_to()` / `di_dump_pgsql_to()` — backups (MySQL in PHP; PostgreSQL via `pg_dump`).
- `di_compare_chunk()` / `di_scan_extras()` / `di_repair_apply()` — integrity check & repair.
- `di_requisitos()` — version-aware requirements (reads the package's own `install/check.php`).

The wizard talks to itself with AJAX endpoints under `?ajax=…` (`extraer`, `instalar`, `descargar`, `migrar`, `comparar`, `extras`, `reparar`, `delextras`, `backup`, `repairzip`). Mutating endpoints require the per-install token (`di_token_ok`).

## Running the syntax check

```bash
php -l easydoliinstaller.php
```

CI (`.github/workflows/lint.yml`) runs `php -l` on PHP 7.4, 8.1 and 8.3 for every push and pull request. Keep it green.

## Testing locally

The installer drives Dolibarr's native `install/` over HTTP, so it needs a **multi-threaded** web server (Apache/nginx — `php -S` deadlocks on the self-call). The end-to-end approach used during development is a small PHP/cURL "driver" that reproduces the browser's requests against a local vhost:

1. Drop `easydoliinstaller.php` (and optionally a `dolibarr-x.y.z.zip`) into a web-served folder.
2. Drive `?paso=…` form POSTs and `?ajax=…` calls with a cookie jar.
3. Assert against the DB / filesystem (tables created, `install.lock`, `conf/conf.php`, restored files, …).

Test databases created for this should be disposable; prefix them so they're easy to drop (e.g. `esi_*`).

## Common contributions

**Add a known SHA-256 hash** (pins download integrity for a version):

```php
function di_known_hashes() {
    return array(
        '23.0.3' => '40c1c36133aeec69a6c1ca0c00edbed988b1655cc0a2a3fe34d51da1cd8f24e6',
        // 'x.y.z' => '<sha256 of the official dolibarr-x.y.z.zip>',
    );
}
```

Compute it from the **official** SourceForge zip (`sha256sum dolibarr-x.y.z.zip`). Unknown versions still download over TLS — they just aren't hash-pinned.

**Add a UI language**: add a new array to `di_dict()` with the same keys as the existing languages, and add its code/label to `di_langs()`. Untranslated keys fall back to English.

## Pull requests

- Keep changes within the single file (plus repo docs / CI).
- Make sure `php -l` passes on 7.4/8.1/8.3.
- Describe what you tested (mode, DB engine, Dolibarr version).
- Match the surrounding style; no external dependencies.

## License

By contributing you agree your contribution is licensed under the [MIT License](LICENSE).
