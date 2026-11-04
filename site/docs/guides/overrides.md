---
title: "Overrides | PROCTOR docs"
description: "How an app carries a policy-overrides.json file to waive a check's fail findings until an expiry date."
url: https://proctor.apps.wayne.edu/docs/guides/overrides.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Overrides[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#overrides)

This page explains how an app carries a `policy-overrides.json` file next to its composer.json to waive a check's fail findings until an expiry date. Add an entry for a temporary exception, keep it dated, and remove it before it expires. An approval, like an exam excuse, needs a date.

## How overrides work[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#how-overrides-work)

- An expired override fails the `overrides` check.
- An active override turns the check's fail findings into warnings.
- An override never waives a failure whose state is EOL plus grace (`eol_grace_expired`); that failure stands (the stack's version policy decision).
- PROCTOR rejects the file (exit 2) when an entry names a check that is not registered in the run, or names the `overrides` check itself: an override cannot exempt the check that polices overrides, and an entry for a check that does not exist would silently do nothing.
- The list of valid check ids is built from the checks PROCTOR runs, so a new check needs no change to the guard.
- An active override that expires within 14 days of `--today` (the expiry day counts) gets a warning finding (`proctor/overrides/expiring`) instead of lapsing without a trace, and an active override whose check raised no failure gets a warning finding (`proctor/overrides/unused`) so a stale approval is removed instead of rotting.
- A warning never changes the exit code.
- When the app has no `policy-overrides.json`, the check produces no findings at all: it passes and prints nothing. Every override, active or expired, is printed as an OVERRIDE line by the CLI in addition to these findings.

Warning

An expired override fails the run. Renew it or delete it before the expiry date.

## Verify the date window[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#verify-the-date-window)

Use `--today=YYYY-MM-DD` to make the 14-day warning window deterministic. For example, an entry that expires on the same day as `--today` is still inside the window, because the expiry day counts.

Run the check against the app path:

```bash
vendor/bin/proctor check --path=. --today=YYYY-MM-DD
```

## Rule ids[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#rule-ids)

| Check | Rule id |
| --- | --- |
| `overrides` | `proctor/overrides` |
| `overrides` | `proctor/overrides/expiring` |
| `overrides` | `proctor/overrides/unused` |

## Related[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#related)

- [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html)
- [WayneUI checks](https://proctor.apps.wayne.edu/docs/guides/check-wayneui.html)
