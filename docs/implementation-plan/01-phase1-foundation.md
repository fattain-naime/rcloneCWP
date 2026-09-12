# rcloneCWP — Phase 1: Foundation (Implementation Plan)

> **Status:** Draft for approval — no code written yet.
> **Target:** Phase 1 (Days 1–5) of the rcloneCWP build.
> **License:** MIT — open source and free for everyone. No license key, no
> phone-home, no ionCube on our code (CWP's own files may be encoded; ours are
> plain PHP).
> **Compatibility goal:** runs on any CWP configuration and behind any web server
> CWP supports (Apache, nginx, LiteSpeed, OpenLiteSpeed), across CWP PHP
> variants (7.1–8.x).

---

## 0. Why the plan changed from the blueprint

The original blueprint assumed a single environment. Research on a live CWP
server surfaced that the documented reality and the actual server disagree in
several load-bearing ways, and your compatibility goal adds two more. This
plan is the adjudicated result — where docs and the live server conflict, the
**live server wins**, and where the environment is **varied** (web server, PHP,
CWP layout), the plan must **not assume a single answer**.

1. **Web-server agnostic.** The live box runs nginx, but CWP ships Apache,
   nginx, LiteSpeed and OpenLiteSpeed variants. The module must not break on
   any of them. So access control is *placement-based* (runtime files outside
   the web root) and works on every server.
2. **PHP 7.1 floor.** CWP installs ship PHP 7.1/7.2/7.3 binaries. To run on
   the oldest, the code targets **PHP 7.1+** syntax (no typed properties,
   arrow fns, match, enums, `str_contains`, readonly, nullsafe, constructor
   promotion, `??=`). This runs on 7.1/7.2/7.3/8.x unchanged.
3. **CWP-layout and binary detection.** Credentials file, rclone path and
   module path vary across installs. The code probes at runtime with a fallback
   chain instead of hardcoding one path.

You also decided: **only the one-command install ships** (`curl | bash` from the
repo). There is no manual file-upload path. See §8.

---

## 1. Reality check (research-verified)

| # | Fact | Consequence |
|---|------|-------------|
| 1 | CWP PHP binary `/usr/local/cwp/php71/bin/php` is **PHP 7.2.30** on the live box; CWP also ships 7.1 and 7.3 variants on other installs | **Set the PHP floor to 7.1.** Only 7.1-safe syntax; all crypto via openssl (no sodium on 7.2). Validate with the CWP binary, never the system 8.3 CLI which masks parse errors. |
| 2 | CLAUDE.md's `/usr/local/cwp/.conf/mysql_db.cnf` **does not exist** on the live box; creds live in `/usr/local/cwpsrv/htdocs/resources/admin/include/db_conn.php` (`$db_host/$db_name=root_cwp/$db_user/$db_pass`) | Credentials resolved by a **runtime probe chain**: db_conn.php → mysql_db.cnf → `/root/.my.cnf` → a low-privilege module DB user. Never hardcode one path. |
| 3 | Live web server is **nginx** (cwpsrv 1.24); CWP also runs Apache / LiteSpeed / OpenLiteSpeed | Access control is **placement-first** (runtime files outside the web root) — effective on every server. |
| 4 | CWP modules are `include()`d by an ionCube dispatcher that sets `$include_path`; the module runs as **root** | Guard `if(!isset($include_path)){echo 'invalid access';exit();}` **plus** an independent session/login check (dispatcher ordering unverifiable). No inherited CSRF/sanitization — the module owns both. |
| 5 | `3rdparty.php` is one include line; CWP updates `unzip -o` **clobber** modules/* and 3rdparty.php | Installer is idempotent; the module entry **self-heals** its menu entry on load so a clobber restores itself. |
| 6 | rclone v1.75.0 on the live box; `config show` leaks cleartext secrets; `obscure` is obfuscation, not encryption | Wrapper permits only `config redacted`; AES-256-GCM lives at the app layer. rclone located by a probe chain (`/usr/bin`, `/usr/local/bin`, `PATH`). |
| 7 | At-rest AES-256-GCM key is root-readable by the same uid that runs PHP | It protects off-box DB-dump leaks only — a checkbox, not a defence against root/RCE. Not claimed otherwise. |

---

## 2. What Phase 1 delivers

Phase 1 delivers the **foundation layer** every later phase builds on: the
rclone-safe command wrapper, the DB/Logger/Encryption/Validator/CSRF
primitives, an idempotent installer, and a module entry point that provably
loads inside CWP admin — *without* any feature UI, backup/restore engine, or
destination backends.

### Deployment layout (off-htdocs split — the secure default)

```
<web module file>  /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP.php
                      # module entry: $include_path guard + session guard, dashboard stub
                      # (CWP dispatches modules as FLAT files — modules/<name>.php —
                      #  observed pattern of all 203 stock modules on the live server;
                      #  a subdirectory layout is NOT supported by the dispatcher)

<off-htdocs home>  /usr/local/cwp/rcloneCWP/
├── config.php             # constants, location probe chain, autoload registration
├── bootstrap.php          # spl_autoload_register for CWP\RcloneCWP (no composer)
├── install.php            # CLI-gated installer front-end (lives here, not web-served)
├── uninstall.php          # CLI-gated uninstaller front-end (lives here, not web-served)
├── lib/                   # Database, Logger, Encryption, Validator, CSRF, SchemaLoader, Installer
├── sql/install.sql        # DDL
└── tests/smoke.php        # one CLI run asserting every acceptance criterion
```

Only ONE file is web-exposed: the thin module entry. Everything sensitive
(`lib/`, `sql/`, `install.php`, `uninstall.php`, logs, keys) sits under
`/usr/local/cwp/rcloneCWP/`, outside every web server's document root —
access control is placement-based, so it works identically on Apache, nginx,
LiteSpeed and OpenLiteSpeed with no `.htaccess` dependence.

### Core library classes (public API — later phases code against these)

- **`lib/Database.php`** — PDO singleton: `getInstance()`, `getConnection():PDO`,
  `query()`, `fetch()`, `fetchAll()`, `insert()`, `update()`, `delete()`,
  **`prepare($sql):PDOStatement`**. `ERRMODE_EXCEPTION`,
  `ATTR_EMULATE_PREPARES=false`, `FETCH_ASSOC`. Credentials from the probe
  chain; CWP's own `$db` object never reused. Identifiers never bound —
  whitelisted against a fixed map.
- **`lib/Logger.php`** — instance style, injected: `debug/info/warning/error($msg,$context=[])`.
  Writes to `/var/log/rcloneCWP/rcloneCWP.log` + `rclone_logs`. Never logs secrets.
- **`lib/Encryption.php`** — `encrypt(string):string` / `decrypt(string):string`;
  AES-256-GCM, 12-byte random nonce, 16-byte tag, base64-wrapped
  nonce+tag+ciphertext; **`decrypt()===false` checked**. Key from
  `/usr/local/cwp/.conf/rclone_module_key.conf` (0600, `random_bytes(32)`).
- **`lib/Validator.php`** — static `string($v,$maxLen)`, `int()`, `enum()`,
  `remoteName()` (rejects leading `-`). Plus **`lib/CSRF.php`**:
  `generateToken()` / `validateToken($token):bool` (session-bound synchronizer).
- **`lib/Rclone.php`** — `execute()` whitelist
  (`copy,move,sync,ls,lsd,lsl,size,delete,rmdir,rmdirs,purge,mkdir,cat,checksum,version,config`);
  `escapeshellarg` **every** token; rejects remote names starting with `-`;
  denylists `--config/--password/--ask-password/-o` and any `--`-token and bare
  `--`; only server-side fixed flag maps for user options; `findBinary()` via
  probe chain; never `config show`. Default flags:
  `--transfers 4 --checkers 8 --buffer-size 32M --tpslimit 10 --retries 3 --fast-list`.
- **`lib/SchemaLoader.php`** — quote-aware `;` DDL splitter (won't split on a
  `;` inside a string literal) + executor via `PDO::exec`.
- **`lib/Installer.php`** — idempotent `install()`/`uninstall()`: env checks,
  schema apply, key creation, dir provisioning, menu add/remove.

### Database schema (`sql/install.sql`)

Eight tables (prefix `rclone_`), all InnoDB/utf8mb4/DATETIME like the blueprint,
**with these fixes** from research:

- Backups table is **`rclone_backups`** (not `rcloneCWPs`).
- `rclone_backups.backup_type` ENUM includes **`'selective'`** (drift vs `rclone_jobs`).
- `rclone_logs` gains **`destination_id`**, foreign keys, and a composite
  `(created_at, id)` index.
- Seed API key generated from **`random_bytes(32)`** with **no wildcard `*`**
  permission (the shipped `UUID()` + `['*']` seed is guessable and
  over-privileged).

---

## 3. Ordered build steps (each ends in a runnable check)

1. **`sql/install.sql`** — apply, then verify `information_schema` shows exactly
   8 `rclone_*` tables. Check: `/usr/local/cwp/php71/bin/php install.php`.
2. **`config.php` + `bootstrap.php`** — paths, autoload, credentials probe chain.
   Check: `/usr/local/cwp/php71/bin/php -l`.
3. **`lib/Database.php`** — lint + smoke "connects".
4. **`lib/Encryption.php`** — lint + round-trip; assert `decrypt('invalid') === false`.
5. **`lib/Validator.php` + `lib/CSRF.php`** — lint + smoke assertions.
6. **`lib/Logger.php`** — lint + smoke writes a file line + `rclone_logs` row.
7. **`lib/Rclone.php`** — lint + smoke runs `version`; reject an injected flag.
8. **`lib/SchemaLoader.php`** — lint + `php -r` statement-count round-trip.
9. **`lib/Installer.php` + CLI front-ends** — all lint; CLI gate
   (`PHP_SAPI !== 'cli'` refuses web execution).
10. **`rcloneCWP.php` + dashboard stub** — lint; guard-negative
    and guard-positive smoke tests.
11. **Deploy + menu self-heal** — idempotent add to `3rdparty.php`; module
    re-adds its own entry on load after a clobber.
12. **`tests/smoke.php`** — one CLI run asserting every acceptance criterion.
13. **Full verification** — lint every file with the **CWP** PHP 7.1 binary,
    run `install.php`, run `smoke.php`, checksum `3rdparty.php`/`db_conn.php`
    before/after to prove no CWP-core change.

---

## 4. Security controls

| Control | Defends against | Honest assessment |
|---------|-----------------|-------------------|
| `$include_path` + independent session guard | Direct HTTP access to module | Real control (defense in depth) |
| Runtime files outside the web root | Direct file fetch | **Primary** real control, works on any web server |
| `escapeshellarg` + flag/vet denylist | rclone command injection | Real control |
| Prepared statements + identifier whitelist | SQL injection | Real control |
| CSRF on every POST | Cross-site request forgery | Real control |
| Context-aware output encoding | XSS | Real control |
| AES-256-GCM at rest | Off-box DB-dump leak | **Limited** (key is root-readable) — a checkbox |
| CLI-gated `install`/`uninstall` | Dropping tables from a web URL | Real control (runs as root) |
| `0600` keyfile, `0700` log/cache dirs | Filesystem credential exposure | Real control vs non-root access |

---

## 5. Acceptance criteria (Phase 1 done when all pass)

- [x] `install.php` runs successfully (CLI) on the live CWP server.
- [x] All 8 `rclone_*` tables created.
- [x] rclone detected (probe chain).
- [x] Encryption key created, mode 0600.
- [x] `Database` connects to `root_cwp`.
- [x] `Logger` writes to file and `rclone_logs`.
- [x] `Encryption` round-trips; `decrypt()` of bad input returns `false`.
- [x] `Rclone` executes a whitelisted command; rejects injected flags.
- [x] `Validator` + `CSRF` validate correctly.
- [x] `rcloneCWP.php` loads in CWP admin panel (positive + negative guard tests).
- [x] No CWP core file modified.
- [x] No security vulnerabilities detected in review.
- [x] PSR-12; PHPDoc on all classes/methods.
- [x] Code parses on **PHP 7.1** (the floor).

---

## 6. Deviations from the blueprint (justified)

- Credentials from a **runtime probe chain**, not the single `mysql_db.cnf`
  path (which does not exist on the live box) — enables any CWP config.
- The module entry is a **flat file** `modules/rcloneCWP.php` — the dispatcher
  includes modules as flat files (all 203 stock modules follow this; no
  subdirectory modules exist on the live server). `install.php`/
  `uninstall.php` therefore deploy off-htdocs, not into the modules dir.
- `lib/`, `sql/`, `tests` live **outside** the web root at
  `/usr/local/cwp/rcloneCWP/` — the docs' nested module tree is unsafe if
  nginx ignores `.htaccess`.
- **PHP 7.1 floor** instead of "7.2.30 exactly" — broadest CWP compatibility.
- `install.php`/`uninstall.php` are **CLI-gated** — docs imply web-accessible,
  but they run as root.
- No `composer.json` — stdlib `spl_autoload_register` (self-contained, open
  source).
- `api/`, `cli/`, `templates/`, `destinations/`, `cron/`, full UI **deferred**
  — no Phase 1 acceptance criterion needs them (KISS).
- Menu **self-heal on load** added so a CWP update clobber restores the menu
  without a manual re-install.

---

## 7. Resolved decisions

1. **Off-htdocs home** = `/usr/local/cwp/rcloneCWP/` — holds the non-web-served
   runtime files. Only the thin entry point is web-reachable. **Confirmed.**
2. **install.php / uninstall.php are CLI-gated only.** They run as root and can
   drop tables / remove keys, so they refuse web execution:
   `if (PHP_SAPI !== 'cli') { exit('Run from the shell.'); }`. **Confirmed.**
3. **Menu self-heal on load — kept.** The module re-adds its
   `3rdparty.php` menu entry on every load, so a CWP update's `unzip -o`
   clobber heals itself. **Confirmed.**
4. **Live-panel auth pattern — follow CWP's native pattern.** The CWP dispatcher
   is ionCube-encoded and unreadable, so session-key names can't be read from
   source. But the native pattern is observable from every real CWP module: the
   dispatcher `include()`s the module **after** authenticating the admin and
   sets `$include_path`. So the canonical, native guard is
   `if (!isset($include_path)) { echo 'invalid access'; exit(); }`. The module
   **follows that exact pattern** (feels CWP-native: same menu, same include,
   same admin theme), and adds `$_SESSION['lkey']` / `$_SESSION['cp']` as
   defense-in-depth. Verified when the admin loads the panel, not from encoded
   source. **Confirmed.**
5. **Install method — one-command only.** No manual file-upload path. Install
   is a `curl -sSL https://...install.sh | bash` one-liner; uninstall runs the
   installed `php uninstall.php` (or CWP's CLI command if it has one). **Confirmed.**

---

## 8. One-command installer + uninstaller

Install is a single shell command; uninstall runs from the server (no repo).

### Install

```bash
curl -sSL https://raw.githubusercontent.com/fattain_naive/rcloneCWP/main/install.sh | bash
```

`install.sh` (run as root, on a CWP server) does, in order:

1. **Preflight** — confirm CWP is present (modules dir, PHP binary), confirm root,
   confirm `curl`/`git`.
2. **Fetch** — clone the module repo (`--depth 1`). (During development,
   `RCLONECWP_LOCAL=<path> bash install.sh` installs from a local checkout.)
3. **Deploy** — copy the single web-exposed module entry to the CWP modules
   dir (`modules/rcloneCWP.php` — flat file, matching the dispatcher's
   observed behavior) and the off-htdocs runtime files to
   `/usr/local/cwp/rcloneCWP/` (`config.php`, `bootstrap.php`,
   `install.php`, `uninstall.php`, `lib/`, `sql/`).
4. **Run the PHP installer** (CLI): `/usr/local/cwp/php71/bin/php install.php`
   — creates the schema, the 0600 key file, the 0700 log/cache dirs, sets
   ownership, and adds the `3rdparty.php` menu entry.
5. **Report** — print the admin URL
   (`https://server:2030/index.php?module=rcloneCWP`) and a success summary;
   exit non-zero on failure.

### Uninstall

Uninstall does **not** need the repo — the module is already on the server.

1. **If CWP exposes a CLI command for it, use that** (e.g. a CWP `cwp-cli`
   subcommand). Prefer it when present.
2. **Otherwise, run the installed module's uninstaller directly:**

```bash
cd /usr/local/cwpsrv/htdocs/resources/admin/modules/rcloneCWP
php uninstall.php
```

`uninstall.php` (CLI-gated) drops the `rclone_*` tables (with confirmation),
removes the key file, removes the menu entry and cron entries, and asks before
deleting backups — then removes the deployed files. Safely idempotent: a second
run is a no-op.

### Notes

- The `install.sh` and `uninstall.sh` live at the **repo root**
  (`fattain_naive/rcloneCWP`), not inside the module dir.
- Idempotent: re-running install is safe; the menu entry is added exactly once
  (marker-based).
- The web-panel UI only ever *manages* the module — install is the shell
  one-liner, uninstall runs the installed `uninstall.php`.

---

## 9. Repo naming (pin at release)

The `curl | bash` command in §8 is the **target install UX** — the URL you
pasted is an example, not a live endpoint. At release time the exact public
repo path and default branch get pinned in `install.sh`/`uninstall.sh` and in
the README. Two things to settle then, not now:

- `docs/CLAUDE.md` names the repo `github.com/fattain_naime/rcloneclwp`, but the
  install command uses `fattain_naive/rcloneCWP` — pick one canonical name.
- The repo must be public and pushed with `install.sh` at the root before the
  `curl | bash` URL resolves.

Neither blocks Phase 1 development — the module is built the same way either way;
distribution just needs the real URL pinned at release.
