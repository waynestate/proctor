---
title: "Quick start | PROCTOR docs"
description: "Run PROCTOR against an app and read its pass, warning, info or fail report."
url: https://proctor.apps.wayne.edu/docs/guides/quick-start.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://proctor.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

Pencils up. Five minutes, one command, a mark sheet: install PROCTOR, run the first check, read what the proctor saw.

1. Add PROCTOR to the app as a dev dependency.
   ```
   composer require waynestate/proctor --dev
   ```
   Expected: Composer installs it, and the command is then `vendor/bin/proctor`.
2. Run the first check.
   ```
   vendor/bin/proctor check
   ```
   Expected: the text report. A header line with the tool and the composer version, a line naming the policy in use, one line per finding written in words, then a RESULT line. Exit 0 when nothing failed (warnings allowed), 1 when something did.
   ![Nine real lines from PROCTOR's text report, one per check, each written in words](https://proctor.apps.wayne.edu/docs/readme/report.svg)
3. Read the mark sheet.
   Every finding carries a stable, namespaced rule id: `proctor/<check-id>` for a check's default finding, `proctor/<check-id>/<rule>` for a named rule. A FAIL is a resit; a WARN is a note in the margin; an INFO is the proctor thinking out loud.
4. Make the run deterministic.
   ```
   vendor/bin/proctor check --today=2026-01-01 --php-runtime=8.4.0
   ```
   Expected: the same marks on every run. `--today` overrides today's date so runs are deterministic, and `--php-runtime` tells the `unpatched` check the PHP version that will serve the app. CI should pass the deploy image's PHP, not the runner's.
5. Emit a machine-readable mark sheet (optional).
   ```
   vendor/bin/proctor check --format=json
   ```
   Use `--format=codequality` for GitLab's Code Quality JSON, or `--format=sarif` for a SARIF 2.1.0 log that GitHub code scanning can read.
6. Name the policy explicitly (optional).
   ```
   vendor/bin/proctor check --policy=policy.json
   ```
   Without `--policy`, PROCTOR resolves the policy in this order: the `PROCTOR_POLICY` environment variable, the app's `vendor/waynestate/creed/policy/policy.json`, then the copy bundled with PROCTOR.

## What you built[#](https://proctor.apps.wayne.edu/docs/guides/quick-start.html#what-you-built)

You ran PROCTOR's first marking pass: a verdict per check against the CREED policy, with an exit code CI can gate on. Nothing in the app changed; the proctor only watches.

## Next steps[#](https://proctor.apps.wayne.edu/docs/guides/quick-start.html#next-steps)

- [Install](https://proctor.apps.wayne.edu/docs/install.html): requirements and the single-file PHAR route for apps without Composer access.
- [Reference: commands](https://proctor.apps.wayne.edu/docs/reference/commands.html): the long-form docs for the commands, [rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html) and [exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html).
- When you are ready for CI, the README's GitHub code scanning section shows a workflow that runs the check with `--format=sarif` and uploads the log to code scanning.
