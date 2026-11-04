---
title: Overrides
description: How an app carries a policy-overrides.json file to waive a check's fail findings until an expiry date.
---

# Overrides

This page explains how an app carries a `policy-overrides.json` file next to its composer.json to waive a check's fail findings until an expiry date. Add an entry for a temporary exception, keep it dated, and remove it before it expires. An approval, like an exam excuse, needs a date.

## How overrides work

- An expired override fails the `overrides` check.
- An active override turns the check's fail findings into warnings.
- An override never waives a failure whose state is EOL plus grace (`eol_grace_expired`); that failure stands (the stack's version policy decision).
- PROCTOR rejects the file (exit 2) when an entry names a check that is not registered in the run, or names the `overrides` check itself: an override cannot exempt the check that polices overrides, and an entry for a check that does not exist would silently do nothing.
- The list of valid check ids is built from the checks PROCTOR runs, so a new check needs no change to the guard.
- An active override that expires within 14 days of `--today` (the expiry day counts) gets a warning finding (`proctor/overrides/expiring`) instead of lapsing without a trace, and an active override whose check raised no failure gets a warning finding (`proctor/overrides/unused`) so a stale approval is removed instead of rotting.
- A warning never changes the exit code.
- When the app has no `policy-overrides.json`, the check produces no findings at all: it passes and prints nothing. Every override, active or expired, is printed as an OVERRIDE line by the CLI in addition to these findings.

> [!WARNING]
> An expired override fails the run. Renew it or delete it before the expiry date.

## When the audit cannot run

`composer-audit` fails (`proctor/composer-audit/skipped`) with a message that starts with `not audited:` in two cases: the composer executable is not on PATH, and the app path has no composer.lock. Until Composer is installed or the lock is committed, an active `composer-audit` entry downgrades those fails to warnings:

```json
[
    {
        "check": "composer-audit",
        "reason": "no lock yet, ticket in progress",
        "expires": "2026-12-01",
        "approved_by": "tester"
    }
]
```

An entry needs a `check`, a `reason`, an `expires` date and an `approved_by`; a file missing any of them is rejected (exit 2). Remove the entry when the audit can run again, so the approval does not rot.

## Backing an audit ignore entry

`composer-audit` fails each ignore entry in composer.json that no active `composer-audit` override backs (`proctor/composer-audit/unbacked-ignore`), because an ignore without a dated approval never expires. An override backs the entry when its `advisory` field equals the entry's advisory id case-insensitively, its `package` field matches the entry's package the way composer matches patterns, or it carries no scope at all; an entry that names neither an id nor a package (an ignore-severity level) is backed by an unscoped override only. An override that backs an entry is not reported unused. To back an `ignore-id` entry for CVE-2024-52301:

```json
[
    {
        "check": "composer-audit",
        "advisory": "CVE-2024-52301",
        "reason": "patch lands with the next framework release",
        "expires": "2026-12-01",
        "approved_by": "tester"
    }
]
```

Remove the ignore entry from composer.json when the override expires, so the approval and the silence end on the same date.

## Verify the date window

Use `--today=YYYY-MM-DD` to make the 14-day warning window deterministic. For example, an entry that expires on the same day as `--today` is still inside the window, because the expiry day counts.

Run the check against the app path:

```bash
vendor/bin/proctor check --path=. --today=YYYY-MM-DD
```

## Rule ids

| Check | Rule id |
| --- | --- |
| `overrides` | `proctor/overrides` |
| `overrides` | `proctor/overrides/expiring` |
| `overrides` | `proctor/overrides/unused` |

## Related

- [CI checks](check-ci.md)
- [WayneUI checks](check-wayneui.md)
