---
title: "Upgrading | PROCTOR docs"
description: "How to upgrade PROCTOR to a newer release and note the changes that affect your runs."
url: https://proctor.apps.wayne.edu/docs/upgrading.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Upgrading[#](https://proctor.apps.wayne.edu/docs/upgrading.html#upgrading)

PROCTOR is the invigilator, so upgrading it is a seat change, not a new exam: same hall, fresher pen. This page tells you how to move an app from one PROCTOR release to the next and which changes affect your runs. Start with the release that has a break, then work down to the additive ones.

## 0.8.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-8-0)

Released 2026-11-08. This is a pre-1.0 minor: consumers that pin `^0.7` stay on 0.7.x until they move their constraint to `^0.8`. Most of what is new is additive or a warning (the `mysql-eol` check, the `X-Sesame-Assertion` read in `raw-identity-header`, the ADVISOR module, release tags accepted by `preflight-pinned`), but one change can turn a passing run into a failing one.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking)

- `crlf-deploy-script` fails an app whose deploy script has Windows line endings (CRLF): the top-level `build.sh` and `release.sh`, and any `.sh` under `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/`. Bash on Linux reads the carriage return as part of each command, so such a script was already broken on the host; PROCTOR now says so before the deploy does. Convert the file to LF and add `*.sh text eol=lf` to `.gitattributes`.

### Changed[#](https://proctor.apps.wayne.edu/docs/upgrading.html#changed)

- `mysql-eol` is new and warns, never fails, while Citadel's shared database server is MySQL 5.6 (D-008, D-053). It grades the MySQL or MariaDB version named in a root compose file and in `SQUIRE_MYSQL_IMAGE` in `.env.example` or `.env.squire.example`: a security-only line warns with the days left, an end-of-life line warns as `eol_grace_expired` and says to move to MySQL 8.4 or later, and a floating major tag such as `mysql:8` warns until you pin a major.minor. It turns into a fail once Citadel offers a supported MySQL, so treat these warnings as the to-do list for that day.
- `raw-identity-header` also warns on a direct read of `X-Sesame-Assertion`, the signed assertion Drawbridge sends. Only SESAME's gateway driver should read it; take the user from SESAME instead.
- `preflight-pinned` accepts a Preflight component pinned to a full release tag such as `@0.3.0` as well as a commit SHA (D-054), so a tag pin no longer warns. A branch, `~latest` or a partial version such as `@0.3` still warns.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated)

- None recorded so far.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps)

1. Move the PROCTOR constraint in your composer.json from `^0.7` to `^0.8`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `crlf-deploy-script` finding first, then the `mysql-eol` warnings:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify)

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.8.0`.

## 0.7.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-7-0)

Released 2026-11-07. This is a pre-1.0 minor: consumers that pin `^0.6` stay on 0.6.x until they move their constraint to `^0.7`. Most of what is new is additive (`proctor gate`, the deploy gate, and the `raw-identity-header`, `env-outside-config`, `app-key`, `served-folder`, `laravel-php` and `laravel-eol` checks), but one change can turn a passing run into a failing one.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking-1)

- `node-eol` fails an app that pins an end-of-life Node line (Node 20 or older under the bundled policy, as of 2026-04-30). Node 20 is past the grace window, so it fails as `eol_grace_expired`, which no override softens: move to Node 22 or 24.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated-1)

- None recorded so far.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps-1)

1. Move the PROCTOR constraint in your composer.json from `^0.6` to `^0.7`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `node-eol` finding first:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify-1)

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.7.0`.

## 0.6.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-6-0)

Released 2026-11-05. This is a pre-1.0 minor: consumers that pin `^0.5` stay on 0.5.x until they move their constraint to `^0.6`. Most of what is new is additive, but three changes can turn a passing run into a failing one.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking-2)

- `composer-audit` fails when the composer executable is not on PATH or the app has no composer.lock (rule id `proctor/composer-audit/skipped`, message starting with `not audited:`). An app whose audit could not run used to pass with an info line; it now exits non-zero until Composer is installed, the lock is committed, or an active `composer-audit` override with a reason and an expiry downgrades the fail to a warning.
- `composer-audit` fails each ignore entry in composer.json that no active `composer-audit` override backs (rule id `proctor/composer-audit/unbacked-ignore`). An app that silences audit results in its own composer.json used to earn only a count warning; it now exits non-zero until every entry is backed by an active override whose `advisory` field names the entry's advisory id, whose `package` field matches the entry's package, or that carries no scope at all. Steps: record one such entry per unbacked ignore in policy-overrides.json with a reason and an expiry, keep the composer.json ignore until the override expires, then remove the ignore entry.
- **Refused `--policy` and `--today` in CI.** In CI (any of `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` set to a value other than `0` or `false`), `proctor check` now refuses `--policy` and `--today` with a tool error (exit 2) unless the job sets `PROCTOR_ALLOW_OVERRIDES=1`. In CI the policy and the date must come from the stack, not from the app being checked: a pipeline that could pass its own policy or date could grade itself. Pipelines that pass these options must either set `PROCTOR_ALLOW_OVERRIDES: "1"` as a variable of a trusted job (a protected or group-level CI variable, never the app's own `.gitlab-ci.yml`) or drop the option: name the policy with the `PROCTOR_POLICY` environment variable instead of `--policy` (it stays allowed in CI), and drop `--today` or let the trusted job allow it. Outside CI nothing changes.

### Changed[#](https://proctor.apps.wayne.edu/docs/upgrading.html#changed-1)

- An app that failed only because of a dev-only advisory now warns instead. An advisory on a package that composer.lock lists only in `packages-dev` is a warning at any severity, with the message ending in ` [dev dependency]`; a name in both lists counts as runtime and still fails at or above the threshold. No action is required unless you want the dev dependency updated, because dev tools run in CI.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated-2)

- None recorded so far.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps-2)

1. Move the PROCTOR constraint in your composer.json from `^0.5` to `^0.6`, then apply it with `composer require waynestate/proctor --dev`.
2. Re-run the check in CI and read any `Refused:` message first:
   ```bash
   vendor/bin/proctor check
   ```
3. For each refused option, either set `PROCTOR_ALLOW_OVERRIDES` to `1` on the trusted job (GitLab: group Settings, CI/CD, Variables, scope Protected), or drop the option and name the policy with `PROCTOR_POLICY`.
4. Make sure Composer and composer.lock are present where the check runs, or record a composer-audit override.

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify-2)

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.6.0`.
- Run `vendor/bin/proctor check` in CI: the run reaches the checks when no refused option is present, or the trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- Confirm the refusal names only the option or options that were given (`Refused: --policy is not accepted in CI, ...`, `Refused: --today is not accepted in CI, ...`, or both named together).

## 0.5.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-5-0)

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`. Most of what is new warns and never fails, but one check can now turn a passing run into a failing one.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking-3)

- `composer-audit` fails when `composer audit` runs but its output is not parseable JSON (rule id `proctor/composer-audit/could-not-run`, message starting `not audited:`). A run that audited nothing used to pass quietly; it now exits non-zero, so an app whose audit cannot run will go red until the audit is fixed.
- Consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated-3)

- None recorded for this release.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps-3)

1. Move the PROCTOR constraint in your composer.json from `^0.4` to `^0.5`.
2. Apply it with the install command from the README:
   ```bash
   composer require waynestate/proctor --dev
   ```
3. Re-run the check, and read any `not audited:` failure first:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify-3)

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.5.0`.
- Confirm the new warnings appear where they apply. They warn and never fail:
  - `composer-config` warns when composer.json switches Composer's dependency policy off (`proctor/composer-config/policy-off`, `policy-malware-off`, `policy-advisories-off`).
  - `ci-pipelines` warns when the pipeline environment switches Composer's audit or blocking policy off (`proctor/ci-pipelines/composer-audit-off`).
  - `composer-audit` also counts ignore entries under `config.policy` (`proctor/composer-audit/ignore-entries`), and a policy override may name one advisory or package.

## 0.4.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-4-0)

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The new checks warn and never fail.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking-4)

- Consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. That constraint move is the only break in this release.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated-4)

- None recorded for this release.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps-4)

1. Move the PROCTOR constraint in your composer.json from `^0.3` to `^0.4`.
2. Apply it with the install command from the README:
   ```bash
   composer require waynestate/proctor --dev
   ```
3. Re-run the check so the new checks take part:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify-4)

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

## Related[#](https://proctor.apps.wayne.edu/docs/upgrading.html#related)

- [Install](https://proctor.apps.wayne.edu/docs/install.html)
- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html)
- [Checks](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html)
- [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html)
- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Troubleshooting](https://proctor.apps.wayne.edu/docs/troubleshooting.html)
