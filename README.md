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

The canonical repository is [enterprise-apps/proctor](https://git.wayne.edu/enterprise-apps/proctor) on git.wayne.edu: clone it over SSH as `git@git.wayne.edu:enterprise-apps/proctor.git` or over HTTPS as `https://git.wayne.edu/enterprise-apps/proctor.git`, open issues and merge requests there, and make tags there, by the maintainer, with an approver. GitHub ([waynestate/proctor](https://github.com/waynestate/proctor)) is a read-only push mirror of it, for the published package: nobody pushes to it, and Packagist reads from it. Your proctor keeps one answer sheet, and this is it; the mirror holds the photocopy.

## Install

```
composer require waynestate/proctor --dev
```

The command is then `vendor/bin/proctor`.

## Single-file download

The single-file PHAR is how Preflight and apps without Composer access run
PROCTOR as one file. Since 0.6.0, every tag runs the `release-phar` job in
`.gitlab-ci.yml`, which attaches `proctor.phar` and its `proctor.phar.sha256`
checksum file to that tag's GitLab Release; the maintainer tags, and no tag
means no release. The `composer require` install above stays the main method;
the phar is the one-file alternative.

Download both files for the tag you want, verify the checksum, then run it.
Replace `<release>` with the base URL of the release's asset links (the
release page lists both files).

Linux / macOS (sh), one line:

    curl -fsSL -o proctor.phar "<release>/proctor.phar" -o proctor.phar.sha256 "<release>/proctor.phar.sha256" && sha256sum -c proctor.phar.sha256 && php proctor.phar --version

Windows (PowerShell), one line, no bash needed:

    Invoke-WebRequest "<release>/proctor.phar" -OutFile proctor.phar; Invoke-WebRequest "<release>/proctor.phar.sha256" -OutFile proctor.phar.sha256; if ((Get-FileHash proctor.phar -Algorithm SHA256).Hash -ne (Get-Content proctor.phar.sha256 -TotalCount 1).Trim().Split(' ')[0]) { throw "sha256 mismatch" }; php proctor.phar --version

For a commit between tags, or a release whose job did not attach the files,
build it from a checkout with the script for your OS (both need Docker, and no PHP or Composer on the
host): `.\scripts\build-phar.ps1` on Windows, `scripts/build-phar.sh` on
Linux / macOS. Each script writes `build/proctor.phar` and verifies it by
printing PROCTOR's version.

## Usage

```
vendor/bin/proctor check [--path=.] [--format=text|json|codequality|sarif] [--record=<file>] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z] [--timeout=300]
```

- `--path`: path to the application to check (default `.`).
- `--format`: output format: `text` (default), `json`, `codequality`, or `sarif`.
- `--record`: also write the JSON report (exactly what `--format=json` prints) to this file, whatever `--format` says; its folder is created. A CI job keeps it as an artifact, so one run serves the merge request and Rollcall. A file it cannot write is a tool error (exit 2).
- `--policy`: path to the CREED-format policy file. Optional: without it, PROCTOR resolves the policy from the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json`, then the copy bundled with PROCTOR (see [Policy](#policy)). In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--today`: override today's date (YYYY-MM-DD) so runs are deterministic. In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--php-runtime`: the PHP version that will serve the app (X.Y.Z), used by the `unpatched` check. CI should pass the deploy image's PHP, not the runner's.
- `--timeout`: seconds `composer audit` may run before it is killed (default 300). `proctor gate` defaults to 60.

Before a deploy, ask for a go or no-go instead (see [Deploy gate](#deploy-gate)):

```
vendor/bin/proctor gate [--path=.] [--format=text|json] [--baseline=<report or directory>] [--record=<file>] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z] [--timeout=60]
```

## Checks

<div align="center">
  <img src="docs/readme/report.svg" width="100%" alt="Nine real lines from PROCTOR's text report, one per check shown: FAIL php-floor, WARN unpatched, FAIL unbounded-constraints, PASS lock-committed, WARN portability, WARN mysql56, WARN ci-pipelines, PASS composer-audit, FAIL overrides. Each line is written in words; color only repeats what the word says.">
</div>
<div align="center"><sub>Nine of the forty-three checks shown, asked in this order on every run; `policy-age` stays silent while the policy is fresh. The lines are real output from the test fixtures.</sub></div>

- `php-floor`: fails when the lowest PHP version allowed by `require.php` is past security support plus the grace window, when composer.json has no usable php constraint, or when there is no composer.json at all.
- `unpatched`: warns when a locked component is behind the policy's `latest_patch` for its version line, and passes when it is current. Reads laravel from composer.lock (`laravel/framework`), vue and inertia from package-lock.json (`vue`, `@inertiajs/vue3`), and the PHP runtime from `--php-runtime`. No finding when the component, its version line, or `latest_patch` is absent from the policy.
- `laravel-eol`: classifies the locked `laravel/framework` version against the policy as of `--today`: active passes, naming the line's end date when it has one; security-only warns, naming the end date and the days left (`ends today`, `1 day left`, or `N days left`); eol fails, and past the security end date plus the policy's grace window it fails as `eol_grace_expired`, each naming the security end date when the line has one. It reads the version from composer.lock (`packages`, then `packages-dev`); a leading `v` is stripped, and a version that is not plain numeric (a dev alias) earns no finding. No finding when the lock has no `laravel/framework` or the version resolves to no policy line. An active override downgrades the eol fail to a warning, never the grace-expired one. The proctor keeps the hall on a textbook the publisher still corrects.
- `laravel-php`: fails when composer.json's `require.php` allows no PHP version inside the range the policy records for the locked Laravel major, or when its lowest allowed version is below the range's floor, and passes otherwise: the upper end is open by design, so `^8.2` passes a Laravel whose range is 8.2 to 8.5; the message names the app's constraint, the policy range and the Laravel major. The range's max counts as supported, so a max of `8.5` admits everything below `8.6`. No finding when the lock has no `laravel/framework`, the line has no recorded range, or composer.json declares no `require.php` (php-floor owns the missing-constraint verdict); a present `require.php` that is not a valid version constraint is a tool error. The proctor checks the desk before the student sits, because a book that will not open on the declared desk is a fail.
- `node-eol`: grades every Node version the repository pins against the policy as of `--today`: `.nvmrc`, `.node-version`, package.json `volta.node`, an official `node` image in `.gitlab-ci.yml` (`image:` or `name:`, a registry host or `$CI_DEPENDENCY_PROXY_...` prefix allowed), and an official `node` image on a Dockerfile `FROM` line. Each one gets the CREED state table: active passes, security-only warns with the days left, eol fails, and past the grace window it fails as `eol_grace_expired`, which no override softens. package.json `engines.node` is a declaration, not what runs, so its lowest allowed major is graded the same way but warns at worst (`proctor/node-eol/engines`). An odd major is never an LTS line and never a target, so it warns wherever it is named (`proctor/node-eol/odd-major`). Silent when the policy has no node component or the repository names no Node version, and for aliases (`lts/*`, `node`), tags without a version (`lts-alpine`) and another org's image named node. The proctor checks which calculator is on the desk, not only the one the syllabus allows.
- `mysql-eol`: grades every MySQL or MariaDB version the repository names against the policy as of `--today`: an official `mysql` or `mariadb` image on an `image:` line in a root `docker-compose*.yml` or `compose*.yml` (a registry host or a `$VAR` prefix allowed, a `@sha256:` digest after the tag tolerated), and `SQUIRE_MYSQL_IMAGE=mysql:<version>` in `.env.squire.example` or `.env.example`. Each one gets the CREED state table: active stays silent, security-only warns with the days left, and eol and `eol_grace_expired` warn, never fail, until Citadel's shared database server offers a supported MySQL. Silent when the policy has no mysql or mariadb component, or the repository names no database version. The proctor checks which textbook the desk is set up for, not only the one the syllabus allows.
- `mysql-minimum`: warns, never fails, when the app's MySQL or MariaDB image is below the minimum the policy records for its Laravel line (`proctor/mysql-minimum/below`).
- `unbounded-constraints`: fails for every runtime constraint that is `*`, names a dev- branch, carries `@dev`, has a lower bound with no upper bound, or matches the policy's `banned_constraints`; warns on `path` and `vcs` repositories, on a `minimum-stability` below stable, and on a repository URL that is plain http. `require-dev`, `php`, `ext-*` and `lib-*` are not checked. No open-ended answers in this hall.
- `dev-only-packages`: fails when Laravel Boost or Debugbar is in `require`, because they install in production and expose app internals; warns when Telescope is in `require` (keep it there only if the app registers it for local use) and when a test tool (phpunit, Pest, Mockery, Sail, Pail, Collision) is in `require`. It reads only `require`, never `require-dev`. It reports, and is not in the deploy gate.
- `composer-config`: warns when composer.json opens a door Composer closes by default: `allow-plugins` set to `true`, `secure-http` set to `false`, `lock` set to `false`, `audit.block-insecure` set to `false`, `config.policy` set to `false`, `policy.malware` set to `false` or `policy.advisories` set to `false`; notes when `require.php` is set without `config.platform.php`. Warning and info only, never a fail, so an app that passes today keeps passing.
- `composer-cooldown`: warns when composer.json sets no Composer release cooldown (`config.policy.cooldown.period`), so Composer installs a newly published version the moment it is released, before any bug report or rollback has had time to land (CREED practice 40); also warns when a period is set but `block` is false, which turns the cooldown off again. Warning only, never a fail, so an app that passes today keeps passing.
- `lock-committed`: fails when composer.lock is missing from the app path. When the lock is present and its `content-hash` is a real 32-hex hash, it warns (`proctor/lock-committed/out-of-sync`) when the hash no longer matches composer.json, so a stale lock that still installs is visible instead of silent; the warning never changes the exit code.
- `lock-tracked`: asks git whether it tracks composer.lock and warns (`proctor/lock-tracked`) when the lock is on disk but untracked, because CI's own install writes a lock that was never committed, so the release would install a tree nobody tested; passes when git lists the lock. It says nothing when the lock is missing (lock-committed owns that verdict), when the app path is not inside a git repository, or when git cannot be run. It starts git, so ADVISOR's in-process `proctor_check` skips it.
- `portability`: warns when code hard-codes a platform fact: an absolute host path, a hard-coded proxy address, a local-disk session store, or a php_value/php_flag line in .htaccess. PHP files are matched on the syntax tree (nikic/php-parser with names resolved), not with regular expressions, so a comment or string that merely mentions a banned call is not flagged and a call split over several lines, or hidden behind a `use function ... as ...` alias, is. A call through a variable (`$f()`) is reported as an info note, never as a pass. Scanned: every *.php file, every file under the top-level `config/` directory, and every `.htaccess`. Skipped at any depth: `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/`; in a Laravel app (an `artisan` file at the project root) also the top-level `bootstrap/cache/`, which holds the generated output of `php artisan optimize` (config.php, routes-v7.php, services.php, packages.php, events.php) instead of app code. A raw-PHP app that keeps its own `bootstrap/cache/` folder is scanned as before.
- `mysql56`: warns when migrations or app code use MySQL features the shared 5.6 server rejects: JSON columns (in `database/`) and JSON queries, generated columns, `renameColumn`/`renameIndex`, vector, invisible and spatial columns, `Schema::hasColumn`-style introspection, raw SQL with 5.7+ syntax, the upsert row alias, indexed strings over 191 characters, date columns defaulting to the current time, and the `db:show`/`db:monitor` commands. Citadel's shared MySQL server is 5.6, so migrations must avoid newer features. Warnings, not failures: an app on its own newer database is not affected. Fix by avoiding the feature (a raw `ALTER TABLE ... CHANGE` instead of `renameColumn`, `timestamp` instead of a date column defaulting to now, `Schema::defaultStringLength(191)` in `boot()`) or by moving the app to its own database. HTTP `->json()` calls are not flagged, and a long string only warns when the same migration indexes it.
- `destructive-deploy`: fails when a deploy script runs an artisan command that drops or rebuilds the database (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`), which erases production data on every deploy. Scans `.sh` and `.php` files under a top-level `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory, the top-level `build.sh`, `release.sh` and `.gitlab-ci.yml`, and the `scripts` section of composer.json; shell comments are ignored. Fix by running plain `php artisan migrate --force` in the deploy.
- `crlf-deploy-script`: fails when a deploy script has Windows line endings (CRLF): bash on Linux reads the carriage return as part of each command, so the script breaks on Citadel's Linux hosts. Scans the top-level `build.sh` and `release.sh`, and every `.sh` file under a `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory at any depth; `vendor/`, `node_modules/` and `.git/` are skipped at any depth. One finding per file, on the line of its first carriage return. Fix by converting the file to LF (`sed -i 's/\r$//' build.sh`) and adding `*.sh text eol=lf` to .gitattributes. Warns (`proctor/crlf-deploy-script/no-gitattributes`) when the app has a deploy script and `.gitattributes` has no `*.sh`, `**/*.sh` or `*` line with `eol=lf`.
- `gitattributes-eol`: warns, never fails, when the files a Linux container reads line by line are not pinned to LF (CREED every-os). `proctor/gitattributes-eol/not-pinned`: for each kind the app has (a `*.sh` file, a `*.conf` file, a `Dockerfile` or `*.Dockerfile`), every file of that kind must come out of `.gitattributes` with `eol=lf` and `text` not unset, worked out the way Git does it: every `.gitattributes` from the app root down to the file's folder, lines in order, a later line or a deeper file winning. Accepted: one line per kind (`*.sh text eol=lf`, `*.conf text eol=lf`, `Dockerfile text eol=lf`, `*.Dockerfile text eol=lf`), a broader line such as `* text=auto eol=lf` or `* text eol=lf`, `eol=lf` on its own, the old `crlf=input`, folder and `**` patterns, and a `.gitattributes` in a subfolder; a later `-text`, `binary`, `eol=crlf` or `!eol` undoes the pin. One warning, on `.gitattributes`, naming the first unpinned file of each kind and the exact lines to add. `proctor/gitattributes-eol/crlf`: a `Dockerfile`, `*.Dockerfile` or `*.conf` at any depth under the top-level `deploy/` or `.squire/` has a carriage return on disk; one warning per file, on the line of its first carriage return (shell scripts there are `crlf-deploy-script`'s). `vendor/`, `node_modules/`, `.git/` and `tests/` are skipped at any depth. Fix by adding the lines the message names at the end of `.gitattributes`, converting any CRLF file to LF, then running `git add --renormalize .` and committing. It reports, and is not in the deploy gate. The proctor collects the papers face up.
- `entrypoint-migrate`: warns, never fails, when a container entrypoint runs migrations at start: with two replicas both would run `migrate` at the same moment, and a failed migration crash-loops the container, so migrations run once per release as a job (WELD W24). Scanned: every file whose name contains `entrypoint` (case-insensitive) at any depth; `vendor/`, `node_modules/` and `.git/` are skipped at any depth, and Dockerfiles themselves are not read. A line is flagged when it runs `artisan migrate` (a bare `migrate`; `migrate:status`, `migrate:fresh` and the other subcommands are not), after removing a trailing shell comment; a line whose first non-space character is `#` is ignored, and a line inside a `migrate)` case branch, to the branch's `;;` or `esac`, is not. One warning per file, on the line of its first flagged line. Fix by moving the migration out of the entrypoint and running it once per release as a job, the way Squire's entrypoint takes a `migrate` role. It reports, and is not in the deploy gate.
- `case-clash`: fails when two paths differ only by letter case (`README.md` and `readme.md`): macOS and Windows checkouts ignore case, so one silently replaces the other, and a Linux host then loads a different file than the developer edited. Scanned: every file and directory under the app path; skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One finding per topmost clash, naming every colliding path; a clash below a clashing directory is part of it, not its own finding. Fix by keeping one name and deleting or renaming the duplicate.
- `ci-pipelines`: for stack packages (composer.json `name` starting with `waynestate/`), fails when `.gitlab-ci.yml` or `.github/workflows/ci.yml` is missing or runs no `composer ci`; for any other project, a missing or incomplete `.gitlab-ci.yml` is a warning and `.github/workflows/ci.yml` is optional. A private stack package that sets `extra.proctor.github-mirror` to `false` in composer.json must never be on GitHub: a missing `.github/workflows/ci.yml` then gives no finding, and a present one warns (`proctor/ci-pipelines/github-private`) along with the warning-only rules. Warns when a GitHub `uses:` ref is not a 40-hex commit SHA. Warns (`proctor/ci-pipelines/composer-audit-off`) when the pipeline environment switches Composer's audit or blocking policy off: `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore`, in the GitLab top-level or job `variables:` (a scalar or a map with a `value` key) or the GitHub top-level, job or step `env:`. Warns (`proctor/ci-pipelines/composer-update`) once per line when a command segment of a GitLab job script line (`script`, `before_script` or `after_script`) or a GitHub step run starts with `composer update` or `composer require`: CI must install from the lock (rule 3.7). Warns (`proctor/ci-pipelines/allow-overrides`) once per file when the pipeline environment sets `PROCTOR_ALLOW_OVERRIDES` to `1`, which lets the app pass `--policy` and `--today` in CI (BEST_PRACTICES 1.2) and grade itself against a policy or a date it picks; any other value leaves the guard on. A warning, never a fail, for stack packages and other projects alike. The proctor fails the stack's own exam halls, and only leaves a note for everyone else's.
- `preflight-pinned`: warns, never fails, when an app includes any CI component at a moving ref (a component is an `include:` entry with a `component:` value), or when it re-declares what an included Preflight job runs (a Preflight include is one whose `component:` path contains `/preflight/`). When the ref after the last `@` is neither a full release tag (`MAJOR.MINOR.PATCH` such as `0.3.0`, optional leading `v`) nor a 40-lowercase-hex commit SHA it warns (`proctor/preflight-pinned/unpinned`), and a branch such as `main`, `~latest`, a partial version such as `0.3` or `1`, or no `@` at all all count as moving. Pin a release tag such as `@0.3.0` or a commit SHA; published stack tags are never moved or deleted, so a release tag is a pin. When the app declares one of the Preflight job names (`proctor`, `php-test`, `composer-audit`, `sbom`, `pint`, `phpstan`, `eslint`, `renovate`, `package-publish`) with `script`, `before_script` or `after_script` while a Preflight component is included, it warns (`proctor/preflight-pinned/script-override`). With nothing to flag it passes: `CI components are pinned`, or `no CI components included` when there is no pipeline, a parse error or no component include. The proctor checks the boarding pass against the manifest, so the app cannot board a different flight mid-run.
- `preflight-gates`: warns, never fails, when `.gitlab-ci.yml` skips or weakens Preflight (Preflight practice 3.3). No include whose `component:` path contains `/preflight/` warns (`proctor/preflight-gates/no-include`). A gate component (every Preflight component but `renovate` and `package-publish`) included with the `allow_failure` input set to `true`, or a job the app re-declares under the gate's job name (its `job_name` input, or the component name) with `allow_failure` set to anything but `false`, warns once per place it is set (`proctor/preflight-gates/allow-failure`). No Preflight `proctor` include and no job whose `script` runs `proctor check` or `proctor gate` (`vendor/bin/proctor`, `proctor.phar` or a path to either) warns (`proctor/preflight-gates/no-proctor-job`). No finding when there is no `.gitlab-ci.yml` or it does not parse (`ci-pipelines` reports both); with nothing to flag it passes: `Preflight gates are included, none may fail softly, and PROCTOR runs`. It reports, and is not in the deploy gate. The proctor notices when the exam hall has no proctor, and when the doors were left open for anyone who fails.
- `composer-audit`: fails for advisories at or above the policy's `audit_severity_threshold`, warns for lower ones, and fails with a message that starts with `not audited:` when the composer executable is not on PATH or the app has no composer.lock (rule id `proctor/composer-audit/skipped`): install Composer, commit the lock, or record an active `composer-audit` override in policy-overrides.json with a reason and an expiry to downgrade the fail to a warning. An advisory on a package that composer.lock lists only in packages-dev is a warning at any severity (its message ends in ` [dev dependency]`), while a runtime advisory fails at or above the threshold. When the audit runs but its stdout is not parseable JSON, the check fails (rule id `proctor/composer-audit/could-not-run`) with a message that starts with `not audited:` and carries the first stderr line, because a green run that audited nothing is worse than a red one. Each finding names the package, the version locked in composer.lock, the advisory's CVE (or its advisory id when there is no CVE), its title and its severity. An advisory the app ignores in its own composer.json (`config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, or the legacy `config.audit.ignore`) is a warning, not a fail, and so is anything composer has already moved to its ignored section. Abandoned packages follow the policy's `abandoned_packages` rule, passed to the audit as `--abandoned` (rule id `proctor/composer-audit/abandoned`): `fail` (the bundled policy, and the default) fails each one, `report` warns, `ignore` stays quiet; the message names the suggested replacement or says there is none, and a package the app ignores in `config.policy.abandoned.ignore` (or the legacy `config.audit.ignore-abandoned`) is a warning instead. A package pattern in `config.policy.advisories.ignore` may use `*`, matched the way composer does, case-insensitively. Since Composer 2.10 the audit exits 1 when anything failed it, so a non-zero exit whose JSON lists nothing PROCTOR can read (a malware or policy block, or an error) is itself a fail (`proctor/composer-audit/exit-code`), and only a clean exit with a clean result passes. A warning (`proctor/composer-audit/ignore-entries`) reports the number of ignore entries in composer.json: `config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, `config.policy.advisories.ignore-severity`, `config.policy.abandoned.ignore`, or the legacy `config.audit.ignore`, `config.audit.ignore-severity` and `config.audit.ignore-abandoned` (the two legacy fallbacks count only while their matching `config.policy` block is absent), so they move to the dated override file. An ignore entry that no active `composer-audit` override in policy-overrides.json backs is a fail of its own (`proctor/composer-audit/unbacked-ignore`): an override backs the entry when its `advisory` field equals the entry's advisory id case-insensitively, its `package` field matches the entry's package the way composer matches patterns, or it carries no scope at all, and a backing override is not reported unused. The proctor checks the invigilator's mark against its own answer sheet, and refuses to sign a green for a red pen.
- `composer-version`: fails when the Composer on PATH carries the command-injection holes CVE-2024-35241 and CVE-2024-35242 (2.2.x below 2.2.24, or any other 2.x below 2.7.7) or is Composer 1, which is end of life; when a policy sets `tools.composer.minimum` above the CVE floor for the version's line, it fails below that too, naming the policy in the message (the bundled policy sets 2.8.0); warns when composer cannot be run. The invigilator checks its own pen: [GHSA-47f6-5gq3-vx9c](https://github.com/advisories/GHSA-47f6-5gq3-vx9c) and [GHSA-v9qv-c7wm-wgmf](https://github.com/advisories/GHSA-v9qv-c7wm-wgmf).
- `repo-url-credentials`: warns when a credential is written into a package source URL: a deploy token in the userinfo of a composer.json repository URL or a composer.lock source/dist URL (`https://gitlab+deploy-token-12:***@host/group/lib.git`), a bare token user of 20+ characters, a `private_token`, `access_token`, `token` or `password` query parameter, or a `.npmrc` line that sets `_authToken`, `_auth` or `_password` to a literal value (`${SOME_VAR}` is fine). The secret is masked as `***` in the message, so the report itself is safe to hand back. Warning, not failure. Fix by moving the token to `auth.json`, which is gitignored, or a CI variable, and using one registry URL without credentials. The proctor spots the answer key taped inside the desk before the student does.
- `instant-client-committed`: warns when Oracle Instant Client is committed to the repo: an `instantclient*.zip` or `instantclient*.rpm` archive, any file under a directory named `instantclient` or `instantclient_*` (an unzipped install), or a loose client library such as `libclntsh.so`, `libociei.so` or `oci.dll`. Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One warning per match: the archive, the topmost matching directory, or the loose file, each reported with its total size in MB. Warning, not failure: Citadel's images already include Instant Client, and the WSU Sail and Docker images will too. Fix by deleting it, running `git rm -r --cached` on the path PROCTOR reports, and adding that path to .gitignore. The proctor takes the 60-to-126-MB textbook off the student's desk: the exam hall already keeps a copy on the shelf.
- `env-outside-config`: warns, never fails, for every `env()` call in a Laravel app (an `artisan` file at the root, or `laravel/framework` in composer.json `require`) outside its config files: after `php artisan config:cache` Laravel no longer loads .env, so the call returns null in production while it works on a laptop. Scanned: every `*.php` file except the top-level `config/` and `bootstrap/cache/`, and `tests/`, `vendor/`, `node_modules/`, `storage/` and `.git/` at any depth. PHP is read on the syntax tree, so a comment, a string, a method named `env` and a function imported from another namespace are not flagged; a Blade line is matched only with a quoted first argument (`{{ env('APP_NAME') }}`), and Blade's `@env('local')` directive is not a call. Larastan enforces the same rule, so a call that carries its ignore marker for it (`@phpstan-ignore larastan.noEnvCallsOutsideOfConfig` on the line or on a comment line right above) is an info note instead (`proctor/env-outside-config/suppressed`), visible but not counted; a closure in `bootstrap/app.php` that runs before config is loaded is the usual reason. Fix by reading the variable in a config file and calling `config()` where it is used. The proctor reads the answer sheet, not the notes in the margin.
- `raw-identity-header`: warns, never fails, when app code reads an identity header directly instead of taking the user from SESAME. Drawbridge, the gateway, signs the session into the `X-Sesame-Assertion` header and passes the user's claims as request headers; only SESAME's gateway driver may open that pair, because it verifies the signature before a claim becomes the logged-in user (CREED auth-3), and app code that reads a claim header itself accepts whatever value the proxy, or a client, put there. Scanned: every `*.php` file except `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/` at any depth. PHP is read on the syntax tree with names resolved, so a header name in a comment or a log string, a variable named `$REMOTE_USER`, and `getenv()` with a computed argument are not flagged. Flagged read positions: a string key on `$_SERVER` or `$_ENV`, the quoted argument of `getenv()` or `apache_getenv()`, the quoted first argument of a method named `header`, `server`, `hasHeader`, `getHeader` or `getHeaderLine` (and of `get()` on a `->headers` object), and a string key on the result of `getallheaders()` or `apache_request_headers()`, directly or through a variable assigned from them. Names match case-insensitively with `-` and `_` interchangeable, and where the read comes from `$_SERVER` or the environment the `HTTP_` prefix Apache adds to request headers is accepted too. `X-Sesame-Assertion` is the signed assertion itself, so it is flagged like any other direct read; `PHP_AUTH_USER` is basic-auth material, so it is not an identity claim and is not flagged. Blade templates (`*.blade.php`) are matched line by line for `$_SERVER['...']` and `request()->header('...')` / `request()->server('...')`. A file that does not parse is skipped here; the portability check reports it. Fix by taking the user from SESAME (the gateway driver) and reading the claims it returns. The proctor checks the ID at the door; it does not trust the name written on the exam paper.
- `app-key`: warns, never fails, when the repository gives away the APP_KEY or makes a leaked one worse. `proctor/app-key/committed-env`: a real env file is committed (`.env`, `.env.production`, any `.env.*` that is not an example or a test file). `proctor/app-key/committed-key`: a committed env file, examples included, sets `APP_KEY` or `APP_PREVIOUS_KEYS` to a real key (`base64:` and 16 or 32 bytes), or `config/app.php` carries one as a literal; the key is never printed. `proctor/app-key/cookie-sessions`: `SESSION_DRIVER=cookie` in a committed env file, or a `cookie` default in `config/session.php`. A real env file is graded only when PROCTOR knows it is committed (git lists it, or `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` is set), so a developer's own `.env` never raises a flag; example files (`.example`, `.dist`, `.sample`, `.template`) are always graded, and test files (`.env.testing`, `.env.test`, `.env.ci`, `.env.dusk.*`) are skipped. Fix by removing the file or value, rotating the key with `php artisan key:generate` on every environment that used it, and moving sessions to the database or redis driver. The proctor does not read the answer key aloud; it only notes that it was left on the desk.
- `app-debug`: warns, never fails, when a committed env, deploy or CI file sets `APP_DEBUG` to `true` or `1` (case-insensitive, optional spaces around `=` or `:`): `.env.production`, `.env.squire.example`, any file under `deploy/` or `deployments/`, and the CI pipelines (`.gitlab-ci.yml` and `.github/workflows/*.yml`). One warning per file, on the line of its first true value; it never reads `.env` (a committed `.env` is the app-key check's finding) or `.env.example`, the local-development template where debug is expected to be on. Fix by setting `APP_DEBUG=false`, which Laravel reads in config/app.php. It reports, and is not in the deploy gate. The proctor keeps the answer sheet from showing its workings to the crowd.
- `display-errors`: warns, never fails, when a committed php.ini, .user.ini or .htaccess turns `display_errors` on (`proctor/display-errors/on`) or `log_errors` off (`proctor/display-errors/log-off`): any `*.ini` at the app root or at any depth under `public/`, `deploy/`, `deployments/` or `docker/`, and every `.htaccess` at the root or at any depth under those folders. A line whose first non-space character is `;` or `#` is ignored, and a file whose name contains `dev`, `local` or `development` (such as `php.dev.ini`) is the local-development template and is skipped, along with `vendor/`, `node_modules/`, `.git/` and anything under `tests/`. One warning per file per rule, on the line of its first match. Fix by keeping `display_errors` Off and `log_errors` On (CREED runtime-and-headers). It reports, and is not in the deploy gate. The proctor keeps the stack traces out of the hall.
- `directory-listing`: warns, never fails, when a committed .htaccess or server config turns on directory listing: an Apache `Options` directive (case-insensitive, leading spaces allowed) whose arguments carry `+Indexes`, a bare `Indexes` or `All`, unless the same line also has `-Indexes` after it, or an nginx `autoindex on;` (case-insensitive, spaces allowed before the `;`) (CREED production). Scanned: every `.htaccess` at the app root or at any depth under `public/`, `deploy/`, `deployments/` or `docker/`, and every `*.conf` at any depth under `deploy/`, `deployments/` or `docker/`. A line whose first non-space character is `#` is ignored, and `vendor/`, `node_modules/`, `.git/` and anything under `tests/` are skipped at any depth. One warning per file, on the line of its first match. Fix by keeping `Indexes` off (`Options -Indexes`) and `autoindex off;`. It reports, and is not in the deploy gate. The proctor keeps the shelves out of the crowd's sight.
- `csp-unsafe-inline`: warns, never fails, when committed app config, server config or an example env file lets scripts run inline: a `script-src`, `script-src-elem` or `script-src-attr` directive (case-insensitive) whose value list carries `'unsafe-inline'` (with the single quotes, case-insensitive) (CREED runtime-and-headers). Scanned: every `*.php` directly in `config/` and `bootstrap/`, `public/index.php` and every `*.php` at any depth under `app/`; every `.htaccess` at the app root or at any depth under `public/`, `deploy/`, `deployments/` or `docker/`, and every `*.conf` at any depth under `deploy/`, `deployments/` or `docker/`; and the `HELMET_CSP=` lines of `.env.example`, `.env.production` and `.env.squire.example`. In a header string (server config, an env line, or a PHP string such as `"script-src 'self' 'unsafe-inline'; img-src *"`) the value list runs from the directive name to the next `;`, the end of the quoted string or the end of the line; in a PHP array (`'script-src' => ["'self'", "'unsafe-inline'"]`) it runs from the `=>` to the first `]` after it, which may be on a later line. `style-src`, `style-src-elem` and `style-src-attr` never warn: HELMET's own default keeps `style-src-attr 'unsafe-inline'`. A nonce or `'strict-dynamic'` beside it still warns, and so does a report-only policy. A line whose first non-space characters are `//`, `#` or `*` is ignored, and `vendor/`, `node_modules/`, `.git/` and anything under `tests/` are skipped at any depth. One warning per file, on the line of its first offending `'unsafe-inline'`. Fix by removing `'unsafe-inline'` from the script source and letting HELMET's CSP nonce carry the inline scripts. It reports, and is not in the deploy gate. The proctor reads only the notes the hall hands out, never ones slipped in through the window.
- `robots-txt`: warns, never fails, when an app served from a committed `public/index.php` commits no `public/robots.txt` (`proctor/robots-txt/missing`), or one that lets every crawler in by default (`proctor/robots-txt/allows-all`): no group for `User-agent: *` holds `Disallow: /` or `Allow: /` (CREED production). `Disallow: /` keeps every crawler out, and `Allow: /` is a written choice to be crawled; either one passes (`proctor/robots-txt`), and a group with both passes too. Laravel's skeleton ships `User-agent: *` with an empty `Disallow:`, which lets everyone in without anyone deciding so, and `Disallow: /admin` or `Allow: /public` covers one corner, not the hall. Field names are case-insensitive, `#` comments and blank lines are ignored, and a group is the run of rules after one or more `User-agent` lines. Only committed files count. No finding at all when the app has no committed `public/index.php`. Fix by committing `public/robots.txt` with `User-agent: *` and `Disallow: /`, or `Allow: /` when crawling is wanted (a public front page). It reports, and is not in the deploy gate. The proctor hangs a sign on the exam-room door: spectators welcome, or not, but say which.
- `vite-secrets`: warns, never fails, when a secret-looking variable carries Vite's `VITE_` prefix: Vite exposes every `VITE_` variable to the browser bundle, so its value reaches every visitor. `proctor/vite-secrets/env-file`: a committed env file declares one (`VITE_API_KEY=...`); example files (`.env.example`, `.env.squire.example`, `.dist`, `.sample`, `.template`) are always read, any other `.env*` file only when PROCTOR knows it is committed (git lists it, or `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` is set). `proctor/vite-secrets/source`: front-end code under the top-level `resources/js/` or `src/` (`.js`, `.ts`, `.jsx`, `.tsx`, `.vue`, `.svelte` and the `m`/`c` variants) reads one as `import.meta.env.VITE_...` or `import.meta.env['VITE_...']`; comment lines are skipped. Secret-looking: a word of the name is `SECRET`, `TOKEN`, `PASSWORD`, `PASSWD`, `PASSPHRASE`, `CREDENTIAL` (or a plural) or `APIKEY`, or starts with `PRIVATE`; `KEY` right after `API`, `ACCESS`, `SIGNING` or `ENCRYPTION`; or the name is `VITE_APP_KEY`. Pusher and Reverb app keys (`VITE_PUSHER_APP_KEY`, `VITE_REVERB_APP_KEY`) are public by design and pass. Only the name is matched: the value is never parsed or printed, and the process environment is never read. One warning per line; with nothing to flag it passes when the app uses Vite (a root `vite.config.*`, or `vite` in package.json), and is silent otherwise. Fix by keeping the secret on the PHP side under a name without the prefix and calling a route that uses it. It reports, and is not in the deploy gate. The proctor reads the names on the envelopes, never the letters inside.
- `served-folder`: warns, never fails, when a committed file under the top-level `public/` can be downloaded by a stranger: a PHP file that calls `phpinfo()` (`proctor/served-folder/phpinfo`), or a database dump (`*.sql`, `*.sql.gz`, `*.dump`), a backup or editor copy (`*.bak`, `*.old`, `*.orig`, `*.swp`, a name ending in `~`), a log, an env file or a database admin tool (`adminer*.php`) (`proctor/served-folder/debug-file`). No finding when the app has no `public/` folder. Fix by deleting the file or moving it out of `public/`. The proctor clears the scratch paper off the desk before the hall opens.
- `world-writable`: warns, never fails, when the repository sets mode 777: `chmod` with `777`, `0777`, `a+rwx` or `ugo+rwx` in a shell script, a Dockerfile, a Makefile, `.gitlab-ci.yml` or the `scripts` of composer.json and package.json, or a PHP `chmod()` with the literal `0777`. Comment lines, the sticky `1777` and `mkdir()` (the umask still applies to it) are not flagged; `tests/` is skipped. Fix by giving the web server user write access to the folders it needs (`storage/` and `bootstrap/cache/` in Laravel) and nothing more. The proctor does not prop the exam-room door open for anyone who wanders by.
- `wayneui-forked-views`: warns when forked copies of WayneUI are committed: any file under `resources/views/vendor/wayne-ui/` (the published 1.x views) or `public/vendor/wayne-ui/` (the published 1.x assets). Those copies silently override the package, so an app keeps old layouts after a WayneUI upgrade and nobody sees it; WayneUI's MIGRATING.md says to delete them. Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `node_modules/` and `.git/`; skipped at the top level only: `vendor/` (Composer's), so the `vendor` segment inside `resources/views/vendor/wayne-ui/` still matches. One warning per folder, with its file count. Warning, not failure: a new check must not turn a passing app into a failing one. Fix by deleting the folder and running `git rm -r --cached` on it; if you changed the forked views on purpose, compare them with the package views first. The proctor confiscates the photocopied syllabus: the current one, with the errata, is already pinned to the board.
- `wayneui-outdated`: warns when the WayneUI an app carries is behind the latest release. It reads the installed version from composer.lock (`waynestate/wayne-ui`), package-lock.json (`@waynestate/wayne-ui-css`, `@waynestate/wayne-ui-vue`) and committed copies of `wayne-ui.css` (the version in the banner of the first 400 bytes), asks composer once per run for the release list (`composer show waynestate/wayne-ui --all --format=json`), and warns, per source, when the installed version is behind the highest stable release of the same major. Pre-releases (-dev, alpha, beta, RC) and other majors are ignored. A locked WayneUI 1.x (`cit-web-infra/wayne-ui`) gets its own warning with no lookup: 1.x is Bootstrap 3 and predates the comparison. Only committed files count as copies: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk; skipped at any depth: `node_modules/` and `.git/`, and the top-level `vendor/`. When the lookup cannot run (no composer on PATH, offline, or no parsable versions) the check reports one info finding with the skipped marker and nothing else, so an offline run never breaks; an app with nothing WayneUI-related runs no process at all. By default the check warns, not fails: being out of date must not turn a passing app into a failing one. An app that wants a hard stop on its pasted stylesheets sets `wayneui.copies` to `fail` in its composer.json, under `extra.proctor` next to `github-mirror`; the default is `warn`. In `fail` mode a behind committed copy is a failure, with a sentence naming the setting appended to its message, while findings from composer.lock and package-lock.json stay warnings: only committed copies are affected by the setting, and an offline run never fails in either mode, because an unknown latest is always just the info skipped finding. Fix by updating the lock file (`composer update waynestate/wayne-ui`, or `npm update` for the npm packages) or by replacing the pasted copy from the release. The proctor marks the paper against the current syllabus, not the one the student photocopied last semester.
- `overrides`: fails for every expired entry in policy-overrides.json, and for every entry that runs past the policy's maximum duration (`rules.override_max_days`, 90 days when the key is absent): such an entry fails with `proctor/overrides/too-long` and is not applied, because an approval is a dated exception and CREED allows no permanent override. It warns (`proctor/overrides/expiring`) for an active entry that expires within 14 days of `--today` (the expiry day counts), so an approval that is about to lapse is visible instead of silent. An active entry that matches no failure warns (`proctor/overrides/unused`) so a stale approval is removed instead of rotting; the warning never changes the exit code. An entry that names a check that is not registered in the run, or that names the `overrides` check itself, is a tool error (exit 2): the file is rejected before any check runs. A policy whose `override_max_days` is not a positive integer is a tool error (exit 2) naming the key.
- `override-approver`: warns when an entry in policy-overrides.json is approved by someone who wrote it. git blame (read-only, whitespace ignored, moved lines followed) names who last wrote each line of the entry, and `approved_by` must not name one of them: the git author name, the author email, its local part, or the username in a GitLab or GitHub noreply address, compared trimmed and case-insensitively, with a leading `@` and `Name <email>` understood. A line holding only `approved_by` is the approver's signature and does not count, so a reviewer may sign someone else's entry but not approve, or extend, one they wrote (`proctor/override-approver/self-approved`). Uncommitted lines count as the next commit's author's, so a local run warns before the commit. It never passes on a guess: with no git, no git checkout, or a line that a shallow clone credits to its oldest commit, it reports `proctor/override-approver/could-not-run` with a message that starts `not checked:` (set `GIT_DEPTH: 0` on the job). CREED's `rules.override_self_approval` makes both findings `warn` (the default, also when the key is absent) or `fail`. An override can neither target this check nor waive its failure. `proctor check` only: the deploy gate does not run it. A note from home does not count when the candidate signed it.
- `policy-age`: warns when the policy in use is more than 60 days old (its `refreshed_on` date is more than 60 days before `--today`), or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run, and the bundled policy may omit the date. It exists so a stopped CREED refresh robot is visible instead of silent.

## Exit codes

- `0`: pass (warnings allowed)
- `1`: one or more failures
- `2`: tool error (bad options, unreadable policy, a present but malformed or unreadable composer.json, or an overrides file that is malformed, unreadable, or names a check that is not registered, the overrides check or the override-approver check, or a `--record` file it could not write). A missing composer.json is not a tool error: the app gets a verdict, with php-floor failing.

On a tool error every format still writes its report to stdout, next to the message on stderr: `text` prints the message as a FAIL line, `json` writes the report shape with `result` set to `error`, an `error` field carrying the message and empty findings, `codequality` lists the error as one blocker issue, and `sarif` writes a log whose single run reports an unsuccessful invocation.

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

## Deploy gate

`proctor gate` is the proctor at the exam-hall door: it does not mark the whole paper, it decides whether you may sit. A release script or a platform hook calls it before a deploy, and it follows CREED's deploy-gate chapter: a small, fast rule set that ratchets, so a deploy that does not make things worse always passes.

| Gate check | Why it is at the door |
| --- | --- |
| `php-floor` | the PHP the app declares is still supported |
| `unpatched` | the runtime and framework are on their latest patch (warns only) |
| `laravel-eol` | the locked Laravel line is still supported |
| `laravel-php` | the declared PHP fits the locked Laravel line |
| `lock-committed` | the lock file is the release |
| `composer-audit` | no known advisory at or above the threshold |
| `overrides` | every override is still in date |
| `policy-age` | the policy itself is fresh (warns only) |

`override-approver` stays out of the gate on purpose: it reads git history, which a release export may not carry, and whether an override was approved by someone else is settled once, in CI, on the merge request that added it.

Overrides are applied first, as in `proctor check`. Each remaining failure is then blocked (no baseline, so a first deploy; a regression, because the baseline passed that rule; or an expired override, for any check), held (the baseline failed the same rule id too, so owners of an EOL app can still ship the fix) or waived (composer-audit had no advisory data, which only warns at deploy time). Any block is a no-go.

- `--baseline`: any PROCTOR JSON with its findings (the verdict `gate --record` wrote last time, or a `proctor check` report from `--format=json` or `check --record`) or the running deployment's source directory. Without it, every failure blocks.
- `--record`: also write the JSON verdict to a file, whatever `--format` prints; pass it as `--baseline` next time. This is the gate's verdict (`command: gate`, `verdict`, `summary`, and each finding's `gate` outcome), not the check report that `proctor check --record` writes, and it is not the `proctor.json` Rollcall reads.
- Exit codes: `0` go, `1` no-go, `2` the gate could not decide. A platform hook fails open on 2, with an alert, so a gate bug never takes down deploys.
- Staged rules: when CREED's policy stages a check as `report` or `warn`, a failure that would block is logged as `STAGE` and the deploy goes ahead (ADR-0006 item 4). The policy ships with no stages, so every check blocks today.

The whole story, with the verdict's JSON shape: [Deploy gate](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html).

## Which command when

<!-- which-command-when: canonical copy in WELD docs/reference/which-command.md; edit there first, then copy word for word -->
Several stack commands sound alike (`weld:doctor`, `squire doctor`, `proctor check`) and do different jobs. Find your question in the second column and run the command beside it. The doctors look at setup, the proctor grades against policy, and the gate decides deploys: same waiting room, different appointments.

| Command | What it checks | When to run it | Where it runs |
|---|---|---|---|
| `composer ci` | **Will my pipeline pass?** The repo's own CI contract, the same command the pipeline runs: validation, the tests, Pint, PHPStan, `composer audit` and whatever checks the repo adds. | Before every push. | App repo, CI |
| `bin/weld.sh doctor` or `.\bin\weld.ps1 doctor` (both run `php artisan weld:doctor`) | **Does my WELD app hold together?** The app key, writable `storage` and `bootstrap/cache`, the database connection and pending migrations. One PASS, WARN or FAIL line each; exit 1 on a FAIL. Standards are PROCTOR's. | After `up`, when the app will not start, and after every deploy off Citadel. | App repo (in the app container), deploy host (in the app container) |
| `vendor/bin/squire doctor` | **Is my Citadel release setup right?** `build.sh`, the release scripts, the health check, script hygiene, line endings, secrets kept out of git and the local parity setup, against Squire's rules. Exit 1 on a failed rule. The lock file, audit and MySQL 5.6 checks are handed to PROCTOR. | After `squire init` or `squire update`, and before the first deploy. | App repo, CI |
| `vendor/bin/squire init --check` | **Have the files Squire wrote drifted from its templates?** Missing, outdated or edited copies. Writes nothing; exit 1 on drift. | After you upgrade Squire (then run `squire update`), and in CI. | App repo, CI |
| `vendor/bin/squire doctor oracle`, or `php artisan oracle:check` in WELD and Sourdough's Laravel apps | **Can my app reach Oracle?** The `ORACLE_*` settings, the host, the port, the `oci8` driver and the login, in plain language, printing setting names and never values. Silent when the app has no Oracle settings. | After you fill in the `ORACLE_*` settings, or when a connection fails. Squire's `release.sh` runs the same check on every release, as a report. | App repo, deploy host |
| `vendor/bin/proctor check` (`composer proctor` in WELD and Sourdough's apps) | **Does my app meet CREED's policy?** The PHP floor, Laravel, Node and MySQL end of life, the committed lock, `composer audit`, open-ended constraints, portability, MySQL 5.6 safety, overrides and the rest of its checks. Exit 1 on a FAIL. | Before you merge; the pipeline runs it too. | App repo, CI |
| `vendor/bin/proctor gate` | **May this commit deploy?** The deploy-time subset of `proctor check`, ratcheted against the last release's verdict, so a deploy that makes nothing worse goes. Exit 1 is a no-go. | At deploy time. Squire's `release.sh` calls it for you. | Deploy host |
| `sourdough manifest --check` | **Which managed files did I change since Sourdough baked my app?** Managed files that changed or are missing against `.sourdough/manifest.json`; seeded files are yours and never reported. Exit 1 on a change. | Before `sourdough feed`. | App repo, CI |
| `sourdough feed --dry-run` | **Is my app behind its Sourdough template?** The plan a feed would apply, with nothing written. Exit 1 when feeding would change files. | After you install a new Sourdough, and in CI to catch an app falling behind. | App repo, CI |
| `booster plan` | **What does my upgrade take?** The ordered PHP, Laravel and front-end hops, the dependencies that block them, and advisory PHPCompatibility and PHPStan scans. Changes nothing. | Before an upgrade, and when PROCTOR says your PHP or Laravel is past support. | App repo |
| `booster replay check` | **Does my app still answer the same after a change?** Replays the requests in `booster-replay.json` and compares the answers with the ones `booster replay record` saved. Exit 1 on a changed answer. | After an upgrade hop or a hand fix, once you have a recording. | App repo (in Docker) |
| `booster init --check` | **Are Booster's coding-tab playbooks current?** The playbooks in `.agents/skills`. Changes nothing; exit 1 when one is missing or out of date. | After you upgrade Booster, and in CI. | App repo, CI |
| `php bin/regen-check.php --generated <dir>` | **Is WELD still Sourdough's Inertia shape?** WELD, file by file, against a fresh `sourdough new --inertia`; fails only on drift that `.sourdough/regen-baseline.json` does not already accept. | When Sourdough's Inertia shape or WELD changes. | WELD repo, CI |
| `rollcall report` | **Where does the whole fleet stand?** The latest scan's totals against CREED's policy: PHP, Laravel, the auth package, PROCTOR results and the end-of-life burn-down. Always exit 0; it never gates. | After `rollcall scan`, on the scan's schedule. | CI (a scheduled scan job) |
<!-- /which-command-when -->

The canonical copy of this table is in WELD's docs: [Which command when](https://weld.apps.wayne.edu/docs/reference/which-command.html).

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
| `laravel-eol` | `proctor/laravel-eol` |
| `laravel-php` | `proctor/laravel-php` |
| `node-eol` | `proctor/node-eol` |
| `node-eol` | `proctor/node-eol/engines` |
| `node-eol` | `proctor/node-eol/odd-major` |
| `lock-committed` | `proctor/lock-committed` |
| `lock-committed` | `proctor/lock-committed/out-of-sync` |
| `lock-tracked` | `proctor/lock-tracked` |
| `destructive-deploy` | `proctor/destructive-deploy` |
| `crlf-deploy-script` | `proctor/crlf-deploy-script` |
| `crlf-deploy-script` | `proctor/crlf-deploy-script/no-gitattributes` |
| `gitattributes-eol` | `proctor/gitattributes-eol` |
| `gitattributes-eol` | `proctor/gitattributes-eol/not-pinned` |
| `gitattributes-eol` | `proctor/gitattributes-eol/crlf` |
| `entrypoint-migrate` | `proctor/entrypoint-migrate` |
| `entrypoint-migrate` | `proctor/entrypoint-migrate/runs` |
| `case-clash` | `proctor/case-clash` |
| `overrides` | `proctor/overrides` |
| `overrides` | `proctor/overrides/expiring` |
| `overrides` | `proctor/overrides/too-long` |
| `overrides` | `proctor/overrides/unused` |
| `override-approver` | `proctor/override-approver` |
| `override-approver` | `proctor/override-approver/self-approved` |
| `override-approver` | `proctor/override-approver/could-not-run` |
| `unbounded-constraints` | `proctor/unbounded-constraints` |
| `unbounded-constraints` | `proctor/unbounded-constraints/unbounded` |
| `unbounded-constraints` | `proctor/unbounded-constraints/repository` |
| `unbounded-constraints` | `proctor/unbounded-constraints/minimum-stability` |
| `unbounded-constraints` | `proctor/unbounded-constraints/insecure-url` |
| `dev-only-packages` | `proctor/dev-only-packages` |
| `dev-only-packages` | `proctor/dev-only-packages/exposes-internals` |
| `dev-only-packages` | `proctor/dev-only-packages/test-tool` |
| `composer-config` | `proctor/composer-config` |
| `composer-config` | `proctor/composer-config/allow-plugins-all` |
| `composer-config` | `proctor/composer-config/secure-http-off` |
| `composer-config` | `proctor/composer-config/lock-off` |
| `composer-config` | `proctor/composer-config/audit-blocking-off` |
| `composer-config` | `proctor/composer-config/policy-off` |
| `composer-config` | `proctor/composer-config/policy-malware-off` |
| `composer-config` | `proctor/composer-config/policy-advisories-off` |
| `composer-config` | `proctor/composer-config/platform-php-unset` |
| `composer-cooldown` | `proctor/composer-cooldown` |
| `composer-cooldown` | `proctor/composer-cooldown/missing` |
| `composer-cooldown` | `proctor/composer-cooldown/not-blocking` |
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
| `mysql-eol` | `proctor/mysql-eol` |
| `mysql-minimum` | `proctor/mysql-minimum` |
| `mysql-minimum` | `proctor/mysql-minimum/below` |
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
| `ci-pipelines` | `proctor/ci-pipelines/composer-update` |
| `ci-pipelines` | `proctor/ci-pipelines/allow-overrides` |
| `preflight-pinned` | `proctor/preflight-pinned` |
| `preflight-pinned` | `proctor/preflight-pinned/unpinned` |
| `preflight-pinned` | `proctor/preflight-pinned/script-override` |
| `preflight-gates` | `proctor/preflight-gates` |
| `preflight-gates` | `proctor/preflight-gates/no-include` |
| `preflight-gates` | `proctor/preflight-gates/allow-failure` |
| `preflight-gates` | `proctor/preflight-gates/no-proctor-job` |
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
| `env-outside-config` | `proctor/env-outside-config` |
| `env-outside-config` | `proctor/env-outside-config/suppressed` |
| `raw-identity-header` | `proctor/raw-identity-header` |
| `app-key` | `proctor/app-key` |
| `app-key` | `proctor/app-key/committed-env` |
| `app-key` | `proctor/app-key/committed-key` |
| `app-key` | `proctor/app-key/cookie-sessions` |
| `app-debug` | `proctor/app-debug` |
| `app-debug` | `proctor/app-debug/enabled` |
| `display-errors` | `proctor/display-errors` |
| `display-errors` | `proctor/display-errors/on` |
| `display-errors` | `proctor/display-errors/log-off` |
| `directory-listing` | `proctor/directory-listing` |
| `directory-listing` | `proctor/directory-listing/indexes` |
| `csp-unsafe-inline` | `proctor/csp-unsafe-inline` |
| `csp-unsafe-inline` | `proctor/csp-unsafe-inline/script-src` |
| `robots-txt` | `proctor/robots-txt` |
| `robots-txt` | `proctor/robots-txt/missing` |
| `robots-txt` | `proctor/robots-txt/allows-all` |
| `vite-secrets` | `proctor/vite-secrets` |
| `vite-secrets` | `proctor/vite-secrets/env-file` |
| `vite-secrets` | `proctor/vite-secrets/source` |
| `served-folder` | `proctor/served-folder` |
| `served-folder` | `proctor/served-folder/debug-file` |
| `served-folder` | `proctor/served-folder/phpinfo` |
| `world-writable` | `proctor/world-writable` |
| `wayneui-forked-views` | `proctor/wayneui-forked-views` |
| `wayneui-outdated` | `proctor/wayneui-outdated` |
| `wayneui-outdated` | `proctor/wayneui-outdated/skipped` |
| `policy-age` | `proctor/policy-age` |

`tests/RuleIdContractTest.php` keeps this table in step with the checks: a rule
id that is emitted but not listed here fails the suite, and the test parses this
table, so a known rule id missing from it, or a row that names an unknown rule
id, fails the suite too.

## GitHub code scanning

The `sarif` format feeds GitHub code scanning on the GitHub mirror. Upload the
log with the official action. Every `uses:` is pinned to a full commit SHA, the
way the `ci-pipelines` check asks, and the job installs from the lock, the way
`proctor/ci-pipelines/composer-update` asks (PROCTOR and CREED are already dev
dependencies of the app):

```yaml
name: proctor
on: [push, pull_request]
permissions:
  contents: read
  security-events: write
jobs:
  proctor:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --no-progress
      # No --policy here: CI refuses it, and PROCTOR reads vendor/waynestate/creed's policy itself.
      - run: vendor/bin/proctor check --format=sarif > proctor.sarif
      - uses: github/codeql-action/upload-sarif@9b3254085494b55a0f7c4c2b3d2d52cc712ce370 # v4.39.1
        if: always()
        with:
          sarif_file: proctor.sarif
```

`if: always()` uploads the log even when the check exits 1, which is exactly
when you want to read it.

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

An override also has a maximum duration: an entry whose expiry lies more than
`rules.override_max_days` days after `--today` (90 days when the key is
absent) fails the `overrides` check (`proctor/overrides/too-long`) and is not
applied. The cap exists because an approval is a dated exception, not a
standing one, and CREED allows no permanent override. A policy whose
`override_max_days` value is not a positive integer is a tool error (exit 2)
naming the key.

PROCTOR rejects the file (exit 2) when an entry names a check that is not
registered in the run, or names the `overrides` or `override-approver` check:
an override cannot exempt the checks that police overrides, and an entry for a check
that does not exist would silently do nothing. The list of valid check ids
is built from the checks PROCTOR runs, so a new check needs no change to
the guard. An active override that expires within 14 days of `--today`
(the expiry day counts) gets a warning finding
(`proctor/overrides/expiring`) instead of lapsing without a trace, and an
active override whose check raised no failure gets a warning finding
(`proctor/overrides/unused`) so a stale approval is removed instead of
rotting. A warning never changes the exit code.

Someone other than the author approves an override. `proctor check` reads
git blame for each entry and reports `proctor/override-approver/self-approved`
when `approved_by` names a person who wrote one of its lines (a line that
holds only `approved_by` is the approver's signature and does not count).
With no git history to read, including a line that a shallow clone credits
to its oldest commit, it reports `proctor/override-approver/could-not-run`,
never a pass. CREED's `rules.override_self_approval` makes both `warn` (the
default) or `fail`. git.wayne.edu runs GitLab Community Edition, which has no
Code Owner approval, so the merge request is the human control: protect the
default branch, and have the person named in `approved_by` approve the merge
request that adds the entry. PROCTOR's check is the tripwire for the common
slip, writing your own name. How it matches names, squash merges, bots and
what it cannot see: [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html).

## Policy

`--policy` takes a CREED-format policy file: schema_version 1, component
versions with released, active_until and security_until dates, and rules
(abandoned_packages, audit_severity_threshold, banned_constraints, eol_grace_days,
override_max_days, override_self_approval). The policy-file wiring to `waynestate/creed` is
described below.

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

Weekly CREED copy refresh: a scheduled GitLab job (`creed-copy-refresh`,
Mondays, an hour after CREED's policy robot) re-exports the copy from CREED
main, rebuilds the docs, runs `composer creed-copy:check` and `composer ci`,
and pushes to main only when both pass. A red run leaves main untouched.
Details in MAINTAINING.md, "Refreshing the CREED copy".

## Proof

Proof runs against real Wayne State applications are on a private site, [proof.apps.wayne.edu/proctor](https://proof.apps.wayne.edu/proctor/), behind a Wayne State employee sign-in.

## Coding agents (ADVISOR)

PROCTOR ships a module for [ADVISOR](https://github.com/waynestate/advisor), the stack's read-only MCP server. With
`waynestate/advisor` installed as a dev dependency and the class `Waynestate\Proctor\Advisor\ProctorModule` listed in the
app's `modules.json`, a coding agent gains two read-only tools: `proctor_rules` (every rule id, and which are in the deploy
gate) and `proctor_check` (PROCTOR's file checks on an app folder, in-process, with the subprocess and `.env` checks
skipped and listed; the four checks it runs that read env files grade one only when git lists it, and name any they
did not open). PROCTOR does not require ADVISOR. The whole story:
[Use PROCTOR from your coding agent](https://proctor.apps.wayne.edu/docs/guides/advisor.html).

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

The docs pages wear the front page: the same masthead and main menu, ruled paper, Young Serif titles under a red pen stroke, the install slab for commands and the green footer band, in light and dark (dark is the same ruled paper on warm graphite, not a green page: WSU green stays on the seal and the footer). That look lives in two files, `docs/_header.html` (the masthead) and `docs/theme/proctor.css` (the palette and type, mapped onto the kit's `--dk-*` tokens), named by the `theme` key in `docs/docs.json`. Edit those, never the vendored kit (`bin/docs-site.php`, `site/assets/docs.css`, `site/assets/docs.js`), which comes from Squire with `--sync-to`.

## Running the tests in Docker

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](MAINTAINING.md).

No PHP is needed on the host:

```
docker run --rm -v "${PWD}:/app" -v stack-composer-cache:/tmp/cache -w /app composer:2 composer install --no-interaction
docker run --rm -v "${PWD}:/app" -w /app php:8.4-cli vendor/bin/phpunit
```

The `stack-composer-cache` volume keeps Composer's downloads between runs; Docker creates it the first time.

The same works with `php:8.3-cli` in place of `php:8.4-cli`.

The site has its own accessibility gate: Preflight's a11y checker (vendored in `tools/a11y/check.mjs` from the same Preflight tag the CI job runs, pinned by `tests/A11yCheckerPinTest.php`), axe-core 4.14.0 in Playwright, WCAG 2.2 AA, run in Docker over every HTML page in `site/` in light and dark at 320 and 1280 px, with the WCAG 1.4.10 reflow check: `scripts/a11y.sh` on Linux and macOS, `scripts/a11y.ps1` on Windows.

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
  <a href="https://git.wayne.edu/enterprise-apps/parity" title="Parity"><img src="docs/readme/cast/parity.png" width="40" alt="Parity"></a>
  <a href="https://github.com/waynestate/preflight" title="Preflight"><img src="docs/readme/cast/preflight.png" width="40" alt="Preflight"></a>
  <a href="https://github.com/waynestate/pinstripe" title="Pinstripe"><img src="docs/readme/cast/pinstripe.png" width="40" alt="Pinstripe"></a>
  <a href="https://github.com/waynestate/booster" title="Booster"><img src="docs/readme/cast/booster.png" width="40" alt="Booster"></a>
  <a href="https://github.com/waynestate/rollcall" title="Rollcall"><img src="docs/readme/cast/rollcall.png" width="40" alt="Rollcall"></a>
  <a href="https://git.wayne.edu/enterprise-apps/drawbridge" title="Drawbridge"><img src="docs/readme/cast/drawbridge.png" width="40" alt="Drawbridge"></a>
  <a href="https://github.com/waynestate/advisor" title="ADVISOR"><img src="docs/readme/cast/advisor.png" width="40" alt="ADVISOR"></a>
  <a href="https://github.com/waynestate/weld" title="WELD"><img src="docs/readme/cast/weld.png" width="40" alt="WELD"></a>
</div>

<div align="center"><sub>Images are regenerated from the front page by <a href="scripts/readme-art/README.md"><code>scripts/readme-art</code></a>. Pencils down.</sub></div>
