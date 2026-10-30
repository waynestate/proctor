---
title: "PROCTOR: Watches the exam. Doesn't help."
description: "PROCTOR checks a PHP app against CREED's version policy from the command line: PHP support, patch levels, Composer constraints, the audit, MySQL 5.6 and CI. It writes text, JSON, GitLab Code Quality or SARIF."
url: https://proctor.apps.wayne.edu/
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

The stack's check command

# PROCTOR: Watches the exam. Doesn't help.

PROCTOR stands for Policy Rules Of Code, Tested On Release.

Run `vendor/bin/proctor check` in a PHP app and it checks the app against CREED's version policy: the PHP floor, patch levels, Composer constraints, the lock file, `composer audit`, MySQL 5.6 safety, portability and the CI pipelines. You get text, JSON, GitLab Code Quality or SARIF, and an exit code of 0, 1 or 2.

`$ composer require waynestate/proctor --dev`

**Already included in [WELD](https://weld.apps.wayne.edu/) and in every [Sourdough](https://sourdough.apps.wayne.edu/) app.** You can also install PROCTOR on its own in any PHP app you already have.

[Sit the exam](https://proctor.apps.wayne.edu/#start-title) [Watch it mark a paper](https://proctor.apps.wayne.edu/#sheet-title)

- **14** checks
- **4** report formats
- **3** exit codes
- **0** policy of its own

Page 1

## Instructions to candidates

Read these before you turn the page. The proctor will not repeat them.

1. ### Bring your own lock.
   PROCTOR reads the app's `composer.json` and `composer.lock`, and `package-lock.json` for Vue and Inertia. It never runs your app.
2. ### The questions are set by CREED.
   Support dates, the grace window and the audit threshold all come from CREED's policy file. PROCTOR has no rules of its own. If your PHP version isn't in the policy at all, that counts as a failure.
3. ### Every answer is written in words.
   Each line starts `PASS`, `INFO`, `WARN` or `FAIL` and the last line says `RESULT`. There's no colour and there are no ANSI codes, so the words carry the meaning.
4. ### Three ways to leave the room.
   `0` pass, warnings allowed. `1` one or more failures. `2` a tool error: bad options, an unreadable policy, a malformed `composer.json`. Exit 2 means the proctor tripped, not you.
5. ### Notes from home expire.
   An override needs a reason, an expiry date and an approver, and it shows up in every report. Once a PHP version is past end of life plus the grace period, no override counts.

Part A

## The questions

Nine of PROCTOR's fourteen checks, in the order they run. Each one shows a real line from PROCTOR's text report, taken from its test fixtures.

1. Q1 `php-floor`
   ### Is the lowest PHP your `composer.json` allows still supported?
   Active passes. Security-only warns. Past end of life, or no usable `php` constraint, fails. The floor comes from the constraint, not from the PHP that runs the check.
   FAIL php-floor: php floor 7.4 is eol_grace_expired
2. Q2 `unpatched`
   ### Is every locked component on its line's latest patch?
   Reads Laravel from `composer.lock`, Vue and Inertia from `package-lock.json`, and PHP from `--php-runtime`. Being behind is a warning, so you choose when to update.
   WARN unpatched: laravel 12.1.3 is behind 12.4.7 (checked 2026-10-28); update to the latest patch.
3. Q3 `unbounded-constraints`
   ### Is every runtime requirement tied to a released range?
   A `*` or a `dev-` branch fails, including `dev-master as 1.0`. `require-dev`, `php`, `ext-*` and `lib-*` are skipped.
   FAIL unbounded-constraints: vendor/any constraint "\*" is unbounded
4. Q4 `lock-committed`
   ### Did you hand in your `composer.lock`?
   Missing from the app path fails. In CI the working tree is a clone, so present means committed.
   PASS lock-committed: composer.lock is present
5. Q5 `portability`
   ### Would the app still run on another host?
   Warns on an absolute host path, a hard-coded proxy address, sessions on local disk, or a `php_value` line in `.htaccess`. We want the same app to run on Citadel, OpenShift, Coolify or plain Docker.
   WARN portability: host-path: absolute host path "/var/log/app.log"; read it from env or config
6. Q6 `mysql56`
   ### Will your migrations survive the shared MySQL 5.6 server?
   Looks for JSON and generated columns, `renameColumn`, the upsert alias, long indexed strings and a few more. It reads the code as a syntax tree, so a match inside a comment or a string doesn't count. These are warnings only, because an app on its own newer database is fine.
   WARN mysql56: mysql56-json: JSON columns and JSON queries need MySQL 5.7.8
7. Q7 `ci-pipelines`
   ### Do both pipelines run `composer ci`?
   A stack package (named `waynestate/...`) fails without both a GitLab and a GitHub pipeline. Any other project gets a warning for a missing GitLab one, and GitHub is optional. A GitHub `uses:` ref that isn't a full commit SHA warns.
   WARN ci-pipelines: ci-pipelines-gitlab-missing: .gitlab-ci.yml is missing; add a GitLab pipeline with a job whose script runs composer ci
8. Q8 `composer-audit`
   ### Does the lock carry a known advisory?
   Fails at or above the policy's `audit_severity_threshold`, warns below it. Each finding names the package, the locked version, the advisory's CVE or id, its title and its severity. An advisory the app ignores in its own composer.json is a warning, not a fail, and so is anything composer has already moved to its ignored section. An advisory with no severity is treated as failing, because we'd rather not turn unknown input into a pass.
   PASS composer-audit: no advisories found
9. Q9 `overrides`
   ### Has any note from home expired?
   Every expired entry in `policy-overrides.json` fails. Active ones still appear in every report, and one that expires within 14 days gets a warning.
   FAIL overrides: override for "lock-committed" expired on 2026-01-01

### Part B: next term Coming soon

Planned but not built yet, so there are no commands for these.

- **The release gate.** `proctor gate` against the image that will actually serve the app, with a ratchet that blocks new problems before old ones.
- **Security headers and CSP.** HELMET sets them; PROCTOR will check they are there.
- **The WayneUI contract.** Checks that the app uses WayneUI at a supported version.
- **Node versions.** Today only the Vue and Inertia locks are read.
- **Lock in sync.** Flags a lock whose hash no longer matches `composer.json`.

Marking

## The answer sheet marks itself

One run of `proctor check` on a small app, then a second run after the fix. Scroll, and keep your eyes on your own paper.

1. 1 · Pencils up
   The app is called `your-app`, and the policy is CREED's.
   ```
   vendor/bin/proctor check --policy=policy.json
   ```
2. 2 · Questions 1 and 2
   PHP 8.4 is in active support, so that passes. Laravel is a few patches behind, which is only a warning.
   ```
   PASS php-floor: php floor 8.4 is active
   WARN unpatched: laravel 12.1.3 is behind 12.4.7 (checked 2026-10-28); update to the latest patch.
   ```
3. 3 · Question 3: red pen
   One requirement says `"*"`. That fails, and the note in the margin says what to write instead.
   ```
   FAIL unbounded-constraints: vendor/any constraint "*" is unbounded
   ```
4. 4 · Questions 4 to 9
   The other six pass: the lock is there, nothing is hard-coded, no migration needs a newer MySQL, both pipelines run, the audit is clean and no override has expired. One failure is still enough to fail the run.
   ```
   RESULT: fail (1 failures, 1 warnings)
   ```
5. 5 · Resit, pencils down
   Pin `"vendor/any": "^2.0"` and run it again. Question 3 passes now. The patch warning is still there, but warnings are allowed, so it exits 0.
   ```
   PASS unbounded-constraints: all require constraints are bounded
   RESULT: pass
   ```

PROCTOR answer sheet Form 0.1

Name your-app Date 2026-10-28

**The marked answer sheet: final answer for each check after the resit. Question 3 failed first and passed on the resit.**

| Question | Check | Answer |
| --- | --- | --- |
| 1 | php-floor | pass |
| 2 | unpatched | warn |
| 3 | unbounded-constraints | fail, then pass on the resit |
| 4 | lock-committed | pass |
| 5 | portability | pass |
| 6 | mysql56 | pass |
| 7 | ci-pipelines | pass |
| 8 | composer-audit | pass |
| 9 | overrides | pass |

Red pen, Q3: "\*" is unbounded. pin it: "vendor/any": "^2.0"

Result First run: fail (1 failures, 1 warnings) Resit: pass

PASS exit 0 · warnings allowed

Hand in

## Four copies of the same paper

Pick the copy your reader needs with `--format`. The findings are the same in all four. These are real runs on PROCTOR's `unbounded-app` fixture, trimmed where marked.

**text**

White copy · for people · `--format=text` (the default)

```
proctor 0.2.7 | composer: 2.10.3
Policy: bundled (/app/src/Creed/policy.json), refreshed 2026-10-29, 0 days ago
PASS php-floor: php floor 8.4 is active
FAIL unbounded-constraints: vendor/dev constraint "dev-master" is unbounded
FAIL unbounded-constraints: vendor/any constraint "*" is unbounded
INFO composer-config: require.php is set but config.platform.php is not, so the lock resolves against the local PHP
PASS lock-committed: composer.lock is present
PASS portability: no hard-coded platform facts found
PASS mysql56: no Laravel migrations found; skipped
PASS destructive-deploy: no destructive deploy commands found
PASS ci-pipelines: both pipelines run composer ci
WARN composer-audit: composer audit could not run: No installed packages found. …
PASS composer-version: composer 2.10.3 is at or above the 2.7.7 floor
PASS repo-url-credentials: no credentials found in repository URLs
RESULT: fail (2 failures, 1 warnings)
```

**json**

Canary copy · for scripts · `--format=json`

```
{
    "tool": "proctor",
    "version": "0.2.7",
    "path": "tests/fixtures/apps/unbounded-app",
    "today": "2026-10-29",
    "result": "fail",
    "exit": 1,
    "tools": {
        "composer": "2.10.3"
    },
    "policy": {
        "origin": "bundled",
        "path": "/app/src/Creed/policy.json",
        "refreshed_on": "2026-10-29",
        "age_days": 0
    },
    "findings": [
        {
            "check_id": "php-floor",
            "rule_id": "proctor/php-floor",
            "status": "pass",
            "message": "php floor 8.4 is active",
            "path": "composer.json",
            "line": 1
        },
        {
            "check_id": "unbounded-constraints",
            "rule_id": "proctor/unbounded-constraints/unbounded",
            "status": "fail",
            "message": "vendor/dev constraint \"dev-master\" is unbounded",
            "path": "composer.json",
            "line": 4
        },
        … ten more findings
    ],
    "overrides": []
}
```

**codequality**

Green copy · for the merge request · `--format=codequality`

```
[
    {
        "description": "vendor/dev constraint \"dev-master\" is unbounded",
        "check_name": "proctor/unbounded-constraints/unbounded",
        "fingerprint": "5e27f1a0f163c3ad1c0d2f10dbac60e87ae91759f0d31c3551fad2570a66468e",
        "severity": "major",
        "location": {
            "path": "composer.json",
            "lines": {
                "begin": 4
            }
        }
    },
    … three more findings
]
```

Fingerprints hash the rule id, path and message, never the line number, so a finding keeps its name when the file moves.

**sarif**

Gold copy · for GitHub code scanning · `--format=sarif`

```
{
    "$schema": "https://json.schemastore.org/sarif-2.1.0.json",
    "version": "2.1.0",
    "runs": [
        {
            "tool": { "driver": { "name": "proctor", "version": "0.2.7", … } },
            "results": [
                {
                    "ruleId": "proctor/unbounded-constraints/unbounded",
                    "level": "error",
                    "message": {
                        "text": "vendor/dev constraint \"dev-master\" is unbounded"
                    },
                    "locations": [ … "uri": "composer.json" … "startLine": 4 … ],
                    "partialFingerprints": {
                        "proctorFingerprint/v1": "5e27f1a0f163c3ad…"
                    }
                }
            ]
        }
    ]
}
```

The mark sheet, filed in the format the head office already reads.

- **0**: Pass. Warnings allowed.
- **1**: One or more failures.
- **2**: Tool error. The proctor tripped over the chair.

Absences

## A note from home

An app can carry `policy-overrides.json` next to its `composer.json`. An active override turns a check's failures into warnings. An expired one fails the `overrides` check, and every override is printed on every run.

Excused absence No. 001

```
[
    {
        "check": "lock-committed",
        "reason": "lock is generated by CI and not committed",
        "expires": "2027-01-31",
        "approved_by": "jane@example.wayne.edu"
    }
]
```

signed, a real approver

When the note runs out

```
FAIL overrides: override for "lock-committed" expired on 2026-01-01
OVERRIDE lock-committed: fixture: expired (expires 2026-01-01, approved by tester)
RESULT: fail (1 failures, 1 warnings)
```

No note excuses end of life plus grace. A failure in the `eol_grace_expired` state stands, whatever the app carries.

Exam halls

## Sitting it in CI

It's the same command on GitLab and GitHub. On GitLab, Preflight's component runs it. On the GitHub mirror, the SARIF report goes to code scanning.

Hall G GitLab, through Preflight

```
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.1.1
    inputs:
      proctor_command: vendor/bin/proctor check --format=codequality --policy=vendor/waynestate/creed/policy/policy.json
```

The job publishes a Code Quality report, so findings show on the merge request. We strongly recommend a git.wayne.edu instance runner.

Hall H GitHub code scanning

```
- run: composer require waynestate/proctor --dev
# policy.json is the CREED policy file
- run: vendor/bin/proctor check --format=sarif --policy=policy.json > proctor.sarif
- uses: github/codeql-action/upload-sarif@9b3254085494b55a0f7c4c2b3d2d52cc712ce370 # v4.39.1
  with:
    sarif_file: proctor.sarif
```

Pinned to a full commit SHA, the way `ci-pipelines` asks. Needs `permissions: security-events: write`.

In CI, give `--php-runtime` the PHP version of the deploy image, because that image serves the app. The runner's PHP doesn't matter.

Begin

## You may turn over your paper

Install PROCTOR and CREED's policy as dev dependencies, then run the check from the app's folder.

**macOS · Linux**

1. Install, in a terminal:
   `composer require waynestate/proctor waynestate/creed --dev`
2. Sit the exam:
   `vendor/bin/proctor check --policy=vendor/waynestate/creed/policy/policy.json`

**Windows**

1. Install, in PowerShell:
   `composer require waynestate/proctor waynestate/creed --dev`
2. Sit the exam:
   `vendor\bin\proctor check --policy=vendor\waynestate\creed\policy\policy.json`

**No PHP (Docker)**

1. Install, from the app's folder (PowerShell, macOS or Linux):
   `docker run --rm -v "${PWD}:/app" -w /app composer:2 composer require waynestate/proctor waynestate/creed --dev`
2. Sit the exam. The `composer:2` image brings PHP and Composer, so `composer-audit` runs too:
   `docker run --rm -v "${PWD}:/app" -w /app composer:2 vendor/bin/proctor check --policy=vendor/waynestate/creed/policy/policy.json`

Options: `--path=.` · `--format=text|json|codequality|sarif` · `--policy=<file>` (optional; without it PROCTOR tries the `PROCTOR_POLICY` variable, then vendor/waynestate/creed, then its bundled copy) · `--today=YYYY-MM-DD` to fix the date so runs repeat · `--php-runtime=X.Y.Z` for the `unpatched` check.

Open book

## Sitting it with an agent

Three prompts for whatever coding agent you use. Copy one, or open it in OpenCode, Claude Code, Codex or ChatGPT. Each tells the agent which pages to read, and to ask you before it guesses.

### Get started with PROCTOR

Installs PROCTOR, runs the check and explains each FAIL and WARN with the smallest fix. It changes nothing. Works for raw PHP, Laravel with Blade, and Laravel with Inertia and Vue.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer run PROCTOR on the PHP app in this folder. PROCTOR is the check command of WELD, the Wayne State application stack: one vendor/bin/proctor check grades a PHP app against CREED's version policy and exits 0 (pass, warnings allowed), 1 (one or more failures) or 2 (a tool error).

Read first:
- https://proctor.apps.wayne.edu/llms.txt, the index of PROCTOR's pages, each one as Markdown. Fetch the pages you need; do not work from memory.
- This app's composer.json and composer.lock, and package-lock.json if there is one: PROCTOR reads Vue and Inertia from it.
- ADVISOR, the stack's MCP server, if it is connected: run_proctor_check runs the app's own vendor/bin/proctor and returns the findings, and policy_lookup answers support dates from CREED. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt

Task:
1. Say which kind of app this is: raw PHP, Laravel with Blade, or Laravel with Inertia and Vue. PROCTOR checks all three with the same command.
2. If PROCTOR is not installed, give me the install command and wait: `composer require waynestate/proctor waynestate/creed --dev`
3. Run the check, or ask ADVISOR to: `vendor/bin/proctor check --policy=vendor/waynestate/creed/policy/policy.json` (on Windows the path is vendor\bin\proctor).
4. For every FAIL and WARN, say what it means and the smallest fix. Change nothing yet.

Constraints:
- PROCTOR holds no policy of its own; CREED's policy decides. Never guess a support date.
- An override in policy-overrides.json needs a reason, an expiry and an approver. Never propose one to hide an end-of-life PHP: past end of life plus grace, no override counts.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess, and before you touch anything that deploys.

Output: a findings table (result, check, rule id, file, fix), the exit code, then the fixes as numbered steps, then your questions.
```

### Or sit it in CI

### PROCTOR in GitLab CI, through Preflight

Adds Preflight's proctor component to the app's pipeline, so findings show on each merge request as Code Quality.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding PROCTOR to the GitLab pipeline of the PHP app in this folder, at Wayne State University. PROCTOR is the check command of WELD, the Wayne State application stack. Preflight is the stack's shared GitLab CI: its proctor component runs PROCTOR and publishes a Code Quality report, so the findings show on the merge request.

Read first:
- https://proctor.apps.wayne.edu/llms.txt, then Preflight's own index: https://preflight.apps.wayne.edu/llms.txt
- This app's .gitlab-ci.yml, composer.json and composer.lock.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs for preflight and proctor, and run_proctor_check to see today's findings before the pipeline does. If it is not connected, say so and work from the pages.

Task:
1. Check that waynestate/proctor and waynestate/creed are dev dependencies. If not, give me the command and wait: `composer require waynestate/proctor waynestate/creed --dev`
2. Add Preflight's proctor component to .gitlab-ci.yml, at the version Preflight's page gives today, with proctor_command set to `vendor/bin/proctor check --format=codequality --policy=vendor/waynestate/creed/policy/policy.json`
3. Keep every job the file already has. Show me the whole diff before you write it.

Constraints:
- Pin the component to a released version, never a branch.
- If you add --php-runtime, give it the PHP of the image that serves the app, not the runner's. If you cannot tell, ask.
- Strongly recommended: a git.wayne.edu instance runner. Say whether this project has one, or that you cannot tell.
- Ask me before you guess, and before you push anything.

Output: the diff, then what the merge request will show, then your questions.
```

### PROCTOR in GitHub code scanning

Adds a workflow that uploads the SARIF report on each push and pull request. Every action is pinned to a commit SHA, as the ci-pipelines check asks.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding PROCTOR to GitHub code scanning for the PHP app in this folder, at Wayne State University. PROCTOR is the check command of WELD, the Wayne State application stack. Its sarif format is what GitHub code scanning reads.

Read first:
- https://proctor.apps.wayne.edu/llms.txt, and PROCTOR's README, section GitHub code scanning, which has the workflow to start from.
- This app's .github/workflows/, composer.json and composer.lock.
- ADVISOR, the stack's MCP server, if it is connected: run_proctor_check shows today's findings before the workflow does. If it is not connected, say so and work from the pages.

Task:
1. Add a workflow, or a job in the existing one, that installs PROCTOR with `composer require waynestate/proctor --dev`, runs vendor/bin/proctor check with --format=sarif and CREED's policy, writes proctor.sarif, and uploads it with github/codeql-action/upload-sarif.
2. Give the job permissions: security-events: write, and nothing more than it needs.
3. Show me the whole file before you write it.

Constraints:
- Pin every uses: ref to a full 40-character commit SHA, with the version in a comment. PROCTOR's ci-pipelines check warns on anything else.
- If this app has no GitHub repository, stop and say so: GitLab is the primary, and Paper 2 is the one to sit.
- Ask me before you guess, and before you push anything.

Output: the workflow file, then what code scanning will show, then your questions.
```

The agent reads the same pages you do: [llms.txt](https://proctor.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://proctor.apps.wayne.edu/llms-full.txt) holds them in one file, and this page has a [Markdown twin](https://proctor.apps.wayne.edu/index.md). Nothing here phones home. The buttons only copy text or open the tool you picked. Show your working.

Seating chart

## Who else is in the room

The rest of the Wayne State app stack. The proctor sits at the front, and everyone else sits the same exam.

![PROCTOR logo: an eye with a gold square pupil](https://proctor.apps.wayne.edu/brand/proctor-avatar-256.png)

PROCTOR

Watches the exam. Doesn't help.

The class

- ![WayneUI logo: a rounded frame around a solid shape, with a gold tab](https://proctor.apps.wayne.edu/assets/marks/wayneui.svg)
  [WayneUI](https://wayneui.apps.wayne.edu/)
  Wayne State's design system, for HTML, raw PHP, Blade and Vue.
- ![CREED logo](https://proctor.apps.wayne.edu/assets/marks/creed.png)
  [CREED](https://creed.apps.wayne.edu/)
  Sets the questions. Its `policy.json` is what PROCTOR reads.
- ![Preflight logo](https://proctor.apps.wayne.edu/assets/marks/preflight.png)
  [Preflight](https://preflight.apps.wayne.edu/)
  GitLab CI components. One of them runs PROCTOR.
- ![Pinstripe logo](https://proctor.apps.wayne.edu/assets/marks/pinstripe.png)
  [Pinstripe](https://pinstripe.apps.wayne.edu/)
  Code style presets for Pint, PHPStan, Rector, ESLint and Prettier.
- ![SESAME logo](https://proctor.apps.wayne.edu/assets/marks/sesame.png)
  [SESAME](https://sesame.apps.wayne.edu/)
  Sign-in through CAS, Entra ID or Drawbridge.
- ![HELMET logo](https://proctor.apps.wayne.edu/assets/marks/helmet.png)
  [HELMET](https://helmet.apps.wayne.edu/)
  Security headers, CSP and a hardened session cookie.
- ![Sourdough logo](https://proctor.apps.wayne.edu/assets/marks/sourdough.png)
  [Sourdough](https://sourdough.apps.wayne.edu/)
  Starts a new raw PHP app with PROCTOR already set up.
- ![WELD logo](https://proctor.apps.wayne.edu/assets/marks/weld.png)
  [WELD](https://weld.apps.wayne.edu/)
  A Laravel, Inertia and Vue app with the stack installed. Its pipeline runs PROCTOR through Preflight.

Transfer students, new this term

- ![Booster logo](https://proctor.apps.wayne.edu/assets/marks/booster.png)
  [Booster](https://booster.apps.wayne.edu/)
  Upgrades PHP and Laravel one version at a time, until the PHP floor passes.
- ![Rollcall logo](https://proctor.apps.wayne.edu/assets/marks/rollcall.png)
  [Rollcall](https://rollcall.apps.wayne.edu/)
  Scans the apps and reports each one's PHP, Laravel and sign-in versions.
- ![Squire logo](https://proctor.apps.wayne.edu/assets/marks/squire.png)
  [Squire](https://squire.apps.wayne.edu/)
  The deploy kit for Citadel. A release can be set to stop on failing PROCTOR checks.
- ![Drawbridge logo](https://proctor.apps.wayne.edu/assets/marks/drawbridge.png)
  [Drawbridge](https://drawbridge.apps.wayne.edu/)
  A gateway that signs people in through Entra ID before they reach a Citadel app.
- ![ADVISOR logo](https://proctor.apps.wayne.edu/assets/marks/advisor.png)
  [ADVISOR](https://advisor.apps.wayne.edu/)
  An MCP server. Its `run_proctor_check` tool runs your app's PROCTOR for your agent.
