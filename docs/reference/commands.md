---
title: Commands
description: The PROCTOR commands and their options, starting with proctor check and the options every command accepts.
---

# Commands

Every command PROCTOR ships, with the options each one accepts. The table is generated from the CLI, so it stays in step with the code; a proctor reads the rules aloud before the exam, it does not take the exam for you. If you have not run a check yet, start with the [Quick start](../guides/quick-start.md).

<!-- generated:commands -->
| Command | What it does |
| --- | --- |
| `proctor check` | Measure a PHP app against the version policy. |
| `proctor gate` | Decide go or no-go for a deploy: the deploy-time checks, ratcheted against a baseline. |

### `proctor check`

Measure a PHP app against the version policy.

Usage: `proctor check [--path PATH] [--format FORMAT] [--record RECORD] [--policy POLICY] [--today TODAY] [--php-runtime PHP-RUNTIME] [--timeout TIMEOUT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application to check. | `.` |
| `--format=VALUE` | Output format (text, json, codequality, sarif). | `text` |
| `--record=VALUE` | Also write the JSON report (what --format=json prints) to this file (its folder is created), whatever --format says. A CI job keeps it as an artifact. |  |
| `--policy=VALUE` | Path to the CREED-format policy file. Without it, PROCTOR checks the PROCTOR_POLICY environment variable, then the app's vendor/waynestate/creed/policy/policy.json, then its own bundled copy. In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1. |  |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1. |  |
| `--php-runtime=VALUE` | The PHP version that will serve the app (X.Y.Z), for the unpatched check. |  |
| `--timeout=VALUE` | Maximum seconds for composer audit before it is killed. | `300` |
| `--silent` | Do not output any message |  |

### `proctor gate`

Decide go or no-go for a deploy: the deploy-time checks, ratcheted against a baseline.

Usage: `proctor gate [--path PATH] [--format FORMAT] [--baseline BASELINE] [--record RECORD] [--policy POLICY] [--today TODAY] [--php-runtime PHP-RUNTIME] [--timeout TIMEOUT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application to deploy. | `.` |
| `--format=VALUE` | Output format (text, json). | `text` |
| `--baseline=VALUE` | What the running deployment already fails: a PROCTOR JSON report (the file --record writes) or the running source directory. Without it every failure blocks, as for a first deploy. |  |
| `--record=VALUE` | Also write the JSON verdict to this file (its folder is created), whatever --format says. A later run can read it with --baseline. |  |
| `--policy=VALUE` | Path to the CREED-format policy file. Without it, PROCTOR checks the PROCTOR_POLICY environment variable, then the app's vendor/waynestate/creed/policy/policy.json, then its own bundled copy. In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1. |  |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1. |  |
| `--php-runtime=VALUE` | The PHP version that will serve the app (X.Y.Z), for the unpatched check. |  |
| `--timeout=VALUE` | Maximum seconds for composer audit before it is killed. | `60` |
| `--silent` | Do not output any message |  |

### Options every command accepts

| Option | Meaning | Default |
| --- | --- | --- |
| `-h`, `--help` | Display help for the given command. When no command is given display help for the list command |  |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |  |
| `-v|-vv|-vvv`, `--verbose` | Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug |  |
| `-V`, `--version` | Display this application version |  |
| `--ansi` | Force (or disable --no-ansi) ANSI output |  |
| `--no-ansi` | Negate the "--ansi" option |  |
| `-n`, `--no-interaction` | Do not ask any interactive question |  |
<!-- /generated -->

## Examples

Run the check against an application:

```
vendor/bin/proctor check [--path=.] [--format=text|json|codequality|sarif] [--record=<file>] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z] [--timeout=300]
```

- `--path`: path to the application to check (default `.`).
- `--format`: output format: `text` (default), `json`, `codequality`, or `sarif`.
- `--record`: also write the JSON report to this file, byte for byte what `--format=json` prints, whatever `--format` says; its folder is created, and a relative path is relative to the current directory, not `--path`. The exit code is the check's own. A file it cannot write is a tool error (exit 2, never a pass), and a run that stops on a tool error records the JSON error report. CI keeps the file as a job artifact for Rollcall (see [CI checks](../guides/check-ci.md#run-proctor-in-gitlab-ci)).
- `--policy`: path to the CREED-format policy file. Optional: without it, PROCTOR resolves the policy from the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json`, then the copy bundled with PROCTOR (see [Policy](../guides/policy.md)). In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--today`: override today's date (YYYY-MM-DD) so runs are deterministic. In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--php-runtime`: the PHP version that will serve the app (X.Y.Z), used by the `unpatched` check. CI should pass the deploy image's PHP, not the runner's.
- `--timeout`: seconds `composer audit` may run before it is killed (default 300). `proctor gate` defaults to 60.

Ask for a go or no-go before a deploy, against the verdict recorded last time:

```
vendor/bin/proctor gate --baseline=storage/proctor-gate.json --record=storage/proctor-gate.json
```

- `--baseline`: what the running deployment already fails: a PROCTOR JSON report or the running source directory. Without it every failure blocks, as for a first deploy.
- `--record`: also write the JSON verdict to this file, for the deploy log and the next run's `--baseline`.

The gate runs eight deploy-time checks and exits 0 (go), 1 (no-go) or 2 (could not decide); see [Deploy gate](../guides/deploy-gate.md).

## Related

- [Deploy gate](../guides/deploy-gate.md)

- [Exit codes](exit-codes.md)
- [Rule ids](rule-ids.md)
- [Troubleshooting](../troubleshooting.md)
- [Quick start](../guides/quick-start.md)
