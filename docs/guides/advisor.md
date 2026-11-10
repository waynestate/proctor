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

Both tools run in-process: no subprocess, no network, nothing written, and neither reads `.env` itself (four checks
that do run read other env files, and only the ones git lists; see
[What is skipped, and why](#what-is-skipped-and-why)). `proctor_rules` takes
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
- `skipped_files`: the env files a check that ran did not open because git does not list them, each with `id` (the
  check), `file` (relative to the app) and `reason` (see below). Empty when every env file on disk is tracked.
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
| `lock-tracked` | `starts a subprocess` | git, to see whether composer.lock is committed |
| `wayneui-forked-views` | `starts a subprocess` | `git ls-files` |
| `wayneui-outdated` | `starts a subprocess` | `composer show` (which asks Packagist) and git |
| `node-eol`, `env-outside-config`, `raw-identity-header`, `robots-txt`, `served-folder`, `world-writable` | `starts a subprocess` | `git ls-files`, to grade only committed files |
| `override-approver` | `starts a subprocess` | `git blame`, to see who wrote each override |
| `app-key`, `vite-secrets` | `reads .env` | reads committed env files for an `APP_KEY`, or for `VITE_` variables that look like secrets |

Four checks that do run read env files other than `.env`. Inside ADVISOR every one of them grades an env file only
when git lists it, the rule `app-key` and `vite-secrets` follow in a full run, so the `.env.production` on a
developer's laptop, which can hold real secrets, is never opened:

| Check | Files it reads, when git lists them | What it reads in them |
|---|---|---|
| `mysql-eol`, `mysql-minimum` | `.env.squire.example`, `.env.example` | the `SQUIRE_MYSQL_IMAGE=` line |
| `app-debug` | `.env.production`, `.env.squire.example` | the `APP_DEBUG` value |
| `csp-unsafe-inline` | `.env.example`, `.env.production`, `.env.squire.example` | the `HELMET_CSP=` lines |

To know what git lists without starting git, PROCTOR reads the repository's index file in PHP (the same list
`git ls-files` prints), from the first `.git` folder or `.git` file at or above the app folder. A file on disk that
the index does not list is not opened and comes back under `skipped_files`, with the reason `not tracked by git`, or
`no git index to read` when there is no repository or its index is in a shape PROCTOR does not read (a split index).
Add the file with `git add`, or run `vendor/bin/proctor check`, to have it graded.

None of them reads `.env`. A finding names the file and the line; the MySQL checks add the engine and version from
the image name, and no other value from these files comes back. `vendor/bin/proctor check` reads these files from disk
whether git lists them or not, as it always has: in CI the checkout holds only what the repository holds.
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
