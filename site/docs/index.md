---
title: "Overview | PROCTOR docs"
description: "A framework-free PHP CLI that measures a PHP app against the WSU version policy."
url: https://proctor.apps.wayne.edu/docs/
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Overview [#](https://proctor.apps.wayne.edu/docs/#overview)

![PROCTOR's front page: a green examination book sealed with PROCTOR's eye mark, beside the headline 'Watches the exam. Doesn't help.'](https://proctor.apps.wayne.edu/docs/readme/hero.jpg)

PROCTOR: Policy Rules Of Code, Tested On Release. It is the proctor your composer.lock didn't know it needed: a framework-free PHP CLI that measures a PHP application against the WSU version policy. No phones, no EOL PHP. It reads the app's composer.json and composer.lock, decides the support state of the app's PHP floor from a policy file, and reports each check as pass, warning, info or fail. The checks and their rules come from the stack's version policy decision and from the policy file passed with `--policy`; PROCTOR itself holds no policy of its own.

Note

**Not to be confused with:** code style is Pinstripe, CI is Preflight. PROCTOR grades an app against CREED's rules.

## Install and first run [#](https://proctor.apps.wayne.edu/docs/#install-and-first-run)

Add PROCTOR to the app as a dev dependency:

```
composer require waynestate/proctor --dev
```

The command is then `vendor/bin/proctor`. Run it against the app:

```
vendor/bin/proctor check [--path=.] [--format=text|json|codequality|sarif] [--record=<file>] [--policy=<file>] [--today=YYYY-MM-DD] [--php-runtime=X.Y.Z] [--timeout=300]
```

A clean hall exits 0 (warnings allowed), a resit exits 1, and a tripped proctor exits 2.

## Pick your path [#](https://proctor.apps.wayne.edu/docs/#pick-your-path)

- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html): install PROCTOR, run the first check and read the mark sheet.
- [Install](https://proctor.apps.wayne.edu/docs/install.html): requirements, the composer line and the single-file PHAR route.
- [Deploy gate](https://proctor.apps.wayne.edu/docs/guides/deploy-gate.html): `proctor gate` gives a release script a go or no-go, ratcheted against what is already running.
- Reference: the long-form docs for the [commands](https://proctor.apps.wayne.edu/docs/reference/commands.html), [rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html) and [exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html).

## Status [#](https://proctor.apps.wayne.edu/docs/#status)

The header names the release these pages document; [Upgrading](https://proctor.apps.wayne.edu/docs/upgrading.html) lists what changed at each break. Forty-two checks, eight of them in the deploy gate, four report formats and three exit codes. License: MIT.

Part of WELD, the Wayne State application stack: [CREED](https://creed.apps.wayne.edu/) sets the policy PROCTOR marks against, [Preflight](https://preflight.apps.wayne.edu/) runs it in GitLab CI, and [Sourdough](https://sourdough.apps.wayne.edu/) and [WELD](https://weld.apps.wayne.edu/) apps come with it installed. The look is [WayneUI](https://wayneui.apps.wayne.edu/).

Agents: the machine-readable entry point for this documentation is [llms.txt](https://proctor.apps.wayne.edu/llms.txt).
