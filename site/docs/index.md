---
title: "PROCTOR | PROCTOR docs"
description: "A framework-free PHP CLI that measures a PHP app against the WSU version policy."
url: https://proctor.apps.wayne.edu/docs/
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# PROCTOR[#](https://proctor.apps.wayne.edu/docs/#proctor)

This page says what PROCTOR is and how to run it.

> **Policy Rules Of Code, Tested On Release.** The invigilator your composer.lock didn't know it needed: no phones, no EOL PHP.

Checks: `composer ci` (the same command runs on GitLab and GitHub). Front page: [`site/index.html`](https://github.com/waynestate/proctor/blob/main/site/index.html). On Citadel it is served by the raw-PHP deploy layer in [`deploy/`](https://github.com/waynestate/proctor/tree/main/deploy): a Squire kit whose front controller serves `site/` behind HELMET's security headers. For local development, keep serving `site/` directly.

PROCTOR is a framework-free PHP CLI that measures a PHP application against the WSU version policy. It reads the app's composer.json and composer.lock, decides the support state of the app's PHP floor from a policy file, and reports each check as pass, warning, info or fail. The checks and their rules come from the stack's version policy decision and from the policy file passed with `--policy`; PROCTOR itself holds no policy of its own.

> **Not to be confused with:** code style is Pinstripe, CI is Preflight. PROCTOR grades an app against CREED's rules.

![Nine real lines from PROCTOR's text report, one per check shown: FAIL php-floor, WARN unpatched, FAIL unbounded-constraints, PASS lock-committed, WARN portability, WARN mysql56, WARN ci-pipelines, PASS composer-audit, FAIL overrides. Each line is written in words; color only repeats what the word says.](https://proctor.apps.wayne.edu/docs/readme/report.svg)

Nine of the seventeen checks shown, asked in this order on every run; \`policy-age\` stays silent while the policy is fresh. The lines are real output from the test fixtures.

## Where the code lives[#](https://proctor.apps.wayne.edu/docs/#where-the-code-lives)

The canonical repository is on nougat: [enterprise-apps/proctor](https://nougat.snu.gs/enterprise-apps/proctor). git.wayne.edu and GitHub ([waynestate/proctor](https://github.com/waynestate/proctor)) are read-only mirrors of it. Open issues and merge requests on nougat, not on the mirrors. This moves to git.wayne.edu once it has CI runners. Your proctor keeps one answer sheet, and this is it; the mirrors hold photocopies.

## Install[#](https://proctor.apps.wayne.edu/docs/#install)

```
composer require waynestate/proctor --dev
```

The command is then `vendor/bin/proctor`.

## Commands[#](https://proctor.apps.wayne.edu/docs/#commands)

| Command | What it does |
| --- | --- |
| `proctor check` | Measure a PHP app against the version policy. |

### `proctor check` [#](https://proctor.apps.wayne.edu/docs/#proctor-check)

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

### Options every command accepts[#](https://proctor.apps.wayne.edu/docs/#options-every-command-accepts)

| Option | Meaning | Default |
| --- | --- | --- |
| `-h`, `--help` | Display help for the given command. When no command is given display help for the list command |  |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |  |
| \`-v | -vv | -vvv`, `--verbose\` |
| `-V`, `--version` | Display this application version |  |
| `--ansi` | Force (or disable --no-ansi) ANSI output |  |
| `--no-ansi` | Negate the "--ansi" option |  |
| `-n`, `--no-interaction` | Do not ask any interactive question |  |

## Environment[#](https://proctor.apps.wayne.edu/docs/#environment)

| Variable | Meaning | Default | Read in |
| --- | --- | --- | --- |
| PROCTOR_POLICY | Path to a CREED-format policy file; tried before the app's vendor copy and the bundled copy when --policy is absent | none | src/Policy/PolicyResolver.php |

**Seated in the same hall**
WayneUI and the stack. Each one sits its own exam.

[![WayneUI](https://proctor.apps.wayne.edu/docs/readme/cast/wayneui.png)](https://github.com/waynestate/wayne-ui) [![CREED](https://proctor.apps.wayne.edu/docs/readme/cast/creed.png)](https://github.com/waynestate/creed) [![PROCTOR](https://proctor.apps.wayne.edu/docs/readme/cast/proctor.png)](https://github.com/waynestate/proctor) [![SESAME](https://proctor.apps.wayne.edu/docs/readme/cast/sesame.png)](https://github.com/waynestate/sesame) [![HELMET](https://proctor.apps.wayne.edu/docs/readme/cast/helmet.png)](https://github.com/waynestate/helmet) [![Sourdough](https://proctor.apps.wayne.edu/docs/readme/cast/sourdough.png)](https://github.com/waynestate/sourdough) [![Squire](https://proctor.apps.wayne.edu/docs/readme/cast/squire.png)](https://github.com/waynestate/squire) [![Preflight](https://proctor.apps.wayne.edu/docs/readme/cast/preflight.png)](https://github.com/waynestate/preflight) [![Pinstripe](https://proctor.apps.wayne.edu/docs/readme/cast/pinstripe.png)](https://github.com/waynestate/pinstripe) [![Booster](https://proctor.apps.wayne.edu/docs/readme/cast/booster.png)](https://github.com/waynestate/booster) [![Rollcall](https://proctor.apps.wayne.edu/docs/readme/cast/rollcall.png)](https://github.com/waynestate/rollcall) [![Drawbridge](https://proctor.apps.wayne.edu/docs/readme/cast/drawbridge.png)](https://github.com/waynestate/drawbridge) [![ADVISOR](https://proctor.apps.wayne.edu/docs/readme/cast/advisor.png)](https://github.com/waynestate/advisor) [![WELD](https://proctor.apps.wayne.edu/docs/readme/cast/weld.png)](https://github.com/waynestate/weld)

Images are regenerated from the front page by [`scripts/readme-art`](https://github.com/waynestate/proctor/tree/main/scripts/readme-art). Pencils down.

****The brand board**: the mark, lockups, palette and type**

![PROCTOR's brand board: the eye mark on a green tile, horizontal and stacked lockups, the palette of Wayne State green, gold, cream and the red pen, and the type.](https://proctor.apps.wayne.edu/docs/readme/board.jpg)
