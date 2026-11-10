---
title: "Troubleshooting | PROCTOR docs"
description: "How to work through a PROCTOR run that trips, warns or fails, and how to read the report to fix the finding."
url: https://proctor.apps.wayne.edu/docs/troubleshooting.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Troubleshooting [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#troubleshooting)

How to work through a PROCTOR run that trips, warns or fails, starting from the exit code and ending at the fix. A proctor that trips over the desk has not marked your paper: exit 2 is the proctor's problem, exit 1 is yours, and warnings are notes in the margin.

## Read the run in order [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#read-the-run-in-order)

1. **The exit code.** `0`: pass (warnings allowed). `1`: one or more failures. `2`: tool error. A warning never changes the exit code, so a red run always has a failure behind it.
2. **The policy line.** Every report names the policy in use: origin (`option`, `env`, `vendor` or `bundled`), path, `refreshed_on` and the age in days. If the run looks wrong, check which policy answered before blaming the checks.
3. **The findings.** One line per finding in the text format, each carrying a stable rule id. The [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html) page maps every id to its check.

## Exit 2: the proctor tripped [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#exit-2-the-proctor-tripped)

A tool error means PROCTOR could not run the checks. The causes it reports:

### Bad options [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#bad-options)

The run named an option the command does not accept, or a value it cannot read.

- Fix: correct the option against the [Commands](https://proctor.apps.wayne.edu/docs/reference/commands.html) page.
- Check: `vendor/bin/proctor check --help` lists what the command accepts.

### The message starts with `Refused:` [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#the-message-starts-with-refused)

In CI (any of `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` set to a value other than `0` or `false`), `--policy` and `--today` are refused (exit 2) unless the job sets `PROCTOR_ALLOW_OVERRIDES=1`: in CI the policy and the date must come from the stack, not from the app being checked, so a pipeline that could pass its own policy or date could grade itself. The message names the option or options that were given.

- Fix: drop `--policy` and let the policy resolve from the `PROCTOR_POLICY` variable (which stays allowed in CI), then the app's `vendor/waynestate/creed/policy/policy.json`, then the bundled copy; drop `--today`, or move the option to a trusted job that sets `PROCTOR_ALLOW_OVERRIDES: "1"` as a protected or group-level CI variable, never in the app's own `.gitlab-ci.yml`.
- Check: rerun in CI; the run reaches the checks when no refused option is present, or the trusted job allows them. Outside CI the same command runs unchanged.

### The message names a policy file [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#the-message-names-a-policy-file)

An explicit source (`--policy` or `PROCTOR_POLICY`) that points at a missing or invalid file is an error (exit 2). A vendor copy that cannot be read or parsed, or that is older than the bundled one, is not an error: it is skipped with a note on stderr and the run continues with the next source.

- Fix: correct the path or the file, or drop the explicit source and let the policy resolve from the `PROCTOR_POLICY` variable, then the app's `vendor/waynestate/creed/policy/policy.json`, then the bundled copy.
- Check: rerun and read the policy line; it names the origin, path, `refreshed_on` and age of the policy that answered.

### The message names policy-overrides.json [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#the-message-names-policy-overrides-json)

The file is rejected before any check runs when it is malformed or unreadable, when an entry names a check that is not registered in the run, or when an entry names the `overrides` or `override-approver` check: an override cannot exempt the checks that police overrides, and an entry for a check that does not exist would silently do nothing.

- Fix: repair the JSON, and use a check id that PROCTOR actually runs; the list of valid ids is built from the checks PROCTOR runs, so a new check needs no change to the guard.
- Check: rerun; the file must parse and name only registered checks before any check runs.

### composer.json is present but malformed [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#composer-json-is-present-but-malformed)

A present but malformed or unreadable composer.json is a tool error. A missing composer.json is not: the app gets a verdict, with `php-floor` failing.

- Fix: repair the JSON, or point `--path` at the application root that has one.
- Check: rerun; with a readable composer.json the run reaches the checks.

## Failures that mean what they say [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#failures-that-mean-what-they-say)

### composer-audit fails with `not audited:` [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#composer-audit-fails-with-not-audited)

Rule id `proctor/composer-audit/could-not-run`. The audit ran but its stdout was not parseable JSON; the message starts with `not audited:` and carries the first stderr line, because a green run that audited nothing is worse than a red one.

- Fix: read the stderr line in the message and fix what composer reported. Composer audit runs with `--no-plugins --no-interaction` and a minimal explicit environment (PATH plus the variables composer needs to start), so inherited `COMPOSER_*` switches no longer reach it.
- Check: rerun the check; only a clean audit with a clean result passes.

### composer-audit fails with `not audited:` because composer is missing [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#composer-audit-fails-with-not-audited-because-composer-is-missing)

Rule id `proctor/composer-audit/skipped`. The message reads `not audited: composer executable not found on PATH; install Composer or record an override`: there is no composer executable on PATH, so the audit could not run at all. A skipped audit must never look like a pass.

- Fix: install Composer on the runner, or record an active `composer-audit` override in policy-overrides.json with a reason and an expiry to downgrade the fail to a warning.
- Check: rerun; once composer is found, the audit runs and is graded as usual.

### composer-audit fails with `not audited:` because the lock is missing [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#composer-audit-fails-with-not-audited-because-the-lock-is-missing)

Rule id `proctor/composer-audit/skipped`. The message reads `not audited: no composer.lock in the app path; commit the lock or record an override`: the app path has no composer.lock, so `composer audit --locked` has nothing to audit.

- Fix: commit the lock file (`composer update --lock` regenerates it), or record an active `composer-audit` override in policy-overrides.json with a reason and an expiry to downgrade the fail to a warning.
- Check: rerun; with the lock present, the audit runs and is graded as usual.

### composer-audit fails with no advisory listed [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#composer-audit-fails-with-no-advisory-listed)

Rule id `proctor/composer-audit/exit-code`. Since Composer 2.10 the audit exits 1 when anything failed it, so a non-zero exit whose JSON lists nothing PROCTOR can read (a malware or policy block, or an error) is itself a fail, and only a clean exit with a clean result passes.

- Fix: address what Composer blocked: the block is real, not a PROCTOR misread.
- Check: run with `--format=json` and read the finding.

### composer-audit fails because an ignore entry has no matching override [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#composer-audit-fails-because-an-ignore-entry-has-no-matching-override)

Rule id `proctor/composer-audit/unbacked-ignore`. The message reads `ignore entry "<entry>" in composer.json has no matching override; record it in policy-overrides.json with a reason and an expiry`: the app silences an audit result in its own composer.json, and no active `composer-audit` override in policy-overrides.json backs that entry. An ignore without a dated approval never expires, so each unbacked entry fails instead of warning.

- Fix: record an active `composer-audit` override in policy-overrides.json that backs the entry: its `advisory` field equals the entry's advisory id case-insensitively, its `package` field matches the entry's package, or the override carries no scope at all (the only shape that backs an ignore-severity level). Remove the ignore entry from composer.json when the override expires.
- Check: rerun; the entry earns no `unbacked-ignore` fail, the count warning (`proctor/composer-audit/ignore-entries`) still reports it, and the backing override is not flagged `proctor/overrides/unused`.

### lock-committed fails [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#lock-committed-fails)

`composer.lock` is missing from the app path.

- Fix: commit the lock file.
- Check: rerun; with the lock present, a `content-hash` that no longer matches composer.json warns (`proctor/lock-committed/out-of-sync`) instead of failing, so regenerate the lock when the warning appears and the warning never changes the exit code.

### destructive-deploy fails [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#destructive-deploy-fails)

A deploy script runs an artisan command that drops or rebuilds the database (`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`), which erases production data on every deploy. Scanned: `.sh` and `.php` files under a top-level `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/` directory, the top-level `build.sh`, `release.sh` and `.gitlab-ci.yml`, and the `scripts` section of composer.json; shell comments are ignored.

- Fix: run plain `php artisan migrate --force` in the deploy.
- Check: rerun; the check passes when no scanned script drops or rebuilds the database.

### php-floor fails and there is no composer.json [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#php-floor-fails-and-there-is-no-composer-json)

Intended behavior: a missing composer.json is not a tool error, so the app gets a verdict, with `php-floor` failing.

- Fix: point `--path` at the application root that carries the composer.json.
- Check: rerun against the right path.

## Warnings that deserve a look [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#warnings-that-deserve-a-look)

### proctor/policy-age [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#proctor-policy-age)

The policy in use is more than 60 days old (its `refreshed_on` date is more than 60 days before `--today`), or a non-bundled policy has no `refreshed_on` date. It exists so a stopped CREED refresh robot is visible instead of silent.

- Fix: refresh the policy: `composer update waynestate/creed` for the vendor copy, or point `--policy` or `PROCTOR_POLICY` at a current file. Warning only: an old policy never fails a run, and the bundled policy may omit the date.

### proctor/overrides/expiring and proctor/overrides/unused [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#proctor-overrides-expiring-and-proctor-overrides-unused)

An active entry in policy-overrides.json that expires within 14 days of `--today` (the expiry day counts) warns `expiring`, so an approval that is about to lapse is visible instead of silent. An active entry that matches no failure warns `unused`, so a stale approval is removed instead of rotting.

- Fix: renew the entry with a new `expires` date and approver, or delete it. A warning never changes the exit code; an expired entry, however, fails the `overrides` check.

### proctor/override-approver/self-approved [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#proctor-override-approver-self-approved)

`approved_by` names someone git blame says wrote a line of the entry: the author name, email, email local part or noreply username. Renewing your own approval counts, because moving `expires` makes you an author of the entry.

- Fix: ask someone who did not write the entry to review the merge request, approve it, and put their name in `approved_by` (on its own line). Do not rewrite history to change the author; the merge request is the record.
- Check: rerun `proctor check`; the pass line says how many entries were checked.

### proctor/override-approver/could-not-run [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#proctor-override-approver-could-not-run)

The message starts `not checked:` and names why: git is not on PATH, the app path is not inside a git checkout, git could not read the repository (for example `detected dubious ownership`), or the clone is shallow and the entry's lines are credited to its oldest commit.

- Fix: run `proctor check` in a full git checkout with git installed. In GitLab CI, set `GIT_DEPTH: 0` on the job. For dubious ownership, mark the checkout safe in the job (`git config --global --add safe.directory "$CI_PROJECT_DIR"`).
- Check: rerun; the finding becomes a pass line or a `self-approved` finding. Under CREED's `override_self_approval: fail`, this finding fails the run.

### proctor/repo-url-credentials [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#proctor-repo-url-credentials)

A credential is written into a package source URL: a deploy token in the userinfo of a composer.json repository URL or a composer.lock source/dist URL, a bare token user of 20+ characters, a `private_token`, `access_token`, `token` or `password` query parameter, or a `.npmrc` line that sets `_authToken`, `_auth` or `_password` to a literal value (`${SOME_VAR}` is fine). The secret is masked as `***` in the message, so the report itself is safe to hand back.

- Fix: move the token to `auth.json`, which is gitignored, or a CI variable, and use one registry URL without credentials.
- Check: rerun; the warning clears when no scanned URL carries a literal credential.

### proctor/ci-pipelines/composer-audit-off [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#proctor-ci-pipelines-composer-audit-off)

The pipeline environment switches Composer's audit or blocking policy off: `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore`, in the GitLab top-level or job `variables:` (a scalar or a map with a `value` key) or the GitHub top-level, job or step `env:`.

- Fix: remove the switch from the pipeline environment. A warning, never a fail, for stack packages and other projects alike.

### A dev-dependency advisory is only a warning [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#a-dev-dependency-advisory-is-only-a-warning)

An advisory whose package composer.lock lists only in `packages-dev` is a warning at any severity, with the message ending in ` [dev dependency]`. It still needs fixing: dev tools run in CI, and a vulnerable test runner is a real attack surface even though it is not in the deployed image.

- Fix: update the dev dependency the way you would any other (`composer update vendor/package --with-dependencies`), or record a dated `composer-audit` override in policy-overrides.json if the upgrade is blocked.
- Check: open `composer.lock` and look for the package name under `packages-dev`; if it also appears under `packages`, it counts as runtime and the advisory fails at or above the threshold as usual.

### portability warnings from bootstrap/cache/ [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#portability-warnings-from-bootstrap-cache)

In a Laravel app (an `artisan` file at the project root) the top-level `bootstrap/cache/` is skipped, so the files `php artisan optimize` writes there (`config.php`, `routes-v7.php`, `services.php`, `packages.php`, `events.php`) do not raise warnings and hide real findings. If you still see them, the app has no `artisan` at the root: a raw-PHP app that keeps its own `bootstrap/cache/` folder is scanned as before.

- Fix: put `artisan` at the project root, or move the generated output out of the scanned directories.
- Check: rerun; the generated cache files stop appearing once the app is recognized as Laravel.

## Results that follow the environment [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#results-that-follow-the-environment)

### unpatched disagrees with the deploy [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#unpatched-disagrees-with-the-deploy)

`--php-runtime` is the PHP version that will serve the app (X.Y.Z), used by the `unpatched` check. CI should pass the deploy image's PHP, not the runner's.

- Fix: pass `--php-runtime` matching the deploy image in CI.
- Check: rerun with the flag; the runtime finding follows the version you pass.

### The same app gives different results on different days [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#the-same-app-gives-different-results-on-different-days)

`--today` overrides today's date (YYYY-MM-DD) so runs are deterministic; `policy-age` and the overrides expiring window both compare against it.

- Fix: pin `--today` in CI so a policy refresh or an override renewal does not change the verdict mid-week.
- Check: rerun twice with the same `--today`; the findings match.

### An offline run reports proctor/wayneui-outdated/skipped [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#an-offline-run-reports-proctor-wayneui-outdated-skipped)

When the lookup cannot run (no composer on PATH, offline, or no parsable versions) the check reports one info finding with the skipped marker and nothing else, so an offline run never breaks.

- No fix needed: the version comparison resumes when the release list can be read, and an offline run never fails in either mode, because an unknown latest is always just the info skipped finding.

## You are on an older PROCTOR [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#you-are-on-an-older-proctor)

Some symptoms were bugs, fixed in a release. Check your version first:

```
composer show waynestate/proctor
```

- Exit 255 with `Allowed memory size exhausted` while scanning a large app: fixed in 0.2.4. Each file's syntax tree is dropped before the next file is parsed, and `bin/proctor` raises a memory limit below 512M to 512M (a higher limit or -1 is left alone).
- Every advisory named `unknown`: fixed in 0.2.8. PROCTOR reads Composer's real audit JSON, and each finding reads `<package> <version>: <CVE or advisory id> <title> (<severity>)`.
- The app's ignore list matched nothing: fixed in 0.2.8. `config.policy.advisories.ignore-id`, `config.policy.advisories.ignore` (by package) and the legacy `config.audit.ignore` are read in both their list and map shapes, matching any of the advisory's ids.
- A `?` in the text report is not corruption: since 0.3.1, terminal control characters in app-supplied strings (finding messages, override reasons and approvers) print as a visible `?`. Clean the string at the source.

## Related [#](https://proctor.apps.wayne.edu/docs/troubleshooting.html#related)

- [Commands](https://proctor.apps.wayne.edu/docs/reference/commands.html)
- [Configuration](https://proctor.apps.wayne.edu/docs/reference/configuration.html)
- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html)
