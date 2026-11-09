---
title: "Use PROCTOR from your coding agent (ADVISOR)"
description: "Install the ADVISOR module so a coding agent can ask PROCTOR which rules exist and which are in the deploy gate, and run PROCTOR's file checks on an app folder in-process, with the two read-only tools proctor_rules and proctor_check."
---

# Use PROCTOR from your coding agent (ADVISOR)

This page is for developers whose coding agent runs [ADVISOR](https://github.com/waynestate/advisor), the stack's
read-only MCP server. PROCTOR ships an ADVISOR module, so the agent can ask PROCTOR two questions without leaving its
sandbox: "which rules do you check, and which ones block a deploy?" and "what do you say about this app right now?"
Both answers come from PROCTOR's own classes. Next, see [Checks](checks-overview.md) for every check in prose and
[Deploy gate](deploy-gate.md) for the deploy-time rule set.

## Install ADVISOR

In the app, install ADVISOR as a dev dependency:

```bash
composer require --dev waynestate/advisor
```

Then add one line to the app's `modules.json` (or point `ADVISOR_MODULES_FILE` at a file that holds it):

```json
{
    "modules": [
        "Waynestate\\Proctor\\Advisor\\ProctorModule"
    ]
}
```

PROCTOR does not require ADVISOR, and ADVISOR does not require PROCTOR: the module only loads when both are installed
and `modules.json` names it.

## The two read-only tools

Both tools run in-process: no subprocess, no network, nothing written, and neither reads `.env`. `proctor_rules` takes
no arguments. `proctor_check` takes exactly one, `path`: the app folder, inside the project root (default: the root
itself). A path outside the root, or one that is not a folder, is refused.

### proctor_rules

`proctor_rules` lists every check PROCTOR registers, in the order a report prints them, straight from `CheckSet`. It
returns `proctor_version` and `rules`, one entry per check:

- `id`: the check id, for example `php-floor`.
- `rule_id`: its rule id, `proctor/<id>`. A check may report named rules under it (`proctor/<id>/<rule>`); the
  [Rule ids](../reference/rule-ids.md) page lists them all.
- `in_gate`: `true` when `proctor gate` runs the check at deploy time.
- `docs`: the docs page that explains it.

One rule, as it comes back:

```json
{
    "id": "lock-committed",
    "rule_id": "proctor/lock-committed",
    "in_gate": true,
    "docs": "https://proctor.apps.wayne.edu/docs/guides/check-composer.html#lock-committed"
}
```

### proctor_check

`proctor_check` runs PROCTOR's checks on the app folder with PROCTOR's own `CheckRunner`, the way `proctor check` runs
them by default: the CREED policy PROCTOR bundles, today's date and the app's `policy-overrides.json` when it has one.
It returns:

- `findings`: each with `rule_id`, `status` (`pass`, `info`, `warning` or `fail`), `message`, `file` and `line`
  (`null` when the finding is about the app as a whole). Overrides are already applied, as in a report.
- `counts`: the number of findings per status.
- `verdict`: `fail` when any finding fails, else `pass`.
- `skipped`: the checks it left out, each with `id` and `reason` (see below).
- `ran`, `app` and `source`: what ran, on which folder (relative to the root), against which policy and for which date.

A missing or malformed `composer.json` or `policy-overrides.json` comes back as a tool error with PROCTOR's own
message, the same one `proctor check` prints before it exits 2.

## What is skipped, and why

Some checks cannot run inside ADVISOR's sandbox, so `proctor_check` leaves them out and lists them under `skipped`:

| Check | Reason | What it would run |
|---|---|---|
| `composer-audit` | `starts a subprocess` | `composer audit` |
| `composer-version` | `starts a subprocess` | `composer --version` |
| `instant-client-committed` | `starts a subprocess` | git, to see what is committed |
| `wayneui-forked-views` | `starts a subprocess` | `git ls-files` |
| `wayneui-outdated` | `starts a subprocess` | `composer show` (which asks Packagist) and git |
| `node-eol`, `env-outside-config`, `raw-identity-header`, `robots-txt`, `served-folder`, `world-writable` | `starts a subprocess` | `git ls-files`, to grade only committed files |
| `override-approver` | `starts a subprocess` | `git blame`, to see who wrote each override |
| `app-key` | `reads .env` | reads committed env files for an `APP_KEY` |

`mysql-eol` does run: it reads `.env.example` and `.env.squire.example`, the committed placeholder files, never `.env`.
An override in `policy-overrides.json` for a skipped check is not reported as unused, since its check did not run.

So a `pass` from `proctor_check` is a partial pass. Run `vendor/bin/proctor check` for every check, or ADVISOR's own
`run_proctor_check` tool, which starts PROCTOR as a subprocess under ADVISOR's allowlist.

> [!NOTE]
> The agent reports; PROCTOR decides. Every answer names its source and says plainly what ran and what did not, and
> the run in CI is the one that passes or fails a build. A practice paper is a fine way to study, but it is not the
> exam.

## Related

- [Checks](checks-overview.md)
- [Deploy gate](deploy-gate.md)
- [Rule ids](../reference/rule-ids.md)
- [Overrides](overrides.md)
