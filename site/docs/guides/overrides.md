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
- An override has a maximum duration: an entry whose expiry lies more than `rules.override_max_days` days after `--today` (90 days when the key is absent) fails the `overrides` check (`proctor/overrides/too-long`) and is not applied. The cap exists because an approval is a dated exception, not a standing one, and CREED allows no permanent override.
- An override never waives a failure whose state is EOL plus grace (`eol_grace_expired`); that failure stands (the stack's version policy decision).
- PROCTOR rejects the file (exit 2) when an entry names a check that is not registered in the run, or names the `overrides` or `override-approver` check: an override cannot exempt the checks that police overrides, and an entry for a check that does not exist would silently do nothing.
- In `proctor check`, the `override-approver` check flags an entry approved by someone who wrote it (see [Someone else signs the note](https://proctor.apps.wayne.edu/docs/guides/overrides.html#someone-else-signs-the-note)).
- The list of valid check ids is built from the checks PROCTOR runs, so a new check needs no change to the guard.
- An active override that expires within 14 days of `--today` (the expiry day counts) gets a warning finding (`proctor/overrides/expiring`) instead of lapsing without a trace, and an active override whose check raised no failure gets a warning finding (`proctor/overrides/unused`) so a stale approval is removed instead of rotting.
- A warning never changes the exit code.
- In `proctor gate`, an expired override blocks the deploy whatever the baseline says, even when its check is not one the gate runs (see [Deploy gate](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html)).
- When the app has no `policy-overrides.json`, the check produces no findings at all: it passes and prints nothing. Every override, active or expired, is printed as an OVERRIDE line by the CLI in addition to these findings.

Warning

An expired override fails the run. Renew it or delete it before the expiry date.

## Someone else signs the note[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#someone-else-signs-the-note)

`approved_by` names the person who approved the exception, and that person must not be the one who wrote it. A note from home signed by the candidate is not a note from home. `proctor check` holds that line with the `override-approver` check:

- For each entry, git blame names who last wrote each of its lines, ignoring whitespace changes and following lines moved within the file. Only read-only git commands run.
- The entry is self-approved when `approved_by` names one of those authors: the git author name, the author email, the email's local part, or the username in a GitLab (`123-name@users.noreply.<host>`) or GitHub noreply address. The comparison is trimmed and case-insensitive, and a leading `@` and the `Name <email>` form are understood.
- A line that holds only `approved_by` is the approver's signature, so its own author does not count. A reviewer may write their name into someone else's entry; they may not approve an entry they wrote, or an extension of their own approval (moving `expires` makes you an author of the entry). Keep `approved_by` on a line of its own, as pretty-printed JSON does, so a signature stays a signature.
- Uncommitted lines count as written by the author of the next commit (`git var GIT_AUTHOR_IDENT`), so a local run warns before you commit.
- With squash merges, GitLab credits the squashed commit to the merge request's author, so every line of the entry is theirs. A reviewer who signs inside that merge request does not become an author, and a merge request author who names themselves is still caught.
- A self-approval is `proctor/override-approver/self-approved`, on the entry's first line. When every entry checks out, one pass line says how many were checked.
- The check never passes on a guess. When git is not on PATH, the app path is not inside a git checkout, or git cannot answer, it reports `proctor/override-approver/could-not-run` with a message that starts `not checked:`.
- In a shallow clone, git credits every line older than the clone to the clone's oldest commit, whoever wrote it. An entry with such a line is `could-not-run` too, unless a self-approval inside the clone's history is already proven. GitLab clones shallow by default (`GIT_DEPTH`), so set `GIT_DEPTH: 0` on the job that runs `proctor check`.
- CREED's `rules.override_self_approval` sets the status of both findings: `warn`, the default and the value when the key is absent, or `fail`. Under `fail`, an approval PROCTOR cannot check fails too, because a strict rule that a shallow clone could dodge is no rule. Any other value is a tool error (exit 2).
- No override can target `override-approver`, and none downgrades its failure: an author could otherwise approve their own waiver.
- `proctor gate` does not run it; see [Deploy gate](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html) for why.

A self-approved entry in the text report:

```
WARN override-approver: override for "lock-committed" is approved by "Avery Author", who also wrote it (commit 1a2b3c4d); someone who did not write the entry must approve it
```

### What makes an approval real[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#what-makes-an-approval-real)

git.wayne.edu runs GitLab Community Edition, which has no Code Owner approval rules, so no GitLab setting can require that the named person approves the file. The human control is the merge request:

1. Protect the default branch so no one pushes to it directly. Every change, including one to `policy-overrides.json`, then arrives in a merge request.
2. The person named in `approved_by` reviews that merge request and approves it. The Approve button exists in every GitLab tier, but Community Edition does not make it required, so the person who merges checks that the named approver approved.
3. Write `approved_by` the way git knows the approver, ideally their WSU email, so PROCTOR can compare it.

PROCTOR's check is the tripwire, not the control. It catches the common slip, writing your own name, and an approver extending their own approval. It cannot catch a name that is not the author's but never approved either, a different spelling of the same person, or a git identity typed as someone else's: `git config user.name` is whatever the committer set. The proctor reads the signature on the note; whether it was forged is a question for the merge request.

A bot never approves. When a bot writes an entry, the bot is its author and a person still signs it; a whitespace-only reformat by a bot changes no authorship, because whitespace changes are ignored.

## When the audit cannot run[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#when-the-audit-cannot-run)

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

## Backing an audit ignore entry[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#backing-an-audit-ignore-entry)

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

## Verify the date window[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#verify-the-date-window)

Use `--today=YYYY-MM-DD` to make the 14-day warning window deterministic. For example, an entry that expires on the same day as `--today` is still inside the window, because the expiry day counts. The same flag makes the maximum-duration boundary deterministic: an entry whose expiry lies exactly `override_max_days` days after `--today` still applies, and one day more fails with `proctor/overrides/too-long`.

Run the check against the app path:

```bash
vendor/bin/proctor check --path=. --today=YYYY-MM-DD
```

## Rule ids[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#rule-ids)

| Check | Rule id |
| --- | --- |
| `overrides` | `proctor/overrides` |
| `overrides` | `proctor/overrides/expiring` |
| `overrides` | `proctor/overrides/too-long` |
| `overrides` | `proctor/overrides/unused` |
| `override-approver` | `proctor/override-approver` |
| `override-approver` | `proctor/override-approver/self-approved` |
| `override-approver` | `proctor/override-approver/could-not-run` |

## Related[#](https://proctor.apps.wayne.edu/docs/guides/overrides.html#related)

- [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html)
- [WayneUI checks](https://proctor.apps.wayne.edu/docs/guides/check-wayneui.html)
