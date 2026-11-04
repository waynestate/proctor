---
title: "Upgrading | PROCTOR docs"
description: "How to upgrade PROCTOR to a newer release and note the changes that affect your runs."
url: https://proctor.apps.wayne.edu/docs/upgrading.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Upgrading[#](https://proctor.apps.wayne.edu/docs/upgrading.html#upgrading)

PROCTOR is the invigilator, so upgrading it is a seat change, not a new exam: same hall, fresher pen. This page tells you how to move an app from one PROCTOR release to the next and which changes affect your runs. Start with the release that has a break, then work down to the additive ones.

## Unreleased[#](https://proctor.apps.wayne.edu/docs/upgrading.html#unreleased)

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking)

- `composer-audit` fails when the composer executable is not on PATH or the app has no composer.lock (rule id `proctor/composer-audit/skipped`, message starting with `not audited:`). An app whose audit could not run used to pass with an info line; it now exits non-zero until Composer is installed, the lock is committed, or an active `composer-audit` override with a reason and an expiry downgrades the fail to a warning.
- `composer-audit` fails each ignore entry in composer.json that no active `composer-audit` override backs (rule id `proctor/composer-audit/unbacked-ignore`). An app that silences audit results in its own composer.json used to earn only a count warning; it now exits non-zero until every entry is backed by an active override whose `advisory` field names the entry's advisory id, whose `package` field matches the entry's package, or that carries no scope at all. Steps: record one such entry per unbacked ignore in policy-overrides.json with a reason and an expiry, keep the composer.json ignore until the override expires, then remove the ignore entry.

## 0.5.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-5-0)

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`. Most of what is new warns and never fails, but one check can now turn a passing run into a failing one.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking-1)

- `composer-audit` fails when `composer audit` runs but its output is not parseable JSON (rule id `proctor/composer-audit/could-not-run`, message starting `not audited:`). A run that audited nothing used to pass quietly; it now exits non-zero, so an app whose audit cannot run will go red until the audit is fixed.
- Consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated)

- None recorded for this release.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps)

1. Move the PROCTOR constraint in your composer.json from `^0.4` to `^0.5`.
2. Apply it with the install command from the README:
   ```bash
   composer require waynestate/proctor --dev
   ```
3. Re-run the check, and read any `not audited:` failure first:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify)

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.5.0`.
- Confirm the new warnings appear where they apply. They warn and never fail:
  - `composer-config` warns when composer.json switches Composer's dependency policy off (`proctor/composer-config/policy-off`, `policy-malware-off`, `policy-advisories-off`).
  - `ci-pipelines` warns when the pipeline environment switches Composer's audit or blocking policy off (`proctor/ci-pipelines/composer-audit-off`).
  - `composer-audit` also counts ignore entries under `config.policy` (`proctor/composer-audit/ignore-entries`), and a policy override may name one advisory or package.

## 0.4.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-4-0)

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The new checks warn and never fail.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking-2)

- Consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. That constraint move is the only break in this release.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated-1)

- None recorded for this release.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps-1)

1. Move the PROCTOR constraint in your composer.json from `^0.3` to `^0.4`.
2. Apply it with the install command from the README:
   ```bash
   composer require waynestate/proctor --dev
   ```
3. Re-run the check so the new checks take part:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify-1)

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
