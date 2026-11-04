---
title: Policy
description: How PROCTOR finds the CREED-format policy file it grades with, from --policy through the bundled copy.
---

# Policy

This page explains which CREED-format policy file PROCTOR checks against, and how to point it at a different one. Pick the source that fits the app (an explicit file, an environment variable, the CREED package, or the bundled copy), then confirm in the report which policy the run actually used. The proctor grades against the school's answer key, not its own memory, and it always says which key it used.

## What a policy file contains

`--policy` takes a CREED-format policy file: schema_version 1, component versions with released, active_until and security_until dates, and rules (audit_severity_threshold, banned_constraints, eol_grace_days). The policy-file wiring to `waynestate/creed` is described in [The CREED reader copy](#the-creed-reader-copy) below.

## Resolution order

PROCTOR resolves the policy in this order, first match wins:

1. The `--policy` option, naming a policy file (origin `option`).
2. The `PROCTOR_POLICY` environment variable, naming a policy file (origin `env`).
3. `<app>/vendor/waynestate/creed/policy/policy.json`, when it exists, is readable, and is at least as fresh as the bundled copy (origin `vendor`).
4. The policy bundled with PROCTOR, `src/Creed/policy.json` (origin `bundled`).

Name the policy explicitly:

```bash
vendor/bin/proctor check --policy=policy.json
```

Or set the environment variable for the shell:

```sh tab="Linux / macOS"
export PROCTOR_POLICY=/path/to/policy.json
vendor/bin/proctor check
```

```powershell tab="Windows (PowerShell)"
$env:PROCTOR_POLICY = 'C:\path\to\policy.json'
vendor/bin/proctor check
```

> [!NOTE]
> The resolver reads each candidate's top-level `refreshed_on` date from the raw JSON and treats it as a date only when it is an ISO date (`YYYY-MM-DD`). A value that is absent or not one is treated as absent.

## The vendor copy

An app that installs CREED gets its policy automatically:

```bash
composer require waynestate/creed --dev
```

The vendor copy wins over the bundled one only when it is at least as fresh: its `refreshed_on` is the same as or newer than the bundled copy's (ISO dates compare as strings), or the bundled copy has no `refreshed_on` date at all.

A vendor copy that cannot be read or parsed is skipped with a note on stderr and the run continues with the next source; a vendor copy older than the bundled one is skipped the same way. The notes PROCTOR can print:

- `Skipping vendor policy <path>: the file could not be read.`
- `Skipping vendor policy <path>: invalid JSON (<reason>).`
- `Skipping vendor policy <path>: <reason>.` when the CREED reader rejects the file
- `Using the bundled policy: the vendor copy at <path> (refreshed <date>) is older than the bundled copy (refreshed <date>).` When a copy has no date, its slot reads `without a refreshed_on date` instead.

> [!WARNING]
> An explicit source (`--policy` or `PROCTOR_POLICY`) that points at a missing or invalid file is still an error (exit 2). Only the vendor copy is skipped quietly.

## Policy and date in CI

In CI (any of `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` set to a value other than `0` or `false`), `--policy` and `--today` are refused with a tool error (exit 2): in CI the policy and the date must come from the stack, not from the app being checked, so a pipeline that could pass its own policy or date in its own `.gitlab-ci.yml` could grade itself. The refusal message names the option or options that were given.

The `PROCTOR_POLICY` environment variable stays allowed in CI; it is how a trusted job names a policy. A trusted job (a protected or group-level CI variable, never the app's own `.gitlab-ci.yml`) can allow the two options by setting `PROCTOR_ALLOW_OVERRIDES` to exactly `1`:

```yaml
# Group Settings > CI/CD > Variables: name PROCTOR_ALLOW_OVERRIDES, value 1,
# type Variable, scope Protected. Never in the app's own .gitlab-ci.yml.
PROCTOR_ALLOW_OVERRIDES: "1"
```

Outside CI nothing changes: both options work as described above.

## What the report says

Every report names the policy it used: origin (`option`, `env`, `vendor` or `bundled`), path, `refreshed_on` and the age in days. The age is whole days since the refresh: zero on the refresh day, positive after it, negative when the date is in the future, and absent when the policy carries no `refreshed_on`.

The `policy-age` check warns when the policy is more than 60 days old, or when a non-bundled policy has no `refreshed_on` date, so a stopped CREED refresh is visible instead of silent.

## The CREED reader copy

PROCTOR carries a generated copy of the CREED reader under `src/Creed/` (namespace `Waynestate\Proctor\Creed`), so it never depends on the CREED package at runtime. `composer creed-copy:check` verifies the copy against the CREED checkout named by `CREED_PATH` (default `../creed`); refresh it by running CREED's `bin/export.php` against `src/Creed/`.

## Related

- [Overrides](overrides.md)
- [Formats](formats.md)
- [GitHub code scanning](github-code-scanning.md)
- [Rule ids](../reference/rule-ids.md)
- [Exit codes](../reference/exit-codes.md)
