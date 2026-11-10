---
title: "CI checks | PROCTOR docs"
description: "The checks that verify CI pipelines run composer ci, that Preflight includes are pinned to a release tag or a commit SHA, and that its gates run and block."
url: https://proctor.apps.wayne.edu/docs/guides/check-ci.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# CI checks [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#ci-checks)

This page covers the PROCTOR checks that verify CI pipelines run `composer ci`, that Preflight includes are pinned and its gates are present and blocking, and that deploy scripts and committed files keep the app portable. Run `vendor/bin/proctor check` against the app path, then fix the finding the report names. PROCTOR watches the exam and does not hand out answers.

## Run PROCTOR in GitLab CI [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#run-proctor-in-gitlab-ci)

The smallest pipeline change that satisfies `preflight-pinned` and `preflight-gates` below is Preflight's `proctor` component, pinned to a release tag, with its command left at the default (add Preflight's `php-test` and `composer-audit` next to it, and a job that runs `composer ci` for `ci-pipelines`):

```yaml
include:
  - component: $CI_SERVER_FQDN/enterprise-apps/preflight/proctor@0.5.0
```

The default command is `vendor/bin/proctor check --format=codequality`, so the findings show on the merge request as Code Quality. Do not add `--policy` or `--today` to `proctor_command`: in CI PROCTOR refuses both (exit 2) unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`, and without them it reads CREED's policy from the app's `vendor/waynestate/creed`. Since Preflight 0.4.0 the component clones the full history for this job (`git_depth: "0"`), so `override-approver` can read git blame. Strongly recommended: a git.wayne.edu instance runner. Preflight's own page lists every input: [preflight.apps.wayne.edu](https://preflight.apps.wayne.edu/).

To keep PROCTOR's full JSON report from the same run, add `--record`: `vendor/bin/proctor check --format=codequality --record="$CI_PROJECT_DIR/proctor.json"` prints Code Quality for the merge request and writes the JSON report (byte for byte what `--format=json` prints) to `proctor.json`, without running the checks twice. Rollcall, the fleet census, reads each app's latest `proctor.json` from its `proctor` job's artifacts, so the job must keep the file as an artifact under `when: always` (a failing check still has a report worth reading). The exit code is the check's own; a record PROCTOR cannot write is exit 2.

Preflight 0.5.0 does this on its own. When the app's PROCTOR lists `--record` in `proctor check --help` (PROCTOR 0.11.0 or later), the component adds `--record "$CI_PROJECT_DIR/proctor.json"` to a `proctor check` command and keeps `proctor.json` as an artifact under `when: always`, next to the Code Quality report. It never adds the option to a `proctor gate` command, whose record is a deploy verdict, not the report Rollcall reads, to a command that already passes it, or to one that is not a plain `proctor check` line (shell operators, quotes or `$`). With an older PROCTOR the job log says so and the run keeps no `proctor.json`: move the app to PROCTOR ^0.11.

Still pinned to Preflight 0.4.0? That component keeps only the Code Quality report, so do both halves yourself: put the `--record` command above in the `proctor_command` input, and add `artifacts: paths: [proctor.json]` to a `proctor:` job in your own `.gitlab-ci.yml`, next to the include. GitLab merges that key into the component's job, which already keeps its artifacts `when: always`; it re-declares no `script`, so `preflight-pinned` has nothing to flag. Or move the pin to `@0.5.0` and drop both.

## ci-pipelines [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#ci-pipelines)

`ci-pipelines` enforces the CI contract. For a stack package (a composer.json `name` starting with `waynestate/`), both `.gitlab-ci.yml` and `.github/workflows/ci.yml` must exist and run `composer ci`; a missing or incomplete file is a failure. For any other project, a missing or incomplete `.gitlab-ci.yml` is a warning and `.github/workflows/ci.yml` is optional.

The files are parsed as YAML, not grepped: a `composer ci` that only appears in a comment is not a script line, and a GitLab job is a top-level map with a `script` key. A GitHub job counts when a step runs `composer ci` or calls a reusable workflow with `ci-command: composer ci`.

A private stack package that sets `extra.proctor.github-mirror` to `false` in composer.json must never be on GitHub: a missing `.github/workflows/ci.yml` then gives no finding, and a present one warns (`proctor/ci-pipelines/github-private`) along with the warning-only rules.

Four more rules warn, and never fail, for stack packages and other projects alike:

- A GitHub `uses:` ref that is not a 40-hex commit SHA (`proctor/ci-pipelines/unpinned-uses`).
- A GitLab job's `script`, `before_script` or `after_script` line, or a GitHub step's `run`, with a command that starts with `composer update` or `composer require` (`proctor/ci-pipelines/composer-update`), once per line: CI installs from the lock with `composer install`. A line that is a `#` comment inside a block string never fires.
- A pipeline environment that switches Composer's audit or blocking policy off (`proctor/ci-pipelines/composer-audit-off`): `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore`. On GitLab these come from the top-level or job `variables:` (a scalar or a map with a `value` key); on GitHub from the top-level, job or step `env:`.
- A pipeline environment that sets `PROCTOR_ALLOW_OVERRIDES` to `1` (`proctor/ci-pipelines/allow-overrides`): the variable lets the app pass `--policy` and `--today` in CI (BEST_PRACTICES 1.2), so it could grade itself against a policy or a date it picks. Any other value leaves the guard on. On GitLab it is read from the top-level or job `variables:` (a scalar or a map with a `value` key); on GitHub from the top-level, job or step `env:`. Remove the variable; only PROCTOR's own pipeline needs it.

When nothing needs fixing the check passes with `both pipelines run composer ci`, or `gitlab pipeline runs composer ci` when there is no GitHub workflow.

| Check | Rule id |
| --- | --- |
| `ci-pipelines` | `proctor/ci-pipelines` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-missing` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-parse` |
| `ci-pipelines` | `proctor/ci-pipelines/gitlab-no-ci` |
| `ci-pipelines` | `proctor/ci-pipelines/github-missing` |
| `ci-pipelines` | `proctor/ci-pipelines/github-parse` |
| `ci-pipelines` | `proctor/ci-pipelines/github-no-ci` |
| `ci-pipelines` | `proctor/ci-pipelines/github-private` |
| `ci-pipelines` | `proctor/ci-pipelines/unpinned-uses` |
| `ci-pipelines` | `proctor/ci-pipelines/composer-audit-off` |
| `ci-pipelines` | `proctor/ci-pipelines/composer-update` |
| `ci-pipelines` | `proctor/ci-pipelines/allow-overrides` |

Fix a failing pipeline by adding a GitLab job whose `script` runs `composer ci`, adding a GitHub workflow that runs `composer ci` or calls a reusable workflow with `ci-command: composer ci`, pinning every `uses:` ref to a commit SHA, running `composer install` instead of `composer update` or `composer require`, and removing `PROCTOR_ALLOW_OVERRIDES` from the pipeline environment, which only PROCTOR's own pipeline needs.

## preflight-pinned [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#preflight-pinned)

`preflight-pinned` warns, and never fails, when an app includes any CI component at a moving ref (CREED practice 23), or re-declares what an included Preflight job runs. A component is an `include:` entry with a `component:` value; a Preflight include is one whose `component:` path contains `/preflight/`.

- When the ref after the last `@` is neither a full release tag (`MAJOR.MINOR.PATCH` such as `0.3.0`, optional leading `v`) nor a 40-lowercase-hex commit SHA it warns (`proctor/preflight-pinned/unpinned`). A branch such as `main`, `~latest`, a partial version such as `0.3` or `1` (GitLab resolves it to the newest matching release), or no `@` at all all count as moving. Pin a release tag such as `@0.3.0` or a commit SHA; published stack tags are never moved or deleted, so a release tag is a pin.
- When the app declares one of the Preflight job names (`proctor`, `php-test`, `composer-audit`, `sbom`, `pint`, `phpstan`, `eslint`, `renovate`, `package-publish`) with `script`, `before_script` or `after_script` while a Preflight component is included, it warns (`proctor/preflight-pinned/script-override`).

With nothing to flag it passes: `CI components are pinned`, or `no CI components included` when there is no pipeline, a parse error or no component include.

| Check | Rule id |
| --- | --- |
| `preflight-pinned` | `proctor/preflight-pinned` |
| `preflight-pinned` | `proctor/preflight-pinned/unpinned` |
| `preflight-pinned` | `proctor/preflight-pinned/script-override` |

## preflight-gates [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#preflight-gates)

`preflight-gates` warns, and never fails, when `.gitlab-ci.yml` skips or weakens Preflight, the flags Preflight's practice 3.3 asks for. An include at a moving ref is `preflight-pinned`'s finding; this check covers the rest.

- No `include:` entry whose `component:` path contains `/preflight/` warns (`proctor/preflight-gates/no-include`): none of the stack's gates run. Include `php-test`, `composer-audit` and `proctor` from Preflight, pinned to a release tag.
- A gate that can fail while the pipeline passes warns (`proctor/preflight-gates/allow-failure`), once per place it is set: a gate component included with the `allow_failure` input set to `true`, or a job the app re-declares under the gate's job name (its `job_name` input, or the component name) with `allow_failure` set to anything but `false`, the exit-codes form included. Every Preflight component is a gate except `renovate` (a bot) and `package-publish` (a release step); php-test's advisory job may fail by design and is not one.
- No job that runs PROCTOR warns (`proctor/preflight-gates/no-proctor-job`): the Preflight `proctor` component is not included and no job's `script` runs `proctor check` or `proctor gate` (`vendor/bin/proctor`, `proctor.phar` or a path to either).

No finding when there is no `.gitlab-ci.yml` or it does not parse; `ci-pipelines` reports both. With nothing to flag it passes: `Preflight gates are included, none may fail softly, and PROCTOR runs`. It reports, and is not in the deploy gate. A warning on purpose: a repository fixing its first findings may keep `allow_failure` for a while, with a comment saying why, the way Sourdough's pipeline does on its deploy layer.

| Check | Rule id |
| --- | --- |
| `preflight-gates` | `proctor/preflight-gates` |
| `preflight-gates` | `proctor/preflight-gates/no-include` |
| `preflight-gates` | `proctor/preflight-gates/allow-failure` |
| `preflight-gates` | `proctor/preflight-gates/no-proctor-job` |

## portability [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#portability)

`portability` warns when code hard-codes a platform fact: an absolute host path, a hard-coded proxy address, a local-disk session store, or a `php_value`/`php_flag` line in `.htaccess`.

PHP files are matched on the syntax tree (nikic/php-parser with names resolved), not with regular expressions, so a comment or string that merely mentions a banned call is not flagged, and a call split over several lines, or hidden behind a `use function ... as ...` alias, is. A call through a variable (`$f()`) is reported as an info note, never as a pass.

Scanned: every `*.php` file, every file under the top-level `config/` directory, and every `.htaccess`. Skipped at any depth: `vendor/`, `node_modules/`, `storage/`, `tests/` and `.git/`; in a Laravel app (an `artisan` file at the project root) also the top-level `bootstrap/cache/`, which holds the generated output of `php artisan optimize` (`config.php`, `routes-v7.php`, `services.php`, `packages.php`, `events.php`) instead of app code. A raw-PHP app that keeps its own `bootstrap/cache/` folder is scanned as before.

| Check | Rule id |
| --- | --- |
| `portability` | `proctor/portability` |
| `portability` | `proctor/portability/host-path` |
| `portability` | `proctor/portability/proxy-address` |
| `portability` | `proctor/portability/local-session` |
| `portability` | `proctor/portability/htaccess-php-setting` |
| `portability` | `proctor/portability/parse-error` |
| `portability` | `proctor/portability/dynamic-call` |

## destructive-deploy [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#destructive-deploy)

`destructive-deploy` fails when a deploy script runs an artisan command that drops or rebuilds the database (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`), which erases production data on every deploy.

Danger

A drop-and-rebuild command in a deploy script wipes the live database on every release. Replace it with a plain migration.

It scans `.sh` and `.php` files under a top-level `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory, the top-level `build.sh`, `release.sh` and `.gitlab-ci.yml`, and the `scripts` section of composer.json. Shell comments are ignored.

| Check | Rule id |
| --- | --- |
| `destructive-deploy` | `proctor/destructive-deploy` |

Fix by running plain `php artisan migrate --force` in the deploy.

## instant-client-committed [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#instant-client-committed)

`instant-client-committed` warns when Oracle Instant Client is committed to the repo: an `instantclient*.zip` or `instantclient*.rpm` archive, any file under a directory named `instantclient` or `instantclient_*` (an unzipped install), or a loose client library such as `libclntsh.so`, `libociei.so` or `oci.dll`.

Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One warning per match: the archive, the topmost matching directory, or the loose file, each reported with its total size in MB.

Note

This is a warning, not a failure: Citadel's images already include Instant Client, and the WSU Sail and Docker images will too.

| Check | Rule id |
| --- | --- |
| `instant-client-committed` | `proctor/instant-client-committed` |

Fix by deleting it, running `git rm -r --cached` on the path PROCTOR reports, and adding that path to `.gitignore`.

## Related [#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#related)

- [WayneUI checks](https://proctor.apps.wayne.edu/docs/guides/check-wayneui.html)
- [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)
