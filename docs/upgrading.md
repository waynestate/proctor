---
title: Upgrading
description: How to upgrade PROCTOR to a newer release and note the changes that affect your runs.
---

# Upgrading

PROCTOR is the invigilator, so upgrading it is a seat change, not a new exam: same hall, fresher pen. This page tells you how to move an app from one PROCTOR release to the next and which changes affect your runs. Start with the release that has a break, then work down to the additive ones.

## 0.15.0

Released 2026-11-10. A minor release. Under 0.x, `^0.14` does not pick it up: change the constraint to `^0.15`, then run `composer update waynestate/proctor`.

### Breaking

- None. The new check only warns, and warnings never fail a run or the deploy gate.

### New

- `mysql56-ci` warns when an app has migrations in `database/migrations/` but its `.gitlab-ci.yml` never runs them against MySQL 5.6 (rule ID `proctor/mysql56-ci/no-job`). CREED `databases.md` makes that a MUST: Citadel's shared server is MySQL 5.6.51, and a test suite on SQLite passes DDL that 5.6 rejects. `mysql56` scans the code; `mysql56-ci` checks the wiring.
- `preflight-gates` treats `mysql56-migrate` as a gate, so a `mysql56-migrate` include with `allow_failure: true` warns like the other gates.
- The check count is forty-five, eight of them in the deploy gate (unchanged).

### What to do

1. Change the constraint in `composer.json` to `"waynestate/proctor": "^0.15"` and run `composer update waynestate/proctor`.
2. If `mysql56-ci` warns, include Preflight's `mysql56-migrate` component (Preflight 0.6.0 or later). The job runs `php artisan migrate` against a real MySQL 5.6.51 on an `amd64` runner:

   ```yaml
   include:
     - component: $CI_SERVER_FQDN/enterprise-apps/preflight/mysql56-migrate@30dd7094089931015ec8754fb321e17659749f05 # 0.6.0
   ```

3. An app with no `database/migrations/` folder sees nothing new.

## 0.14.2

Released 2026-11-10. A patch: `^0.14` picks it up with `composer update waynestate/proctor`. Nothing changes for apps: a run that passed on 0.14.1 passes the same way.

### Breaking

- None.

### Changed

- Two of PROCTOR's own scripts that run only in CI (`scripts/creed-copy-refresh.sh` and `scripts/wayneui-copies-scan.sh`) carry the `# proctor-ignore script-pair:` marker, so PROCTOR's self-check shows them as info notes instead of missing twins.

### What to do

- Nothing.

## 0.14.1

Released 2026-11-10. A patch: `^0.14` picks it up with `composer update waynestate/proctor`. Nothing breaks, and a run that passed on 0.14.0 still passes, usually with fewer warnings.

### Breaking

- None.

### Changed

- `script-pair` no longer warns on Squire's deploy-host scripts: a script under `scripts/` that the app's `.squire.json` lists in `files` (such as `scripts/release.sh`) runs on the Citadel host, not a developer machine, and now earns an info note (`proctor/script-pair/squire-owned`) instead of `missing-ps1`.
- A script that runs on one OS on purpose (only inside a Linux CI container, say) can say so with `# proctor-ignore script-pair: <reason>` in its first 10 lines; it earns an info note (`proctor/script-pair/single-os`) instead of the warning. The reason is required: the marker with nothing after the colon is ignored.
- Info notes are visible in the report and not counted, so a check whose only findings are notes still passes.
- `script-pair` walks only the app's `scripts/` folder instead of the whole tree, so it stays fast on large apps; its findings are unchanged.

### What to do

- Nothing for Squire's scripts. For a script of your own that is single-OS on purpose, add the marker line with the reason; for a developer script, add its twin as before.

## 0.14.0

Released 2026-11-10. This is a pre-1.0 minor: consumers that pin `^0.13` stay on 0.13.x until they move their constraint to `^0.14`. Nothing breaks: the one new check, `script-pair`, only warns and is not in the deploy gate, so a run that passed on 0.13.0 still passes. The proctor now checks that every candidate brought both pencils: a developer script in `scripts/` ships as a `.ps1` and `.sh` pair, as CREED's every-os rule asks.

### Breaking

- None. The new findings are warnings, and a warning never changes the exit code.

### Added

| Check | Rule ids | What it warns on |
| --- | --- | --- |
| `script-pair` | `proctor/script-pair/missing-ps1`, `proctor/script-pair/missing-sh` | a `.sh` under the app's top-level `scripts/` (any depth, `vendor/`, `node_modules/` and `.git/` skipped) with no `.ps1` of the same name in the same folder, or a `.ps1` with no `.sh`; `deploy/`, `bin/` and other folders are not read |

### Changed

- The check count is forty-four, eight of them in the deploy gate (unchanged).

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.13` to `^0.14`, then apply it with `composer require waynestate/proctor --dev`.
2. If `proctor check` now warns `missing-ps1` or `missing-sh`, add the missing twin next to the script (same name, same folder, doing the same thing), or turn the script into one PHP command that runs on every OS.

## 0.13.0

Released 2026-11-10. This is a pre-1.0 minor: consumers that pin `^0.12` stay on 0.12.x until they move their constraint to `^0.13`. Nothing breaks: `composer-cooldown` still only warns and is not in the deploy gate, so a run that passed on 0.12.0 still passes. The proctor now reads the cooldown from the rule book instead of reciting it from memory: the period and the exemptions come from the CREED policy, and an app that waits less than the policy asks gets a warning.

### Breaking

- None. The new finding is a warning, and a warning never changes the exit code.

### Added

| Check | Rule ids | What it warns on |
| --- | --- | --- |
| `composer-cooldown` | `proctor/composer-cooldown/too-short` | composer.json sets a `config.policy.cooldown.period` shorter than the one the CREED policy names (`tools.composer.cooldown.period`, 3 days in the bundled policy), compared in hours; a period PROCTOR cannot read is not compared |

### Changed

- `composer-cooldown`'s `missing` advice names the CREED policy's period and exemptions. With the bundled policy (3 days, `waynestate/*` exempt) the message is the same as in 0.12.0; a custom policy (`--policy`) with another period or ignore list changes the example it prints.
- The generated CREED reader in `src/Creed` is refreshed from CREED 0.1.10, which adds `Policy::toolCooldownPeriod()` and `Policy::toolCooldownIgnore()`.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.12` to `^0.13`, then apply it with `composer require waynestate/proctor --dev`.
2. If `proctor check` now warns `too-short`, raise `config.policy.cooldown.period` in composer.json to at least the policy's period (`"3 days"` for the stack), then refresh the lock's content hash with `composer update --lock`.

## 0.12.0

Released 2026-11-10. This is a pre-1.0 minor: consumers that pin `^0.11` stay on 0.11.x until they move their constraint to `^0.12`. Nothing breaks: the two new checks only warn and are not in the deploy gate, so a run that passed on 0.11.2 still passes and a deploy that went ahead still goes ahead. Expect new warnings when your composer.json sets no release cooldown, when git does not track your `composer.lock`, or when a CI component other than Preflight's is included at a moving ref. Two new rules for the hall: wait three days before trusting a fresh package, and hand in the lock you actually wrote.

### Breaking

- None. The new findings are warnings, and a warning never changes the exit code.

### Added

The new checks only warn, never fail, and are not in the deploy gate:

| Check | Rule ids | What it warns on |
| --- | --- | --- |
| `composer-cooldown` | `proctor/composer-cooldown/missing`, `proctor/composer-cooldown/not-blocking` | composer.json sets no `config.policy.cooldown.period`, or sets `block` to `false` |
| `lock-tracked` | `proctor/lock-tracked` | `composer.lock` is on disk but git does not track it (git-ignored included), which CI's own `composer install` would otherwise hide; outside a git repository it says nothing |

`lock-tracked` runs git, so ADVISOR's in-process `proctor_check` skips it; `composer-cooldown` runs there too.

### Changed

- `preflight-pinned` warns on any CI component included at a moving ref, not only Preflight's (CREED practice 23). The script-override warning stays Preflight's.
- `composer-audit` runs the audit once more when the first run prints no JSON, and reports could-not-run only when the second run fails too, so a passing deploy gate is less often waived for missing advisory data.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.11` to `^0.12`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check and read any new warning:

   ```bash
   vendor/bin/proctor check
   ```

   - `composer-cooldown/missing`: add `"policy": {"cooldown": {"period": "3 days", "ignore": ["waynestate/*"]}}` under `config` in composer.json (the period CREED's policy names, and what every Sourdough shape sets). It needs Composer 2.11 or later to take effect.
   - `lock-tracked`: commit the lock with `git add composer.lock`, and remove it from `.gitignore` if it is listed there.
   - `preflight-pinned`: pin the named component to a tag.

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.12.0`.

## 0.11.2

Released 2026-11-09. A patch: `^0.11` picks it up with `composer update waynestate/proctor`. No check, option, verdict or exit code changes: this release carries the docs, the version mentions, PROCTOR's own CI on Composer 2.11.1 and Preflight 0.5.0, and the deploy kit on Squire 0.10.2. Same exam, freshly photocopied.

### Breaking

- None.

### Steps

1. Run `composer update waynestate/proctor`.

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.11.2`.

## 0.11.1

Released 2026-11-09. A patch: `^0.11` picks it up with `composer update waynestate/proctor`. `proctor check` and `proctor gate` run exactly as on 0.11.0, so every verdict and exit code stays the same. The one change is inside ADVISOR: its in-process `proctor_check` no longer opens an env file git does not list. The proctor still walks the hall, but no longer reads a paper that was never handed in.

### Breaking

- None.

### Added

- `proctor_check` answers carry `skipped_files`: each env file a check that ran did not open, with `id`, `file` and `reason` (`not tracked by git`, or `no git index to read`). See [Use PROCTOR from your coding agent](guides/advisor.md#what-is-skipped-and-why).

### Changed

- In ADVISOR, `app-debug`, `csp-unsafe-inline`, `mysql-eol` and `mysql-minimum` grade an env file (`.env.production`, `.env.example`, `.env.squire.example`) only when git lists it, the rule `app-key` and `vite-secrets` follow in a full run. PROCTOR reads git's index in PHP for this, so the tool still starts no subprocess. An agent that saw an `app-debug` or `csp-unsafe-inline` warning on a local, uncommitted `.env.production` no longer sees it there; `vendor/bin/proctor check` still reports it.

### Deprecated

- None.

### Steps

1. Run `composer update waynestate/proctor`.
2. If your agent should grade an env file through ADVISOR, commit it (`git add`); an example file such as `.env.example` is meant to be committed anyway.

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.11.1`.

## 0.11.0

Released 2026-11-09. This is a pre-1.0 minor: consumers that pin `^0.10` stay on 0.10.x until they move their constraint to `^0.11`. Nothing breaks: the one new check only warns and is not in the deploy gate, and the new `--record` option changes nothing until you pass it, so a run that passed on 0.10.0 still passes and a deploy that went ahead still goes ahead. Expect a new warning when `.gitattributes` does not pin your server files to LF.

### Breaking

- None. The new check's findings are warnings, and a warning never changes the exit code.

### Added

The new check only warns, never fails, and is not in the deploy gate:

| Check | Rule ids | What it warns on |
| --- | --- | --- |
| `gitattributes-eol` | `proctor/gitattributes-eol`, `proctor/gitattributes-eol/not-pinned`, `proctor/gitattributes-eol/crlf` | `.gitattributes` does not pin the app's shell scripts, `*.conf` files and Dockerfiles to `eol=lf`, or a Dockerfile or `.conf` under `deploy/` or `.squire/` has Windows line endings |

`proctor check --record=<file>` also writes the JSON report (byte for byte what `--format=json` prints) to a file, whatever `--format` prints, so one CI run can feed both the merge request's Code Quality and Rollcall, which reads `proctor.json` from the `proctor` job's artifacts (see [CI checks](guides/check-ci.md)). The exit code is the check's own; a record PROCTOR cannot write is a tool error (exit 2). `proctor gate --record` shares the same file writer but writes the gate's verdict (`command: gate`, `verdict`, `summary`, each finding's `gate` outcome), a different JSON from the check report; keep it for the next `--baseline`, and do not hand it to Rollcall.

### Changed

- None that changes a run.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.10` to `^0.11`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check and read any `gitattributes-eol` warning; it names the lines to add to `.gitattributes`, then run `git add --renormalize .` and commit (see [App checks](guides/check-app.md#gitattributes-eol)):

   ```bash
   vendor/bin/proctor check
   ```

3. Optional: when Rollcall should read your app, add `--record="$CI_PROJECT_DIR/proctor.json"` to the `proctor check` command of the `proctor` job and keep the file as an artifact under `when: always`. Preflight 0.5.0's component does both for you. On Preflight 0.4.0 the component keeps only the Code Quality report, so set its `proctor_command` input and add `artifacts: paths: [proctor.json]` to a `proctor:` job of your own (see [CI checks](guides/check-ci.md)).

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.11.0`.

## 0.10.0

Released 2026-11-09. This is a pre-1.0 minor: consumers that pin `^0.9` stay on 0.9.x until they move their constraint to `^0.10`. Everything new only warns: nine new checks and two new rules on existing checks, none of them in the deploy gate, so a run that passed on 0.9.0 still passes and a deploy that went ahead still goes ahead. Expect new warnings in the report.

### Breaking

- None. Every new finding is a warning, and a warning never changes the exit code.

### Added

Each of these checks only warns, never fails, and none is in the deploy gate:

| Check | Rule ids | What it warns on |
| --- | --- | --- |
| `mysql-minimum` | `proctor/mysql-minimum`, `proctor/mysql-minimum/below` | the app's MySQL or MariaDB image is below the minimum the policy records for its Laravel line |
| `entrypoint-migrate` | `proctor/entrypoint-migrate`, `proctor/entrypoint-migrate/runs` | a container entrypoint runs `artisan migrate` at start |
| `preflight-gates` | `proctor/preflight-gates`, `proctor/preflight-gates/no-include`, `proctor/preflight-gates/allow-failure`, `proctor/preflight-gates/no-proctor-job` | `.gitlab-ci.yml` includes no Preflight component, lets a Preflight gate fail, or runs no PROCTOR job |
| `app-debug` | `proctor/app-debug`, `proctor/app-debug/enabled` | a committed production env, deploy or CI file sets `APP_DEBUG` to true |
| `display-errors` | `proctor/display-errors`, `proctor/display-errors/on`, `proctor/display-errors/log-off` | a committed php.ini, .user.ini or .htaccess turns `display_errors` on or `log_errors` off |
| `directory-listing` | `proctor/directory-listing`, `proctor/directory-listing/indexes` | a committed .htaccess or server config turns on directory listing |
| `csp-unsafe-inline` | `proctor/csp-unsafe-inline`, `proctor/csp-unsafe-inline/script-src` | committed config lets scripts run inline (`'unsafe-inline'` in a script source) |
| `robots-txt` | `proctor/robots-txt`, `proctor/robots-txt/missing`, `proctor/robots-txt/allows-all` | an app with a committed `public/index.php` commits no `public/robots.txt`, or one with neither `Disallow: /` nor `Allow: /` for `User-agent: *` (write `Allow: /` when crawling is wanted) |
| `vite-secrets` | `proctor/vite-secrets`, `proctor/vite-secrets/env-file`, `proctor/vite-secrets/source` | a secret-looking `VITE_` variable, which Vite ships to the browser |

Two existing checks gain a rule, and both only warn: `crlf-deploy-script` warns (`proctor/crlf-deploy-script/no-gitattributes`) when the app has a deploy script and .gitattributes does not pin `*.sh` to `eol=lf`, and `ci-pipelines` warns (`proctor/ci-pipelines/allow-overrides`) when a pipeline sets `PROCTOR_ALLOW_OVERRIDES` to 1.

### Changed

- `display-errors` reads an ini value without its inline comment, so `display_errors = On ; note` warns.
- The portability check's message says "analyze" (US spelling); a script that matched the old text needs the new one.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.9` to `^0.10`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check and read the new warnings; each one names the file and line to fix, and the [App checks](guides/check-app.md), [CI checks](guides/check-ci.md) and [Rule ids](reference/rule-ids.md) pages say how:

   ```bash
   vendor/bin/proctor check
   ```

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.10.0`.

## 0.9.0

Released 2026-11-08. This is a pre-1.0 minor: consumers that pin `^0.8` stay on 0.8.x until they move their constraint to `^0.9`. Most of what is new reports without blocking (the `dev-only-packages`, `case-clash` and `override-approver` checks are not in the deploy gate, and the gate stages from CREED change nothing until CREED ships a stage), but two changes can turn a passing run into a failing one.

### Breaking

- An override may run at most 90 days past today (CREED `rules.override_max_days`). A longer one fails the overrides check (`proctor/overrides/too-long`) and is not applied, and the overrides check is in the deploy gate, so a deploy that leaned on a long override now stops. Shorten the `expires` date to 90 days or less and renew it when it runs out.
- `proctor check` fails on three new findings: `dev-only-packages` when Laravel Boost or Debugbar sits in `require` (they install in production and expose the app's internals; move them to `require-dev`), `case-clash` when two paths differ only by letter case (they collide on macOS and Windows checkouts; rename one), and `override-approver` when CREED sets `rules.override_self_approval` to `fail` and an override's `approved_by` names someone who wrote it. None of the three is in the deploy gate.

### Changed

- `dev-only-packages` warns on Telescope and on test tools (PHPUnit, Pest, Mockery, Sail, Pail, Collision) in `require`.
- `override-approver` warns by default (`rules.override_self_approval` absent), and reports `could-not-run`, never a pass, without git history; Preflight's proctor component clones the full history by default since its `git_depth` input.
- The deploy gate honors CREED's gate stages: a failure whose check the policy puts at `report` or `warn` is logged as `staged` and the deploy goes ahead. The JSON summary gains `staged` and each finding `stage`.
- The front page lists the supported versions from the bundled policy.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.8` to `^0.9`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `overrides` finding first (it blocks the deploy), then `dev-only-packages` and `case-clash`:

   ```bash
   vendor/bin/proctor check
   ```

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.9.0`.

## 0.8.0

Released 2026-11-08. This is a pre-1.0 minor: consumers that pin `^0.7` stay on 0.7.x until they move their constraint to `^0.8`. Most of what is new is additive or a warning (the `mysql-eol` check, the `X-Sesame-Assertion` read in `raw-identity-header`, the ADVISOR module, release tags accepted by `preflight-pinned`), but one change can turn a passing run into a failing one.

### Breaking

- `crlf-deploy-script` fails an app whose deploy script has Windows line endings (CRLF): the top-level `build.sh` and `release.sh`, and any `.sh` under `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/`. Bash on Linux reads the carriage return as part of each command, so such a script was already broken on the host; PROCTOR now says so before the deploy does. Convert the file to LF and add `*.sh text eol=lf` to `.gitattributes`.

### Changed

- `mysql-eol` is new and warns, never fails, while Citadel's shared database server is MySQL 5.6. It grades the MySQL or MariaDB version named in a root compose file and in `SQUIRE_MYSQL_IMAGE` in `.env.example` or `.env.squire.example`: a security-only line warns with the days left, an end-of-life line warns as `eol_grace_expired` and says to move to MySQL 8.4 or later, and a floating major tag such as `mysql:8` warns until you pin a major.minor. It turns into a fail once Citadel offers a supported MySQL, so treat these warnings as the to-do list for that day.
- `raw-identity-header` also warns on a direct read of `X-Sesame-Assertion`, the signed assertion Drawbridge sends. Only SESAME's gateway driver should read it; take the user from SESAME instead.
- `preflight-pinned` accepts a Preflight component pinned to a full release tag such as `@0.3.0` as well as a commit SHA, so a tag pin no longer warns. A branch, `~latest` or a partial version such as `@0.3` still warns.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.7` to `^0.8`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `crlf-deploy-script` finding first, then the `mysql-eol` warnings:

   ```bash
   vendor/bin/proctor check
   ```

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.8.0`.

## 0.7.0

Released 2026-11-07. This is a pre-1.0 minor: consumers that pin `^0.6` stay on 0.6.x until they move their constraint to `^0.7`. Most of what is new is additive (`proctor gate`, the deploy gate, and the `raw-identity-header`, `env-outside-config`, `app-key`, `served-folder`, `laravel-php` and `laravel-eol` checks), but one change can turn a passing run into a failing one.

### Breaking

- `node-eol` fails an app that pins an end-of-life Node line (Node 20 or older under the bundled policy, as of 2026-04-30). Node 20 is past the grace window, so it fails as `eol_grace_expired`, which no override softens: move to Node 22 or 24.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.6` to `^0.7`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `node-eol` finding first:

   ```bash
   vendor/bin/proctor check
   ```

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.7.0`.

## 0.6.0

Released 2026-11-05. This is a pre-1.0 minor: consumers that pin `^0.5` stay on 0.5.x until they move their constraint to `^0.6`. Most of what is new is additive, but three changes can turn a passing run into a failing one.

### Breaking

- `composer-audit` fails when the composer executable is not on PATH or the app has no composer.lock (rule id `proctor/composer-audit/skipped`, message starting with `not audited:`). An app whose audit could not run used to pass with an info line; it now exits non-zero until Composer is installed, the lock is committed, or an active `composer-audit` override with a reason and an expiry downgrades the fail to a warning.
- `composer-audit` fails each ignore entry in composer.json that no active `composer-audit` override backs (rule id `proctor/composer-audit/unbacked-ignore`). An app that silences audit results in its own composer.json used to earn only a count warning; it now exits non-zero until every entry is backed by an active override whose `advisory` field names the entry's advisory id, whose `package` field matches the entry's package, or that carries no scope at all. Steps: record one such entry per unbacked ignore in policy-overrides.json with a reason and an expiry, keep the composer.json ignore until the override expires, then remove the ignore entry.
- **Refused `--policy` and `--today` in CI.** In CI (any of `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` set to a value other than `0` or `false`), `proctor check` now refuses `--policy` and `--today` with a tool error (exit 2) unless the job sets `PROCTOR_ALLOW_OVERRIDES=1`. In CI the policy and the date must come from the stack, not from the app being checked: a pipeline that could pass its own policy or date could grade itself. Pipelines that pass these options must either set `PROCTOR_ALLOW_OVERRIDES: "1"` as a variable of a trusted job (a protected or group-level CI variable, never the app's own `.gitlab-ci.yml`) or drop the option: name the policy with the `PROCTOR_POLICY` environment variable instead of `--policy` (it stays allowed in CI), and drop `--today` or let the trusted job allow it. Outside CI nothing changes.

### Changed

- An app that failed only because of a dev-only advisory now warns instead. An advisory on a package that composer.lock lists only in `packages-dev` is a warning at any severity, with the message ending in ` [dev dependency]`; a name in both lists counts as runtime and still fails at or above the threshold. No action is required unless you want the dev dependency updated, because dev tools run in CI.

### Deprecated

- None recorded so far.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.5` to `^0.6`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `Refused:` message first:

   ```bash
   vendor/bin/proctor check
   ```

3. For each refused option, either set `PROCTOR_ALLOW_OVERRIDES` to `1` on the trusted job (GitLab: group Settings, CI/CD, Variables, scope Protected), or drop the option and name the policy with `PROCTOR_POLICY`.
4. Make sure Composer and composer.lock are present where the check runs, or record a composer-audit override.

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.6.0`.
- Run `vendor/bin/proctor check` in CI: the run reaches the checks when no refused option is present, or the trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- Confirm the refusal names only the option or options that were given (`Refused: --policy is not accepted in CI, ...`, `Refused: --today is not accepted in CI, ...`, or both named together).

## 0.5.0

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`. Most of what is new warns and never fails, but one check can now turn a passing run into a failing one.

### Breaking

- `composer-audit` fails when `composer audit` runs but its output is not parseable JSON (rule id `proctor/composer-audit/could-not-run`, message starting `not audited:`). A run that audited nothing used to pass quietly; it now exits non-zero, so an app whose audit cannot run will go red until the audit is fixed.
- Consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`.

### Deprecated

- None recorded for this release.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.4` to `^0.5`.
2. Apply it with the install command from the README:

   ```bash
   composer require waynestate/proctor --dev
   ```

3. Re-run the check, and read any `not audited:` failure first:

   ```bash
   vendor/bin/proctor check
   ```

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.5.0`.
- Confirm the new warnings appear where they apply. They warn and never fail:
  - `composer-config` warns when composer.json switches Composer's dependency policy off (`proctor/composer-config/policy-off`, `policy-malware-off`, `policy-advisories-off`).
  - `ci-pipelines` warns when the pipeline environment switches Composer's audit or blocking policy off (`proctor/ci-pipelines/composer-audit-off`).
  - `composer-audit` also counts ignore entries under `config.policy` (`proctor/composer-audit/ignore-entries`), and a policy override may name one advisory or package.

## 0.4.0

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The new checks warn and never fail.

### Breaking

- Consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. That constraint move is the only break in this release.

### Deprecated

- None recorded for this release.

### Steps

1. Move the PROCTOR constraint in your composer.json from `^0.3` to `^0.4`.
2. Apply it with the install command from the README:

   ```bash
   composer require waynestate/proctor --dev
   ```

3. Re-run the check so the new checks take part:

   ```bash
   vendor/bin/proctor check
   ```

### How to verify

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.4.0`.
- Confirm the checks added in this release appear in the report. They warn and never fail, so the exit code of a run that passed before should not change:
  - `composer-audit` reports the count of audit ignore entries (`proctor/composer-audit/ignore-entries`).
  - `overrides` warns when an active entry matches no failure (`proctor/overrides/unused`).
  - `unbounded-constraints` warns on a repository URL that is plain http (`proctor/unbounded-constraints/insecure-url`) and on a `minimum-stability` below stable (`proctor/unbounded-constraints/minimum-stability`).
  - `preflight-pinned` warns when a Preflight component is included at a ref that is not a commit SHA, or when an app overrides the script of an included Preflight job.
  - `lock-committed` warns when the composer.lock content-hash differs from composer.json.

The other released versions (0.1.0 through 0.3.1) record no break in the changelog; they add checks and fixes, and the upgrade command is the same for every move:

```bash
composer require waynestate/proctor --dev
```

## Related

- [Install](install.md)
- [Quick start](guides/quick-start.md)
- [Checks](guides/checks-overview.md)
- [Policy](guides/policy.md)
- [Exit codes](reference/exit-codes.md)
- [Troubleshooting](troubleshooting.md)
