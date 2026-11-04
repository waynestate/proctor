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

### `proctor check` [#](https://proctor.apps.wayne.edu/docs/reference/commands.html#proctor-check)

Measure a PHP app against the version policy.

Usage: `proctor check [--path PATH] [--format FORMAT] [--policy POLICY] [--today TODAY] [--php-runtime PHP-RUNTIME] [--timeout TIMEOUT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--path=VALUE` | Path to the application to check. | `.` |
| `--format=VALUE` | Output format (text, json, codequality, sarif). | `text` |
| `--policy=VALUE` | Path to the CREED-format policy file. Without it, PROCTOR checks the PROCTOR_POLICY environment variable, then the app's vendor/waynestate/creed/policy/policy.json, then its own bundled copy. |  |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--php-runtime=VALUE` | The PHP version that will serve the app (X.Y.Z), for the unpatched check. |  |
| `--timeout=VALUE` | Maximum seconds for composer audit before it is killed. | `300` |
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
- `--policy`: path to the CREED-format policy file. Optional: without it, PROCTOR resolves the policy from the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json`, then the copy bundled with PROCTOR (see [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html)).
- `--today`: override today's date (YYYY-MM-DD) so runs are deterministic.
- `--php-runtime`: the PHP version that will serve the app (X.Y.Z), used by the `unpatched` check. CI should pass the deploy image's PHP, not the runner's.

## Related[#](https://proctor.apps.wayne.edu/docs/reference/commands.html#related)

- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
- [Troubleshooting](https://proctor.apps.wayne.edu/docs/troubleshooting.html)
- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html)
