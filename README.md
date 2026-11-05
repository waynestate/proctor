<div align="center">
  <a href="site/index.html"><img src="docs/readme/hero.jpg" width="100%" alt="PROCTOR's front page: a green examination book sealed with PROCTOR's eye mark, beside the headline 'Watches the exam. Doesn't help.'"></a>
</div>

# PROCTOR

<p>
  <img src="docs/readme/badges/php.svg" alt="php ^8.3">
  <img src="docs/readme/badges/license.svg" alt="license MIT">
  <img src="docs/readme/badges/exit.svg" alt="exit codes: 0 pass, 1 fail, 2 tripped">
  <a href="https://github.com/waynestate/proctor/actions/workflows/ci.yml"><img src="https://github.com/waynestate/proctor/actions/workflows/ci.yml/badge.svg" alt="GitHub CI"></a>
</p>

**Policy Rules Of Code, Tested On Release.** The invigilator your composer.lock didn't know it needed: no phones,
no EOL PHP.

PROCTOR is a framework-free PHP CLI that measures a PHP application against
the WSU version policy. It reads the app's composer.json and composer.lock,
decides the support state of the app's PHP floor from a policy file, and
reports each check as pass, warning, info or fail. The checks and their rules
come from the stack's version policy decision and from the policy file
passed with `--policy`; PROCTOR itself holds no policy of its own.

> [!NOTE]
> **Not to be confused with:** code style is Pinstripe, CI is Preflight. PROCTOR grades an app against CREED's rules.

Checks: `composer ci` (the same command runs on GitLab and GitHub). Front page: [`site/index.html`](site/index.html).
On Citadel it is served by the raw-PHP deploy layer in [`deploy/`](deploy/): a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.
Documentation: [proctor.apps.wayne.edu/docs](https://proctor.apps.wayne.edu/docs/).

## Where the code lives

The canonical repository is on nougat: [enterprise-apps/proctor](https://nougat.snu.gs/enterprise-apps/proctor). git.wayne.edu and GitHub ([waynestate/proctor](https://github.com/waynestate/proctor)) are read-only mirrors of it.
Open issues and merge requests on nougat, not on the mirrors.
This moves to git.wayne.edu once it has CI runners. Your proctor keeps one answer sheet, and this is it; the mirrors hold photocopies.

## Install

```
composer require waynestate/proctor --dev
```

The command is then `vendor/bin/proctor`.

## Single-file download

The single-file PHAR is how Preflight and apps without Composer access run
PROCTOR as one file, and from the next release it is attached to each GitLab
Release with its `proctor.phar.sha256` checksum file. Both files come from the
`release-phar` job in `.gitlab-ci.yml`; the lead tags, and no tag means no
release. The `composer require` install above stays the current method; the
phar is the one-file alternative.

Download both files for the tag you want, verify the checksum, then run it.
Replace `<release>` with the base URL of the release's asset links (the
release page lists both files).

Linux / macOS (sh), one line:

    curl -fsSL -o proctor.phar "<release>/proctor.phar" -o proctor.phar.sha256 "<release>/proctor.phar.sha256" && sha256sum -c proctor.phar.sha256 && php proctor.phar --version

Windows (PowerShell), one line, no bash needed:

    Invoke-WebRequest "<release>/proctor.phar" -OutFile proctor.phar; Invoke-WebRequest "<release>/proctor.phar.sha256" -OutFile proctor.phar.sha256; if ((Get-FileHash proctor.phar -Algorithm SHA256).Hash -ne (Get-Content proctor.phar.sha256 -TotalCount 1).Trim().Split(' ')[0]) { throw "sha256 mismatch" }; php proctor.phar --version

Until the first tagged release that runs the job, build it from a checkout
with the script for your OS (both need Docker, and no PHP or Composer on the
host): `.\scripts\build-phar.ps1` on Windows, `scripts/build-phar.sh` on
Linux / macOS. Each script writes `build/proctor.phar` and verifies it by
printing PROCTOR's version.

## Usage

```
vendor/bin/proctor check [--path=.] [--format=text|json|codequality|sarif] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z]
```

- `--path`: path to the application to check (default `.`).
- `--format`: output format: `text` (default), `json`, `codequality`, or `sarif`.
- `--policy`: path to the CREED-format policy file. Optional: without it, PROCTOR resolves the policy from the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json`, then the copy bundled with PROCTOR (see [Policy](#policy)). In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--today`: override today's date (YYYY-MM-DD) so runs are deterministic. In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--php-runtime`: the PHP version that will serve the app (X.Y.Z), used by the `unpatched` check. CI should pass the deploy image's PHP, not the runner's.

## Checks

<div align="center">
  <img src="docs/readme/report.svg" width="100%" alt="Nine real lines from PROCTOR's text report, one per check shown: FAIL php-floor, WARN unpatched, FAIL unbounded-constraints, PASS lock-committed, WARN portability, WARN mysql56, WARN ci-pipelines, PASS composer-audit, FAIL overrides. Each line is written in words; color only repeats what the word says.">
</div>
<div align="center"><sub>Nine of the seventeen checks shown, asked in this order on every run; `policy-age` stays silent while the policy is fresh. The lines are real output from the test fixtures.</sub></div>

- `php-floor`: fails when the lowest PHP version allowed by `require.php` is past security support plus the grace window, when composer.json has no usable php constraint, or when there is no composer.json at all.
- `unpatched`: warns when a locked component is behind the policy's `latest_patch` for its version line, and passes when it is current. Reads laravel from composer.lock (`laravel/framework`), vue and inertia from package-lock.json (`vue`, `@inertiajs/vue3`), and the PHP runtime from `--php-runtime`. No finding when the component, its version line, or `latest_patch` is absent from the policy.
- `unbounded-constraints`: fails for every runtime constraint that is `*`, names a dev- branch, carries `@dev`, has a lower bound with no upper bound, or matches the policy's `banned_constraints`; warns on `path` and `vcs` repositories, on a `minimum-stability` below stable, and on a repository URL that is plain http. `require-dev`, `php`, `ext-*` and `lib-*` are not checked. No open-ended answers in this hall.
- `composer-config`: warns when composer.json opens a door Composer closes by default: `allow-plugins` set to `true`, `secure-http` set to `false`, `lock` set to `false`, `audit.block-insecure` set to `false`, `config.policy` set to `false`, `policy.malware` set to `false` or `policy.advisories` set to `false`; notes when `require.php` is set without `config.platform.php`. Warning and info only, never a fail, so an app that passes today keeps passing.
- `lock-committed`: fails when composer.lock is missing from the app path. When the lock is present and its `content-hash` is a real 32-hex hash, it warns (`proctor/lock-committed/out-of-sync`) when the hash no longer matches composer.json, so a stale lock that still installs is visible instead of silent; the warning never changes the exit code.
- `portability`: warns when code hard-codes a platform fact: an absolute host path, a hard-coded proxy address, a local-disk session store, or a php_value/php_flag line in .htaccess. PHP files are matched on the syntax tree (nikic/php-parser with names resolved), not with regular expressions, so a comment or string that merely mentions a banned call is not flagged and a call split over several lines, or hidden behind a `use function ... as ...` alias, is. A call through a variable (`$f()`) is reported as an info note, never as a pass. Scanned: every *.php file, every file under the top-level `config/` directory, and every `.htaccess`. Skipped at any depth: `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/`; in a Laravel app (an `artisan` file at the project root) also the top-level `bootstrap/cache/`, which holds the generated output of `php artisan optimize` (config.php, routes-v7.php, services.php, packages.php, events.php) instead of app code. A raw-PHP app that keeps its own `bootstrap/cache/` folder is scanned as before.
- `mysql56`: warns when migrations or app code use MySQL features the shared 5.6 server rejects: JSON columns (in `database/`) and JSON queries, generated columns, `renameColumn`/`renameIndex`, vector, invisible and spatial columns, `Schema::hasColumn`-style introspection, raw SQL with 5.7+ syntax, the upsert row alias, indexed strings over 191 characters, date columns defaulting to the current time, and the `db:show`/`db:monitor` commands. Citadel's shared MySQL server is 5.6, so migrations must avoid newer features. Warnings, not failures: an app on its own newer database is not affected. Fix by avoiding the feature (a raw `ALTER TABLE ... CHANGE` instead of `renameColumn`, `timestamp` instead of a date column defaulting to now, `Schema::defaultStringLength(191)` in `boot()`) or by moving the app to its own database. HTTP `->json()` calls are not flagged, and a long string only warns when the same migration indexes it.
- `destructive-deploy`: fails when a deploy script runs an artisan command that drops or rebuilds the database (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`), which erases production data on every deploy. Scans `.sh` and `.php` files under a top-level `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory, the top-level `build.sh`, `release.sh` and `.gitlab-ci.yml`, and the `scripts` section of composer.json; shell comments are ignored. Fix by running plain `php artisan migrate --force` in the deploy.
- `ci-pipelines`: for stack packages (composer.json `name` starting with `waynestate/`), fails when `.gitlab-ci.yml` or `.github/workflows/ci.yml` is missing or runs no `composer ci`; for any other project, a missing or incomplete `.gitlab-ci.yml` is a warning and `.github/workflows/ci.yml` is optional. A private stack package that sets `extra.proctor.github-mirror` to `false` in composer.json must never be on GitHub: a missing `.github/workflows/ci.yml` then gives no finding, and a present one warns (`proctor/ci-pipelines/github-private`) along with the warning-only rules. Warns when a GitHub `uses:` ref is not a 40-hex commit SHA. Warns (`proctor/ci-pipelines/composer-audit-off`) when the pipeline environment switches Composer's audit or blocking policy off: `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore`, in the GitLab top-level or job `variables:` (a scalar or a map with a `value` key) or the GitHub top-level, job or step `env:`. A warning, never a fail, for stack packages and other projects alike. The proctor fails the stack's own exam halls, and only leaves a note for everyone else's.
- `preflight-pinned`: warns, never fails, when an app includes a Preflight component at a moving ref or re-declares what an included Preflight job runs. A Preflight include is an `include:` entry whose `component:` path contains `/preflight/`; when the ref after the last `@` is not a 40-lowercase-hex commit SHA it warns (`proctor/preflight-pinned/unpinned`), and a tag, a branch, `~latest` or no `@` at all all count as moving. Pin the commit SHA of the release tag; the tag itself can ride in a trailing comment. When the app declares one of the Preflight job names (`proctor`, `php-test`, `composer-audit`, `sbom`, `pint`, `phpstan`, `eslint`, `renovate`, `package-publish`) with `script`, `before_script` or `after_script` while a Preflight component is included, it warns (`proctor/preflight-pinned/script-override`). With nothing to flag it passes: `Preflight includes are pinned`, or `no Preflight components included` when there is no pipeline, a parse error or no Preflight include. The proctor checks the boarding pass against the manifest, so the app cannot board a different flight mid-run.
- `composer-audit`: fails for advisories at or above the policy's `audit_severity_threshold`, warns for lower ones, and fails with a message that starts with `not audited:` when the composer executable is not on PATH or the app has no composer.lock (rule id `proctor/composer-audit/skipped`): install Composer, commit the lock, or record an active `composer-audit` override in policy-overrides.json with a reason and an expiry to downgrade the fail to a warning. An advisory on a package that composer.lock lists only in packages-dev is a warning at any severity (its message ends in ` [dev dependency]`), while a runtime advisory fails at or above the threshold. When the audit runs but its stdout is not parseable JSON, the check fails (rule id `proctor/composer-audit/could-not-run`) with a message that starts with `not audited:` and carries the first stderr line, because a green run that audited nothing is worse than a red one. Each finding names the package, the version locked in composer.lock, the advisory's CVE (or its advisory id when there is no CVE), its title and its severity. An advisory the app ignores in its own composer.json (`config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, or the legacy `config.audit.ignore`) is a warning, not a fail, and so is anything composer has already moved to its ignored section; abandoned packages are warnings too. A package pattern in `config.policy.advisories.ignore` may use `*`, matched the way composer does, case-insensitively. Since Composer 2.10 the audit exits 1 when anything failed it, so a non-zero exit whose JSON lists nothing PROCTOR can read (a malware or policy block, or an error) is itself a fail (`proctor/composer-audit/exit-code`), and only a clean exit with a clean result passes. A warning (`proctor/composer-audit/ignore-entries`) reports the number of ignore entries in composer.json: `config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, `config.policy.advisories.ignore-severity`, `config.policy.abandoned.ignore`, or the legacy `config.audit.ignore`, `config.audit.ignore-severity` and `config.audit.ignore-abandoned` (the two legacy fallbacks count only while their matching `config.policy` block is absent), so they move to the dated override file. An ignore entry that no active `composer-audit` override in policy-overrides.json backs is a fail of its own (`proctor/composer-audit/unbacked-ignore`): an override backs the entry when its `advisory` field equals the entry's advisory id case-insensitively, its `package` field matches the entry's package the way composer matches patterns, or it carries no scope at all, and a backing override is not reported unused. The proctor checks the invigilator's mark against its own answer sheet, and refuses to sign a green for a red pen.
- `composer-version`: fails when the Composer on PATH carries the command-injection holes CVE-2024-35241 and CVE-2024-35242 (2.2.x below 2.2.24, or any other 2.x below 2.7.7) or is Composer 1, which is end of life; warns when composer cannot be run. The invigilator checks its own pen: [GHSA-47f6-5gq3-vx9c](https://github.com/advisories/GHSA-47f6-5gq3-vx9c) and [GHSA-v9qv-c7wm-wgmf](https://github.com/advisories/GHSA-v9qv-c7wm-wgmf).
- `repo-url-credentials`: warns when a credential is written into a package source URL: a deploy token in the userinfo of a composer.json repository URL or a composer.lock source/dist URL (`https://gitlab+deploy-token-12:***@host/group/lib.git`), a bare token user of 20+ characters, a `private_token`, `access_token`, `token` or `password` query parameter, or a `.npmrc` line that sets `_authToken`, `_auth` or `_password` to a literal value (`${SOME_VAR}` is fine). The secret is masked as `***` in the message, so the report itself is safe to hand back. Warning, not failure. Fix by moving the token to `auth.json`, which is gitignored, or a CI variable, and using one registry URL without credentials. The proctor spots the answer key taped inside the desk before the student does.
- `instant-client-committed`: warns when Oracle Instant Client is committed to the repo: an `instantclient*.zip` or `instantclient*.rpm` archive, any file under a directory named `instantclient` or `instantclient_*` (an unzipped install), or a loose client library such as `libclntsh.so`, `libociei.so` or `oci.dll`. Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One warning per match: the archive, the topmost matching directory, or the loose file, each reported with its total size in MB. Warning, not failure: Citadel's images already include Instant Client, and the WSU Sail and Docker images will too. Fix by deleting it, running `git rm -r --cached` on the path PROCTOR reports, and adding that path to .gitignore. The proctor takes the 60-to-126-MB textbook off the student's desk: the exam hall already keeps a copy on the shelf.
- `wayneui-forked-views`: warns when forked copies of WayneUI are committed: any file under `resources/views/vendor/wayne-ui/` (the published 1.x views) or `public/vendor/wayne-ui/` (the published 1.x assets). Those copies silently override the package, so an app keeps old layouts after a WayneUI upgrade and nobody sees it; WayneUI's MIGRATING.md says to delete them. Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `node_modules/` and `.git/`; skipped at the top level only: `vendor/` (Composer's), so the `vendor` segment inside `resources/views/vendor/wayne-ui/` still matches. One warning per folder, with its file count. Warning, not failure: a new check must not turn a passing app into a failing one. Fix by deleting the folder and running `git rm -r --cached` on it; if you changed the forked views on purpose, compare them with the package views first. The proctor confiscates the photocopied syllabus: the current one, with the errata, is already pinned to the board.
- `wayneui-outdated`: warns when the WayneUI an app carries is behind the latest release. It reads the installed version from composer.lock (`waynestate/wayne-ui`), package-lock.json (`@waynestate/wayne-ui-css`, `@waynestate/wayne-ui-vue`) and committed copies of `wayne-ui.css` (the version in the banner of the first 400 bytes), asks composer once per run for the release list (`composer show waynestate/wayne-ui --all --format=json`), and warns, per source, when the installed version is behind the highest stable release of the same major. Pre-releases (-dev, alpha, beta, RC) and other majors are ignored. A locked WayneUI 1.x (`cit-web-infra/wayne-ui`) gets its own warning with no lookup: 1.x is Bootstrap 3 and predates the comparison. Only committed files count as copies: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk; skipped at any depth: `node_modules/` and `.git/`, and the top-level `vendor/`. When the lookup cannot run (no composer on PATH, offline, or no parsable versions) the check reports one info finding with the skipped marker and nothing else, so an offline run never breaks; an app with nothing WayneUI-related runs no process at all. By default the check warns, not fails: being out of date must not turn a passing app into a failing one. An app that wants a hard stop on its pasted stylesheets sets `wayneui.copies` to `fail` in its composer.json, under `extra.proctor` next to `github-mirror`; the default is `warn`. In `fail` mode a behind committed copy is a failure, with a sentence naming the setting appended to its message, while findings from composer.lock and package-lock.json stay warnings: only committed copies are affected by the setting, and an offline run never fails in either mode, because an unknown latest is always just the info skipped finding. Fix by updating the lock file (`composer update waynestate/wayne-ui`, or `npm update` for the npm packages) or by replacing the pasted copy from the release. The proctor marks the paper against the current syllabus, not the one the student photocopied last semester.
- `overrides`: fails for every expired entry in policy-overrides.json, and warns (`proctor/overrides/expiring`) for an active entry that expires within 14 days of `--today` (the expiry day counts), so an approval that is about to lapse is visible instead of silent. An active entry that matches no failure warns (`proctor/overrides/unused`) so a stale approval is removed instead of rotting; the warning never changes the exit code. An entry that names a check that is not registered in the run, or that names the `overrides` check itself, is a tool error (exit 2): the file is rejected before any check runs.
- `policy-age`: warns when the policy in use is more than 60 days old (its `refreshed_on` date is more than 60 days before `--today`), or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run, and the bundled policy may omit the date. It exists so a stopped CREED refresh robot is visible instead of silent.

## Exit codes

- `0`: pass (warnings allowed)
- `1`: one or more failures
- `2`: tool error (bad options, unreadable policy, a present but malformed or unreadable composer.json, or an overrides file that is malformed, unreadable, or names a check that is not registered or the overrides check itself). A missing composer.json is not a tool error: the app gets a verdict, with php-floor failing.

```mermaid
%%{init: {'theme':'base','themeVariables':{'fontFamily':'Helvetica, Arial, sans-serif','primaryColor':'#F4F1EA','primaryTextColor':'#1f2a33','primaryBorderColor':'#0C5449','lineColor':'#8A9A94'}}}%%
flowchart LR
  run["proctor check"] --> ok{"options and policy readable?"}
  ok -- no --> two["exit 2: the proctor tripped"]
  ok -- yes --> fails{"any FAIL?"}
  fails -- yes --> one["exit 1: resit"]
  fails -- no --> zero["exit 0: pass, warnings allowed"]
  classDef pass fill:#0C5449,stroke:#0C5449,color:#F4F1EA
  classDef fail fill:#9B1C2E,stroke:#9B1C2E,color:#F4F1EA
  classDef trip fill:#F4F1EA,stroke:#9B1C2E,color:#9B1C2E,stroke-dasharray:4 3
  class zero pass
  class one fail
  class two trip
```

<details>
<summary><b>Watch it mark a paper</b>: the answer sheet from the front page, handed over finished</summary>
<br>
<div align="center"><img src="docs/readme/marking.jpg" width="100%" alt="The front page's answer sheet: five steps from 'pencils up' to 'resit, pencils down', nine bubbles filled, question 3 circled in red pen with the fix in the margin, the fail struck through and a PASS stamp."></div>
</details>

## Formats

- `text`: a header line with the tool and the versions of the external tools used (composer), then one line naming the policy in use (origin, path, refresh date), then one line per finding, then one line per override, then a RESULT line.
- `json`: one machine-readable object with tool, version, path, today, result, exit, tools, policy (origin, path, refreshed_on, age_days), findings and overrides. An advisory finding carries an `advisories` array with one entry per advisory (package, id, cve, title, link, severity); other findings have no such key.
- `codequality`: GitLab's Code Quality JSON: a single array of findings, so a merge request shows them.
- `sarif`: a SARIF 2.1.0 log: one run, one rule per check, one result per non-pass finding, so GitHub code scanning and other SARIF viewers show them.

## Rule ids

Every finding carries a stable, namespaced rule id: `proctor/<check-id>` for a
check's default finding, `proctor/<check-id>/<rule>` for a named rule. The id
is the finding's identity in the machine formats: `rule_id` in `json`,
`ruleId` in `sarif`, and `check_name` in `codequality`. A rule keeps the
same id across runs and across formats even when its message changes.

The full set, grouped by check:

| Check | Rule id |
| --- | --- |
| `php-floor` | `proctor/php-floor` |
| `unpatched` | `proctor/unpatched` |
| `lock-committed` | `proctor/lock-committed` |
| `lock-committed` | `proctor/lock-committed/out-of-sync` |
| `destructive-deploy` | `proctor/destructive-deploy` |
| `overrides` | `proctor/overrides` |
| `overrides` | `proctor/overrides/expiring` |
| `overrides` | `proctor/overrides/unused` |
| `unbounded-constraints` | `proctor/unbounded-constraints` |
| `unbounded-constraints` | `proctor/unbounded-constraints/unbounded` |
| `unbounded-constraints` | `proctor/unbounded-constraints/repository` |
| `unbounded-constraints` | `proctor/unbounded-constraints/minimum-stability` |
| `unbounded-constraints` | `proctor/unbounded-constraints/insecure-url` |
| `composer-config` | `proctor/composer-config` |
| `composer-config` | `proctor/composer-config/allow-plugins-all` |
| `composer-config` | `proctor/composer-config/secure-http-off` |
| `composer-config` | `proctor/composer-config/lock-off` |
| `composer-config` | `proctor/composer-config/audit-blocking-off` |
| `composer-config` | `proctor/composer-config/policy-off` |
| `composer-config` | `proctor/composer-config/policy-malware-off` |
| `composer-config` | `proctor/composer-config/policy-advisories-off` |
| `composer-config` | `proctor/composer-config/platform-php-unset` |
| `portability` | `proctor/portability` |
| `portability` | `proctor/portability/host-path` |
| `portability` | `proctor/portability/proxy-address` |
| `portability` | `proctor/portability/local-session` |
| `portability` | `proctor/portability/htaccess-php-setting` |
| `portability` | `proctor/portability/parse-error` |
| `portability` | `proctor/portability/dynamic-call` |
| `mysql56` | `proctor/mysql56` |
| `mysql56` | `proctor/mysql56/json` |
| `mysql56` | `proctor/mysql56/generated` |
| `mysql56` | `proctor/mysql56/rename` |
| `mysql56` | `proctor/mysql56/newer-column` |
| `mysql56` | `proctor/mysql56/introspection` |
| `mysql56` | `proctor/mysql56/raw-sql` |
| `mysql56` | `proctor/mysql56/string-length` |
| `mysql56` | `proctor/mysql56/current-date` |
| `mysql56` | `proctor/mysql56/command` |
| `mysql56` | `proctor/mysql56/upsert-alias` |
| `mysql56` | `proctor/mysql56/string-default` |
| `mysql56` | `proctor/mysql56/parse-error` |
| `ci-pipelines` | `proctor/ci-pipelines` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-missing` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-parse` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-no-ci` |
| `ci-pipelines` | `proctor/ci-pipelines/github-missing` |
| `ci-pipelines` | `proctor/ci-pipelines/github-parse` |
| `ci-pipelines` | `proctor/ci-pipelines/github-no-ci` |
| `ci-pipelines` | `proctor/ci-pipelines/github-private` |
| `ci-pipelines` | `proctor/ci-pipelines/unpinned-uses` |
| `ci-pipelines` | `proctor/ci-pipelines/composer-audit-off` |
| `preflight-pinned` | `proctor/preflight-pinned` |
| `preflight-pinned` | `proctor/preflight-pinned/unpinned` |
| `preflight-pinned` | `proctor/preflight-pinned/script-override` |
| `composer-audit` | `proctor/composer-audit` |
| `composer-audit` | `proctor/composer-audit/advisory` |
| `composer-audit` | `proctor/composer-audit/abandoned` |
| `composer-audit` | `proctor/composer-audit/skipped` |
| `composer-audit` | `proctor/composer-audit/could-not-run` |
| `composer-audit` | `proctor/composer-audit/exit-code` |
| `composer-audit` | `proctor/composer-audit/ignore-entries` |
| `composer-audit` | `proctor/composer-audit/unbacked-ignore` |
| `composer-version` | `proctor/composer-version` |
| `composer-version` | `proctor/composer-version/not-found` |
| `composer-version` | `proctor/composer-version/could-not-determine` |
| `repo-url-credentials` | `proctor/repo-url-credentials` |
| `instant-client-committed` | `proctor/instant-client-committed` |
| `wayneui-forked-views` | `proctor/wayneui-forked-views` |
| `wayneui-outdated` | `proctor/wayneui-outdated` |
| `wayneui-outdated` | `proctor/wayneui-outdated/skipped` |
| `policy-age` | `proctor/policy-age` |

`tests/RuleIdContractTest.php` keeps this table in step with the checks: a rule
id that is emitted but not listed here fails the suite.

## GitHub code scanning

The `sarif` format feeds GitHub code scanning on the GitHub mirror. Upload the
log with the official action, pinned to a full commit SHA:

```yaml
name: proctor
on: [push, pull_request]
permissions:
  security-events: write
jobs:
  proctor:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer require waynestate/proctor --dev
      # policy.json is the CREED policy file
      - run: vendor/bin/proctor check --format=sarif --policy=policy.json > proctor.sarif
      - uses: github/codeql-action/upload-sarif@9b3254085494b55a0f7c4c2b3d2d52cc712ce370 # v4.39.1
        with:
          sarif_file: proctor.sarif
```

PROCTOR now files the mark sheet in the format the head office already reads.
No more translating the exam results by hand.

## Overrides

An app can carry a `policy-overrides.json` file next to its composer.json:

```json
[
    {
        "check": "lock-committed",
        "reason": "lock is generated by CI and not committed",
        "expires": "2027-01-31",
        "approved_by": "jane@example.wayne.edu"
    }
]
```

A `composer-audit` override may name the one thing it covers with two
optional fields:

```json
[
    {
        "check": "composer-audit",
        "reason": "upgrade is blocked until the next release window",
        "expires": "2027-01-31",
        "approved_by": "jane@example.wayne.edu",
        "advisory": "CVE-2024-52301",
        "package": "laravel/framework"
    }
]
```

`advisory` is an advisory id or CVE, matched case-insensitively against the
finding's advisory id or CVE. `package` is a package name, with `*` matching
any run of characters the way composer does, matched case-insensitively
against the audited package. When both are present, both must match the same
advisory. Without either field the override covers every fail of the check,
as before. The fields are only valid on `composer-audit`: on any other
check, or with a value that is not a non-empty string, PROCTOR rejects the
file (exit 2). A scoped override never covers a fail that carries no
advisory data (such as the exit-code fail), and a scoped override that
matches no failure earns the `proctor/overrides/unused` warning with its
scope in the text.

An expired override fails the `overrides` check. An active override turns the
matching fail findings of its check into warnings. An override never waives a failure whose
state is EOL plus grace (eol_grace_expired); that failure stands (the stack's version policy decision).

PROCTOR rejects the file (exit 2) when an entry names a check that is not
registered in the run, or names the `overrides` check itself: an override
cannot exempt the check that polices overrides, and an entry for a check
that does not exist would silently do nothing. The list of valid check ids
is built from the checks PROCTOR runs, so a new check needs no change to
the guard. An active override that expires within 14 days of `--today`
(the expiry day counts) gets a warning finding
(`proctor/overrides/expiring`) instead of lapsing without a trace, and an
active override whose check raised no failure gets a warning finding
(`proctor/overrides/unused`) so a stale approval is removed instead of
rotting. A warning never changes the exit code.

## Policy

`--policy` takes a CREED-format policy file: schema_version 1, component
versions with released, active_until and security_until dates, and rules
(audit_severity_threshold, banned_constraints, eol_grace_days). The
policy-file wiring to `waynestate/creed` is described below.

Without `--policy`, PROCTOR resolves the policy in this order (first match
wins):

1. the `PROCTOR_POLICY` environment variable, naming a policy file;
2. `<app>/vendor/waynestate/creed/policy/policy.json`, when it exists and is
   at least as fresh as the bundled copy (same or newer `refreshed_on`);
3. the policy bundled with PROCTOR (`src/Creed/policy.json`).

An app that installs CREED gets its policy automatically:

    composer require waynestate/creed --dev

A vendor copy that cannot be read or parsed is skipped with a note on stderr
and the run continues with the next source; a vendor copy older than the
bundled one is skipped the same way. An explicit source (`--policy` or
`PROCTOR_POLICY`) that points at a missing or invalid file is still an error
(exit 2).

In CI (any of `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` set to a value other than
`0` or `false`), `--policy` and `--today` are refused with a tool error (exit
2): in CI the policy and the date must come from the stack, not from the app
being checked, so a pipeline that could pass its own policy or date could
grade itself. The `PROCTOR_POLICY` environment variable stays allowed in CI;
it is how a trusted job names a policy. A trusted job (a protected or
group-level CI variable, never the app's own `.gitlab-ci.yml`) can allow the
options by setting `PROCTOR_ALLOW_OVERRIDES` to exactly `1`:

```yaml
# Group Settings > CI/CD > Variables: name PROCTOR_ALLOW_OVERRIDES, value 1,
# type Variable, scope Protected. Never in the app's own .gitlab-ci.yml.
PROCTOR_ALLOW_OVERRIDES: "1"
```

Every report names the policy it used: origin (`option`, `env`, `vendor` or
`bundled`), path, `refreshed_on` and the age in days. The `policy-age` check
warns when the policy is more than 60 days old, or when a non-bundled policy
has no `refreshed_on` date, so a stopped CREED refresh is visible instead of
silent.

PROCTOR carries a generated copy of the CREED reader under `src/Creed/`
(namespace `Waynestate\Proctor\Creed`), so it never depends on the CREED
package at runtime. `composer creed-copy:check` verifies the copy against the
CREED checkout named by `CREED_PATH` (default `../creed`); refresh it by
running CREED's `bin/export.php` against `src/Creed/`.

## Proof

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/proctor](https://proof.apps.wayne.edu/proctor/), behind a Wayne State employee sign-in.

## Docs

The documentation lives at [proctor.apps.wayne.edu/docs](https://proctor.apps.wayne.edu/docs/) and is built from `docs/` by Squire's docs builder:

```
composer docs
```

renders the pages into `site/docs/` and fills the generated command and environment reference from the CLI and the source. The pages are committed, so the site stays static. Check without writing:

```
composer docs:check
```

which runs as part of `composer ci`.

The docs pages wear the front page: the same masthead and main menu, ruled paper, Young Serif titles under a red pen stroke, the install slab for commands and the green footer band, in light and dark. That look lives in two files, `docs/_header.html` (the masthead) and `docs/theme/proctor.css` (the palette and type, mapped onto the kit's `--dk-*` tokens), named by the `theme` key in `docs/docs.json`. Edit those, never the vendored kit (`bin/docs-site.php`, `site/assets/docs.css`, `site/assets/docs.js`), which comes from Squire with `--sync-to`.

## Running the tests in Docker

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](MAINTAINING.md).

No PHP is needed on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
```

The same works with `php:8.3-cli` in place of `php:8.4-cli`.

Strongly recommended: a git.wayne.edu instance runner.

## License

MIT, Wayne State University, Enterprise Applications.

<details>
<summary><b>The brand board</b>: the mark, lockups, palette and type</summary>
<br>
<div align="center"><img src="docs/readme/board.jpg" width="100%" alt="PROCTOR's brand board: the eye mark on a green tile, horizontal and stacked lockups, the palette of Wayne State green, gold, cream and the red pen, and the type."></div>
</details>

<div align="center"><b>Seated in the same hall</b><br><sub>WayneUI and the stack. Each one sits its own exam.</sub></div>

<div align="center">
  <a href="https://github.com/waynestate/wayne-ui" title="WayneUI"><img src="docs/readme/cast/wayneui.png" width="40" alt="WayneUI"></a>
  <a href="https://github.com/waynestate/creed" title="CREED"><img src="docs/readme/cast/creed.png" width="40" alt="CREED"></a>
  <a href="https://github.com/waynestate/proctor" title="PROCTOR"><img src="docs/readme/cast/proctor.png" width="40" alt="PROCTOR"></a>
  <a href="https://github.com/waynestate/sesame" title="SESAME"><img src="docs/readme/cast/sesame.png" width="40" alt="SESAME"></a>
  <a href="https://github.com/waynestate/helmet" title="HELMET"><img src="docs/readme/cast/helmet.png" width="40" alt="HELMET"></a>
  <a href="https://github.com/waynestate/sourdough" title="Sourdough"><img src="docs/readme/cast/sourdough.png" width="40" alt="Sourdough"></a>
  <a href="https://github.com/waynestate/squire" title="Squire"><img src="docs/readme/cast/squire.png" width="40" alt="Squire"></a>
  <a href="https://github.com/waynestate/preflight" title="Preflight"><img src="docs/readme/cast/preflight.png" width="40" alt="Preflight"></a>
  <a href="https://github.com/waynestate/pinstripe" title="Pinstripe"><img src="docs/readme/cast/pinstripe.png" width="40" alt="Pinstripe"></a>
  <a href="https://github.com/waynestate/booster" title="Booster"><img src="docs/readme/cast/booster.png" width="40" alt="Booster"></a>
  <a href="https://github.com/waynestate/rollcall" title="Rollcall"><img src="docs/readme/cast/rollcall.png" width="40" alt="Rollcall"></a>
  <a href="https://nougat.snu.gs/enterprise-apps/drawbridge" title="Drawbridge"><img src="docs/readme/cast/drawbridge.png" width="40" alt="Drawbridge"></a>
  <a href="https://github.com/waynestate/advisor" title="ADVISOR"><img src="docs/readme/cast/advisor.png" width="40" alt="ADVISOR"></a>
  <a href="https://github.com/waynestate/weld" title="WELD"><img src="docs/readme/cast/weld.png" width="40" alt="WELD"></a>
</div>

<div align="center"><sub>Images are regenerated from the front page by <a href="scripts/readme-art/README.md"><code>scripts/readme-art</code></a>. Pencils down.</sub></div>
