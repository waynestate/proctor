# MAINTAINING: who watches the watcher

How to build, test, release and keep PROCTOR current. PROCTOR is the stack's exam: `proctor check` measures an app
against CREED's version policy. If PROCTOR is wrong, every app gets the wrong grade, so it is held to the same rules
it enforces. No phones during the exam, including ours.

## What PROCTOR is and what it depends on

- `waynestate/proctor` on Packagist, a framework-free PHP CLI (`bin/proctor`). Optional single-file phar.
- Runtime dependencies: `symfony/console`, `symfony/yaml`, `composer/semver`, `nikic/php-parser`. No other stack
  package at runtime.
- **Its policy comes from CREED.** `src/Creed/` is a generated copy of CREED's reader and `policy.json`
  (namespace `Waynestate\Proctor\Creed`), with a `manifest.json` of hashes. Never hand-edit it. A `--policy <file>`
  flag points PROCTOR at any other `policy.json`, for example `vendor/waynestate/creed/policy/policy.json`.
- **Who uses it:** apps, Preflight's `proctor` component, Squire's release script (as a report), WELD, and the stack's
  own front-page deploys.

## Where it lives

Development happens on git.wayne.edu, the canonical repository: [enterprise-apps/proctor](https://git.wayne.edu/enterprise-apps/proctor). Changes, issues and merge requests go there, and tags are made there, by the maintainer, with an approver. GitHub ([waynestate/proctor](https://github.com/waynestate/proctor)) is a read-only push mirror of it: `main` and tags are copied automatically, nobody pushes to it, and Packagist picks releases up from there.

## Build and test

You need PHP 8.3 or newer and Composer, or only Docker.

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Fix code style | `composer fix` |
| Build the phar (needs Docker), Windows | `.\scripts\build-phar.ps1` |
| Build the phar (needs Docker), Linux and macOS | `scripts/build-phar.sh` |

Both phar scripts write `build/proctor.phar` (gitignored). Since 0.6.0, the phar is published on every tag by the `release-phar` job (see below).

With only Docker (the same line works in PowerShell and sh):

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 sh -c "composer install --no-interaction && composer ci"
docker run --rm -v "${PWD}:/app" -w /app php:8.5-cli vendor/bin/phpunit
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan, the supported-versions table check
(`site:versions-check`), the agent-ready files check (`site:agent-ready-check`), the docs site check (`docs:check`)
and `composer audit`. After a docs change, run `composer docs` and `composer site:agent-ready` first, or the two
site checks fail on stale files.

## CI

- **GitLab:** Preflight 0.8.0's `php-test` (PHP 8.3, 8.4, 8.5), `composer-audit`, `proctor` (on the `deploy/` layer;
  it blocks the pipeline) and `a11y` (every page under `site/`) components, plus the
  `composer-ci`, `wayneui-copies`, `creed-copy-refresh` (scheduled) and `release-phar` (tags) jobs.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`.
- Green means `composer ci` passed on all three PHP versions on both.

## The release-phar job

The GitLab pipeline defines a `release-phar` job that runs on tags only
(`rules: if: $CI_COMMIT_TAG`); the lead cuts the tag, and the job does the
rest. On a tag it:

1. installs the dependencies in the pinned `composer:2.11.1` image (by digest; adding
   curl first, which the image may lack),
2. builds `build/proctor.phar` inside that container, the way
   `scripts/build-phar.sh` does but without Docker-in-Docker: the runtime
   sources (src, bin, composer.json, composer.lock, box.json.dist) go to a
   temp dir, get a no-dev install with `--optimize-autoloader`, and Box
   compiles the phar against that copy,
3. verifies it runs with `php build/proctor.phar --version`,
4. writes `build/proctor.phar.sha256` with `sha256sum`,
5. uploads the phar and the checksum to the project's generic package registry
   under the tag with curl and the `CI_JOB_TOKEN`, refusing to overwrite: if
   the file for the tag already exists (HTTP 200 on a HEAD request) the job
   exits 1,
6. creates the GitLab Release for the tag with curl (`POST` to
   `/projects/:id/releases` with JSON `name`, `tag_name` and `assets.links`),
   or, when the release already exists, adds the two asset links with one
   `POST` to `/projects/:id/releases/:tag/assets/links` per file.

There is no release tooling on the arm64 runner; curl and the GitLab API are
the whole mechanism.

## Refreshing the CREED copy

Whenever CREED's policy or reader changes:

```
php ../creed/bin/export.php --namespace='Waynestate\Proctor\Creed' --to=src/Creed
```

Then check it. Windows: `$env:CREED_PATH = "..\creed"; composer creed-copy:check`. Linux and macOS:
`CREED_PATH=../creed composer creed-copy:check`. The check is not part of `composer ci`, because CI has no CREED
checkout, so run it by hand after each refresh. Commit the copy with the CREED commit it came from in the message
(the manifest records `creed_version` as `unknown` when the export cannot see a tag).

**Handbook check ids.** `tests/CreedHandbookCheckIdsTest.php` reads CREED's `handbook/*.md` and fails when a
"PROCTOR check: `<id>`" tag, or prose such as "PROCTOR's `<id>` check", names an id `CheckSet::all()` does not
register. It finds the handbook through `CREED_PATH`, then the sibling `../creed`, then `vendor/waynestate/creed`, and
skips with a message when none is there (the Composer package ships without `handbook/`, so a plain `composer ci`
in a fresh clone skips it). Run it with a checkout in reach after renaming or removing a check:
`CREED_PATH=../creed vendor/bin/phpunit --filter CreedHandbook`. A failure means the fix belongs in CREED's
handbook, or the check still has to ship.

**Weekly CREED copy refresh.** The `creed-copy-refresh` job (`scripts/creed-copy-refresh.sh`) does this without a
person every Monday at 07:03 America/Detroit, one hour after CREED's policy robot. It clones CREED main with the job
token, re-exports the copy, reruns the docs builder and the agent-ready builder, then runs `composer creed-copy:check`
and the full `composer ci`. Only when both pass does it push one commit to main, as "CREED copy bot", with the CREED
version in the message. No change in the copy (a moved version stamp in `manifest.json` does not count) means no
commit. A red run pushes nothing, so main stays as it was and next week's run tries again. The schedule is a GitLab
setting (CI/CD > Schedules, weekly, variable `PROCTOR_JOB=creed-copy`); to run it now, start a pipeline on main from
the web. GitLab starts no pipeline for a job-token push, which is why the job runs the whole gate before it pushes.
Use the hand steps above only when a refresh cannot wait for Monday.

## Adding or changing a check

- Each check has a stable rule id (see "Rule ids" in the README) and a test. Rule ids never get reused.
- New checks start as warnings and become failures later (warn first, then fail).
- PROCTOR owns the stack's checks. If another project grows a check, it belongs here instead.

## Releasing

1. Move the lines under `## Unreleased` in `CHANGELOG.md` to a new `## X.Y.Z (YYYY-MM-DD)` heading, leaving an empty
   `## Unreleased`.
2. `composer ci` green locally, then on both pipelines for that commit. Refresh the CREED copy first if CREED moved.
3. Run a secrets scan of the history and check that nothing internal (server names, people, IDs) is in the tree.
4. Tag `X.Y.Z` (no `v`) on git.wayne.edu, with an approver. The push mirror carries the tag to GitHub, and Packagist picks it up from there.
5. Wait for the `release-phar` job: it builds the phar on the tag, uploads
   `proctor.phar` and `proctor.phar.sha256` to the generic package registry,
   and attaches both as asset links on the GitLab Release. Check the release
   page for the tag: both files must be listed, and the sha256 file must
   match the phar. If the job fails, fix it and re-run the job; if the
   package upload refuses because the file already exists, the tag was
   already released once and must not be overwritten.
6. A release needs a maintainer's approval. Never move or delete a published tag.
7. Consumers pin `^0.x`, so a pre-1.0 minor (0.2 to 0.3) is opt-in for them; tell them in the CHANGELOG.

## The front page

`site/` is the static front page, for `https://proctor.apps.wayne.edu/` on Citadel. It is served through a small
raw-PHP `deploy/` layer generated with Squire (one per stack project); regenerate that layer, do not hand-edit it.

## Who maintains it

The stack maintainer (a role, not a named person), Wayne State University Enterprise Applications. To reach them, open
an issue on git.wayne.edu ([enterprise-apps/proctor](https://git.wayne.edu/enterprise-apps/proctor/-/issues)). Internal
runbook (accounts, tokens, mirrors, deploy access): ask Enterprise Applications.
