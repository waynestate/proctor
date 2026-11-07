---
title: "Commands | PROCTOR docs"
description: "The PROCTOR commands and their options, starting with proctor check and the options every command accepts."
url: https://proctor.apps.wayne.edu/docs/reference/commands.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Commands[#](https://proctor.apps.wayne.edu/docs/reference/commands.html#commands)

Every command PROCTOR ships, with the options each one accepts. The table is generated from the CLI, so it stays in step with the code; a proctor reads the rules aloud before the exam, it does not take the exam for you. If you have not run a check yet, start with the [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html).

| Command | What it does |
| --- | --- |
| `proctor check` | Measure a PHP app against the version policy. |
| `proctor gate` | Decide go or no-go for a deploy: the deploy-time checks, ratcheted against a baseline. |

### `proctor check` [#](https://proctor.apps.wayne.edu/docs/reference/commands.html#proctor-check)

Measure a PHP app against the version policy.

Usage: `proctor check [--path PATH] [--format FORMAT] [--policy POLICY] [--today TODAY] [--php-runtime PHP-RUNTIME] [--timeout TIMEOUT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application to check. | `.` |
| `--format=VALUE` | Output format (text, json, codequality, sarif). | `text` |
| `--policy=VALUE` | Path to the CREED-format policy file. Without it, PROCTOR checks the PROCTOR_POLICY environment variable, then the app's vendor/waynestate/creed/policy/policy.json, then its own bundled copy. In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1. |  |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). In CI this option is refused unless PROCTOR_ALLOW_OVERRIDES=1. |  |
| `--php-runtime=VALUE` | The PHP version that will serve the app (X.Y.Z), for the unpatched check. |  |
| `--timeout=VALUE` | Maximum seconds for composer audit before it is killed. | `300` |
| `--silent` | Do not output any message |  |

### `proctor gate` [#](https://proctor.apps.wayne.edu/docs/reference/commands.html#proctor-gate)

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

### Options every command accepts[#](https://proctor.apps.wayne.edu/docs/reference/commands.html#options-every-command-accepts)

| Option | Meaning | Default |
| --- | --- | --- |
| `-h`, `--help` | Display help for the given command. When no command is given display help for the list command |  |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |  |
| \`-v | -vv | -vvv`, `--verbose\` |
| `-V`, `--version` | Display this application version |  |
| `--ansi` | Force (or disable --no-ansi) ANSI output |  |
| `--no-ansi` | Negate the "--ansi" option |  |
| `-n`, `--no-interaction` | Do not ask any interactive question |  |

## Examples[#](https://proctor.apps.wayne.edu/docs/reference/commands.html#examples)

Run the check against an application:

```
vendor/bin/proctor check [--path=.] [--format=text|json|codequality|sarif] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z]
```

- `--path`: path to the application to check (default `.`).
- `--format`: output format: `text` (default), `json`, `codequality`, or `sarif`.
- `--policy`: path to the CREED-format policy file. Optional: without it, PROCTOR resolves the policy from the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json`, then the copy bundled with PROCTOR (see [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html)). In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--today`: override today's date (YYYY-MM-DD) so runs are deterministic. In CI this option is refused unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`.
- `--php-runtime`: the PHP version that will serve the app (X.Y.Z), used by the `unpatched` check. CI should pass the deploy image's PHP, not the runner's.

Ask for a go or no-go before a deploy, against the verdict recorded last time:

```
vendor/bin/proctor gate --baseline=storage/proctor-gate.json --record=storage/proctor-gate.json
```

- `--baseline`: what the running deployment already fails: a PROCTOR JSON report or the running source directory. Without it every failure blocks, as for a first deploy.
- `--record`: also write the JSON verdict to this file, for the deploy log and the next run's `--baseline`.

The gate runs eight deploy-time checks and exits 0 (go), 1 (no-go) or 2 (could not decide); see [Deploy gate](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html).

## Related[#](https://proctor.apps.wayne.edu/docs/reference/commands.html#related)

- [Deploy gate](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html)
- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
- [Troubleshooting](https://proctor.apps.wayne.edu/docs/troubleshooting.html)
- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html)
