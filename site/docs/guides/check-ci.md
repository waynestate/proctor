---
title: "CI checks | PROCTOR docs"
description: "The checks that verify CI pipelines run composer ci and that Preflight includes are pinned to a release tag or a commit SHA."
url: https://proctor.apps.wayne.edu/docs/guides/check-ci.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# CI checks[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#ci-checks)

This page covers the PROCTOR checks that verify CI pipelines run `composer ci`, that Preflight includes are pinned, and that deploy scripts and committed files keep the app portable. Run `vendor/bin/proctor check` against the app path, then fix the finding the report names. PROCTOR watches the exam and does not hand out answers.

## ci-pipelines[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#ci-pipelines)

`ci-pipelines` enforces the CI contract. For a stack package (a composer.json `name` starting with `waynestate/`), both `.gitlab-ci.yml` and `.github/workflows/ci.yml` must exist and run `composer ci`; a missing or incomplete file is a failure. For any other project, a missing or incomplete `.gitlab-ci.yml` is a warning and `.github/workflows/ci.yml` is optional.

The files are parsed as YAML, not grepped: a `composer ci` that only appears in a comment is not a script line, and a GitLab job is a top-level map with a `script` key. A GitHub job counts when a step runs `composer ci` or calls a reusable workflow with `ci-command: composer ci`.

A private stack package that sets `extra.proctor.github-mirror` to `false` in composer.json must never be on GitHub: a missing `.github/workflows/ci.yml` then gives no finding, and a present one warns (`proctor/ci-pipelines/github-private`) along with the warning-only rules.

Two more rules warn, and never fail, for stack packages and other projects alike:

- A GitHub `uses:` ref that is not a 40-hex commit SHA (`proctor/ci-pipelines/unpinned-uses`).
- A pipeline environment that switches Composer's audit or blocking policy off (`proctor/ci-pipelines/composer-audit-off`): `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore`. On GitLab these come from the top-level or job `variables:` (a scalar or a map with a `value` key); on GitHub from the top-level, job or step `env:`.

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

Fix a failing pipeline by adding a GitLab job whose `script` runs `composer ci`, adding a GitHub workflow that runs `composer ci` or calls a reusable workflow with `ci-command: composer ci`, and pinning every `uses:` ref to a commit SHA.

## preflight-pinned[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#preflight-pinned)

`preflight-pinned` warns, and never fails, when an app includes a Preflight component at a moving ref or re-declares what an included Preflight job runs. A Preflight include is an `include:` entry whose `component:` path contains `/preflight/`.

- When the ref after the last `@` is neither a full release tag (`MAJOR.MINOR.PATCH` such as `0.3.0`, optional leading `v`) nor a 40-lowercase-hex commit SHA it warns (`proctor/preflight-pinned/unpinned`). A branch such as `main`, `~latest`, a partial version such as `0.3` or `1` (GitLab resolves it to the newest matching release), or no `@` at all all count as moving. Pin a release tag such as `@0.3.0` or a commit SHA; published stack tags are never moved or deleted, so a release tag is a pin (D-054).
- When the app declares one of the Preflight job names (`proctor`, `php-test`, `composer-audit`, `sbom`, `pint`, `phpstan`, `eslint`, `renovate`, `package-publish`) with `script`, `before_script` or `after_script` while a Preflight component is included, it warns (`proctor/preflight-pinned/script-override`).

With nothing to flag it passes: `Preflight includes are pinned`, or `no Preflight components included` when there is no pipeline, a parse error or no Preflight include.

| Check | Rule id |
| --- | --- |
| `preflight-pinned` | `proctor/preflight-pinned` |
| `preflight-pinned` | `proctor/preflight-pinned/unpinned` |
| `preflight-pinned` | `proctor/preflight-pinned/script-override` |

## portability[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#portability)

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

## destructive-deploy[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#destructive-deploy)

`destructive-deploy` fails when a deploy script runs an artisan command that drops or rebuilds the database (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`), which erases production data on every deploy.

Danger

A drop-and-rebuild command in a deploy script wipes the live database on every release. Replace it with a plain migration.

It scans `.sh` and `.php` files under a top-level `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory, the top-level `build.sh`, `release.sh` and `.gitlab-ci.yml`, and the `scripts` section of composer.json. Shell comments are ignored.

| Check | Rule id |
| --- | --- |
| `destructive-deploy` | `proctor/destructive-deploy` |

Fix by running plain `php artisan migrate --force` in the deploy.

## instant-client-committed[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#instant-client-committed)

`instant-client-committed` warns when Oracle Instant Client is committed to the repo: an `instantclient*.zip` or `instantclient*.rpm` archive, any file under a directory named `instantclient` or `instantclient_*` (an unzipped install), or a loose client library such as `libclntsh.so`, `libociei.so` or `oci.dll`.

Only committed files count: the git-tracked files when the app path sits in a git repository and git can be run, otherwise the files on disk. Skipped at any depth: `vendor/`, `node_modules/` and `.git/`. One warning per match: the archive, the topmost matching directory, or the loose file, each reported with its total size in MB.

Note

This is a warning, not a failure: Citadel's images already include Instant Client, and the WSU Sail and Docker images will too.

| Check | Rule id |
| --- | --- |
| `instant-client-committed` | `proctor/instant-client-committed` |

Fix by deleting it, running `git rm -r --cached` on the path PROCTOR reports, and adding that path to `.gitignore`.

## Related[#](https://proctor.apps.wayne.edu/docs/guides/check-ci.html#related)

- [WayneUI checks](https://proctor.apps.wayne.edu/docs/guides/check-wayneui.html)
- [Overrides](https://proctor.apps.wayne.edu/docs/guides/overrides.html)
