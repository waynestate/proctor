---
title: "Deploy gate | PROCTOR docs"
description: "How proctor gate decides go or no-go for a deploy: the deploy-time checks, the ratchet against a baseline, overrides, the record file and the exit codes."
url: https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Deploy gate [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#deploy-gate)

`proctor gate` answers one question for a release script or a platform hook: may this commit be deployed? It runs the small, deploy-time part of PROCTOR's checks, compares the failures with what the running deployment already fails, and exits 0 for go or 1 for no-go. `proctor check` marks the whole paper; the gate is the proctor at the exam-hall door, deciding only whether you may sit.

It follows CREED's deploy-gate chapter (the stack's deploy-gate decision): the rule set is small, offline-tolerant and fast, and it ratchets, so a deploy that does not make things worse always passes.

## Run it [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#run-it)

```
vendor/bin/proctor gate [--path=.] [--format=text|json] [--baseline=<report or directory>] [--record=<file>] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z] [--timeout=60]
```

- `--baseline`: what the running deployment already fails. Either a PROCTOR JSON report (the verdict `gate --record` wrote last time, or a `proctor check` report from `--format=json` or `check --record`) or the running deployment's source directory, which the gate checks with the same rules. Leave it out for a first deploy.
- `--record`: also write the JSON verdict to this file, whatever `--format` says; its folder is created. Next time, pass the same file as `--baseline`. This is the gate's verdict (shape below), not the check report `proctor check --record` writes; Rollcall reads the check report, so do not point it at this file.
- `--format`: `text` (default), a short verdict for the deploy log, or `json`.
- `--policy`, `--today`, `--php-runtime`: as for `proctor check`, including the CI guard on `--policy` and `--today`.
- `--timeout`: seconds for `composer audit` before it is killed; 60 by default here, because a deploy should not wait five minutes.

## What it runs [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#what-it-runs)

Eight of the checks, the deploy-time rule set: `php-floor`, `unpatched`, `laravel-eol`, `laravel-php`, `lock-committed`, `composer-audit`, `overrides` and `policy-age`. That covers the PHP floor and the runtime, the framework line and the PHP range it supports, the lock file, known advisories, and the overrides themselves. Style, portability, MySQL 5.6 safety, CI pipelines and the rest stay in `proctor check`, in CI.

`override-approver`, the check that flags an override approved by its own author, stays out of the gate on purpose:

- It reads git history. A deploy runs from a release, often an export with no `.git` or a shallow one, so at the door it would mostly say `not checked:`.
- The gate is offline-tolerant and fast, and blame walks history.
- Who approved an override is settled when the override reaches the default branch: in CI, on the merge request that added it. Nothing at deploy time changes that answer, while the expiry, which the gate does check, changes every day.
- On Citadel, the platform's own overrides are held by its admins, with their own approver.

## How it decides [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#how-it-decides)

The app's overrides are applied first, exactly as in `proctor check`. Then each failure is one of these:

| Outcome | When | Effect |
| --- | --- | --- |
| `blocked` (`new-app`) | there is no baseline | no-go |
| `blocked` (`regression`) | the baseline passed that rule | no-go |
| `blocked` (`expired-override`) | an override in policy-overrides.json has expired, for any check | no-go, whatever the baseline says |
| `held` | the baseline failed the same rule id too | go: the ratchet lets an existing violation through, so the owners of an EOL app can still ship the fix |
| `waived` | composer-audit could not get advisory data (`proctor/composer-audit/skipped` or `could-not-run`) | go: missing advisory data only warns at deploy time |
| `staged` | the failure would block, but CREED's policy stages its check as `report` or `warn` today (the `gate` block, ADR-0006 item 4) | go: logged as `STAGE`, and the JSON finding carries `stage` and the `reason` it would have blocked for |

CREED's `gate` block ships empty, so today every check blocks; a stage date is agreed with the Citadel team first. A staged failure is still recorded as a fail, so once its check reaches `block`, the ratchet holds it like any other existing violation, and only a new failure blocks; `proctor check` in CI fails it all along.

Warnings and info notes never change the verdict. The comparison is by rule id, so a rule that already failed on the baseline is held even if it got worse within the rule; `proctor check` in CI still fails it. The unused-override warning is left to `proctor check`, because the gate runs only some checks and would call an override for a skipped check unused.

Note

An override names any registered check, not only the eight above. An expired one blocks the deploy even when its check is not part of the gate.

## Exit codes [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#exit-codes)

| Exit code | Meaning |
| --- | --- |
| `0` | go: nothing blocked (held, waived and warnings allowed) |
| `1` | no-go: at least one failure is blocked |
| `2` | the gate could not decide (bad options, an unreadable policy, a malformed overrides file, a missing or malformed baseline, or a record file it could not write) |

A platform hook should fail open on 2, with an alert: a bug in the gate must never take down deploys (the stack's deploy-gate decision). A release script may choose to stop on it instead.

## The verdict [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#the-verdict)

The text verdict leads with the answer and lists only what is not a pass:

```
proctor 0.15.1 gate: NO-GO
Policy: vendor (vendor/waynestate/creed/policy/policy.json), refreshed 2026-10-20, 7 days ago
Baseline: none, so this is a first deploy and every failure blocks
Checks: php-floor, unpatched, laravel-eol, laravel-php, lock-committed, composer-audit, overrides, policy-age
BLOCK php-floor: php floor 7.4 is eol_grace_expired [new app: no baseline, so every failure blocks]
RESULT: no-go (1 blocked, 0 held, 0 waived, 0 warnings, 3 passed)
```

The JSON verdict carries `command` (`gate`), `verdict` (`go`, `no-go`, or `error` when it could not decide), `exit`, `policy`, `baseline` (its source, path and failing rule ids, or null), `checks`, a `summary` with the count of each outcome, `findings` and `overrides`. Each finding keeps the check's own `status` and adds `gate` (its outcome), `reason` when blocked or staged, and `stage` when staged. Because the status is kept, a recorded verdict works as the next run's baseline.

## In a release [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#in-a-release)

Squire's release script runs the gate when PROCTOR is installed, records the verdict, and keeps the last accepted verdict as the next baseline. On Citadel, the gate is meant to run in `runGitPull()`, inside the app's image, before the fetched commit is checked out; that hook waits on the Citadel team.

## Related [#](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html#related)

- [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)
- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Commands](https://proctor.apps.wayne.edu/docs/reference/commands.html)
- [Formats](https://proctor.apps.wayne.edu/docs/guides/formats.html)
