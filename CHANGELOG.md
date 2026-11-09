# Changelog

## Unreleased

- Added: `crlf-deploy-script` warns (`proctor/crlf-deploy-script/no-gitattributes`) when the app has a deploy script and .gitattributes does not pin `*.sh` to `eol=lf` (CREED practice 34).
- Added: the `entrypoint-migrate` check warns when a container entrypoint runs `artisan migrate` at start; run migrations once per release as a job (WELD W24). It reports, and is not in the deploy gate.
- Added: the `ci-pipelines` check warns (`proctor/ci-pipelines/allow-overrides`) when an app's pipeline sets `PROCTOR_ALLOW_OVERRIDES` to 1, which lets it pass `--policy` and `--today` in CI (BEST_PRACTICES 1.2); any other value leaves the guard on.
- Added: the `app-debug` check warns when a committed production env (`.env.production`, `.env.squire.example`), deploy or CI file sets APP_DEBUG=true (CREED practice 12); `.env.example` is the local-development template and is not read; it reports, and is not in the deploy gate.

## 0.9.0 (2026-11-08)

- Added: the `dev-only-packages` check: Laravel Boost, Debugbar (fail) and Telescope (warning) in `require` install in production and expose app internals, and test tools in `require` (phpunit, Pest, Mockery, Sail, Pail, Collision) are a warning; it reports, and is not in the deploy gate.
- Added: `case-clash` fails when two paths differ only by letter case (`README.md` and `readme.md`), because they collide on macOS and Windows checkouts. It is not part of the deploy gate.
- Added: the `override-approver` check (`proctor check` only, not the gate) flags an override approved by someone who wrote it: git blame names who last wrote each line of an entry, and `approved_by` must not name one of them (`proctor/override-approver/self-approved`); a line holding only `approved_by` is the approver's signature. With no git, no checkout or a line a shallow clone credits to its oldest commit it reports `proctor/override-approver/could-not-run`, never a pass. CREED's `rules.override_self_approval` (`warn` when absent, or `fail`) sets the status. An override cannot target the check or waive its failure. The docs say why the merge request approval is the human control on GitLab Community Edition and this check the tripwire. The front page and README count thirty checks.
- Changed: the deploy layer runs Squire's kit 0.7.1 (was 0.6.0): `.squire/php.ini` no longer sets the deprecated `session.sid_*` lines, web requests get a `disable_functions` list and Apache hardening (`.squire/php-web.ini`, `.squire/apache-hardening.conf`), and opcache gets production settings with `SQUIRE_OPCACHE_VALIDATE` as the one switch.
- Docs: BEST_PRACTICES.md's backlog lists only the practices still open (13, was 38): the stale items for 1.2, 2.5, 2.6, 3.7, 4.3, 4.4, 4.5, 4.11, 4.12, 5.5, 5.6, 7.1, 7.2 and 8.4 are rewritten or dropped, 4.7 and 4.8 are no longer called done, and closed practices leave the list. 4.12 is Partially (composer-audit warns on dev-only advisories since 0.6.0), 4.11 records that Composer 2.11 is stable while the base images still pin 2.10.3, 4.8 says Preflight installs a pinned Composer (2.10.3) while the policy minimum stays 2.8.0, and 1.2 notes that nothing flags an app setting `PROCTOR_ALLOW_OVERRIDES` in its own pipeline. The README, the checks overview and the front page count twenty-nine checks, with case-clash.
- Docs: BEST_PRACTICES.md is current at 0.8.0: thirteen stale "How the stack holds it" lines fixed (override cap, abandoned packages, CI guard, gate, SARIF rule ids, test names), and the tag table lists Partially.
- Changed: the front page deploy layer requires HELMET ^1.0 (lock at 1.0.1, was 0.4.0), like the rest of the stack; its shared front controller already runs on HELMET 1.0 in Squire, Sourdough and Pinstripe.
- Changed: the local a11y scripts run Preflight's own checker (vendored from 0.3.1 and pinned by a test), so they check what CI checks: axe-core 4.14.0, 320 and 1280 px, and the reflow check.
- Added: CI runs Preflight's a11y component (0.3.1) on site/, every page in light and dark at 320 and 1280 px with the WCAG 1.4.10 reflow check, blocking.
- Changed: CI pins the Preflight components to 0.3.1 (fixed cache keys, and caches saved even when a job fails; D-072).
- Changed: CI saves its Composer and npm caches even when a job fails, so a retry after a dropped download fetches only what is missing (D-072).
- Changed: CI caches Composer and npm downloads under a fixed key, so a lock change downloads only what changed (D-072).
- Added: the deploy gate honors CREED's gate stages (PROCTOR-23, D-071): a failure that would block is `staged` when the policy puts its check at `report` or `warn` today, so it is logged and the deploy goes ahead; the JSON summary gains `staged` and the finding `stage`. CREED ships no stages, so today's gate is unchanged. Also re-exported the CREED reader at 0.1.7-38-g66791ef.
- Added: the front page shows the supported versions from the bundled policy, written by `bin/supported-versions.php`; `composer ci` fails when the table is stale (PROCTOR-107).
- Added: an override may run at most 90 days past today (CREED rules.override_max_days); a longer one fails the overrides check (proctor/overrides/too-long) and is not applied.
- Fixed: the docs menu closes with Escape and hands focus back to its button (docs kit synced from Squire).
- Fixed: the docs kit's copy button no longer covers the first line of code when it says Copied or Failed: over the code it keeps its resting width and shows a check or a cross when the word does not fit (docs kit synced from Squire).
- Fixed: the front page's version chip shows the full version the docs show (Form 0.8.0, was Form 0.1). It carries `data-docs-version` now, so the docs builder (synced from Squire) rewrites it at every release and `composer docs:check` fails while it is stale.
- Fixed: the front page lists the gate, node-eol and wayneui-outdated as built, and the check count says twenty-eight.
- Docs: README and BEST_PRACTICES match 0.8.0 (proctor gate, the release phar, tags, the composer-update rule id); RuleIdContractTest now checks the README rule table.
- Changed: the deploy layer requires PROCTOR ^0.8 (locked 0.8.0).
- Fixed: `proctor check` writes a report in every format when a tool error stops the run (exit 2): `text` prints the message as a FAIL line, `json` writes the report shape with `result` set to `error`, an `error` field carrying the message and empty findings, and `sarif` writes a log whose run reports an unsuccessful invocation; before, only `codequality` did. `proctor gate` writes its text verdict on exit 2 too.
- Fixed: BEST_PRACTICES.md dates the unbacked-ignore rule to 0.6.0 and says the PHAR ships with each GitLab Release.

## 0.8.0 (2026-11-08)

- Changed: `preflight-pinned` accepts a Preflight component pinned to a full release tag (`MAJOR.MINOR.PATCH`, optional leading `v`, such as `@0.3.0`) as well as a commit SHA, since published stack tags are never moved or deleted (D-054). A branch, `~latest`, a partial version such as `0.3` or `1`, or no ref still warns, and the warning now says to pin a release tag such as `@0.3.0` or a commit SHA.
- Added: a weekly `creed-copy-refresh` CI job (`scripts/creed-copy-refresh.sh`; schedule variable `PROCTOR_JOB=creed-copy`, or by hand from the web on main) re-exports the generated CREED copy in `src/Creed` from CREED main, rebuilds the docs and agent-ready files, runs `composer creed-copy:check` and `composer ci`, and pushes to main with the job token only when both pass; a red run leaves main untouched.
- Changed: the pipeline pins every Preflight CI component to 0.3.0 (preflight@0.3.0); component image inputs are plain image names, since Preflight adds the Dependency Proxy prefix itself.
- Changed: `mysql-eol` warns on a floating major tag such as `mysql:8` or `mysql:5` (pin a major.minor so it can be graded) instead of staying silent (SQUIRE-21c).
- Tests: the MySQL EOL check proves bare-major tags, the last security-support day and MariaDB images (SQUIRE-21b).
- Changed: the bundled CREED copy in `src/Creed` is refreshed from CREED 0.1.7-21: the policy gains the `sessions` block (SESAME and Drawbridge session lifetimes), and the latest Laravel patches move to 12.71.0 and 13.40.0 (the first policy-bot refresh).
- Added: an ADVISOR module (`Waynestate\Proctor\Advisor\ProctorModule`): a coding agent that runs ADVISOR gains two read-only tools, `proctor_rules` (every rule id, and which are in the deploy gate) and `proctor_check` (PROCTOR's file checks on an app folder, in-process; the subprocess and `.env` checks are skipped and listed).
- Added: `crlf-deploy-script` fails when a deploy script (`build.sh`, `release.sh`, or a `.sh` under `deployments/`, `deploy_scripts/`, `deploy/` or `scripts/`) has Windows line endings, which break it on Linux (SQUIRE-17). It is not part of the deploy gate.
- Changed: the front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer's pins move to HELMET `^0.4` (locked 0.4.0) and PROCTOR `^0.7` (locked 0.7.0).
- Docs: the Docker commands in the README and docs mount the shared `stack-composer-cache` volume, so Composer downloads are cached between runs.
- Changed: `raw-identity-header` also flags direct reads of `X-Sesame-Assertion`, the signed assertion Drawbridge sends; only SESAME's gateway driver should read it.
- Fixed: the README and docs usage line for `proctor check` lists `--timeout` (seconds for `composer audit`, default 300), which the command has accepted all along.
- Fixed: the front page says Sourdough starts all five app shapes with PROCTOR set up; it no longer says raw PHP only.
- Changed: CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.
- Added: `mysql-eol` grades every MySQL or MariaDB version the repository names (an official `mysql` or `mariadb` image on an `image:` line in a root `docker-compose*.yml` or `compose*.yml`, and `SQUIRE_MYSQL_IMAGE=mysql:<version>` in `.env.squire.example` or `.env.example`) against the policy's mysql and mariadb lines as of `--today`, with the same table as php-floor except a supported version stays silent instead of passing: active is silent, security-only warns with the days left, and eol and `eol_grace_expired` warn until Citadel offers a supported MySQL (D-008; rule id `proctor/mysql-eol`). Silent when the policy has no mysql or mariadb component.
- Changed: `mysql-eol` warns, never fails, until Citadel's shared database server offers a supported MySQL (D-008); an end-of-life line still says `eol_grace_expired` and to move to MySQL 8.4 or later (D-053).
- Fixed: the README's `raw-identity-header` entry says a direct `X-Sesame-Assertion` read is flagged (it said it was not), and the front page's GitLab example includes `preflight/proctor@0.3.0` (was `@0.1.1`).

## 0.7.0 (2026-11-07)

- Added: `proctor gate`, the deploy gate (ADR-0006, CREED's deploy-gate chapter). It runs the deploy-time checks (`php-floor`, `unpatched`, `laravel-eol`, `laravel-php`, `lock-committed`, `composer-audit`, `overrides`, `policy-age`) with the app's overrides, ratchets them against `--baseline` (a PROCTOR JSON report, or the running deployment's source directory), prints a short text verdict or JSON (`--format=json`), and exits 0 for go, 1 for no-go and 2 when it could not decide. A failure is blocked on a first deploy (no baseline), on a regression (the baseline passed that rule) or for an expired override of any check; it is held when the baseline failed the same rule id too, and waived when composer-audit had no advisory data. `--record` also writes the JSON verdict to a file that the next run can read as its baseline. `--timeout` defaults to 60 seconds here. Squire's release script consumes it.
- Changed: `proctor check` and `proctor gate` share one list of registered checks and one reader for `--path`, `--policy`, `--today`, `--php-runtime` and `--timeout`; `proctor check` behaves exactly as before.

- Added: `raw-identity-header` warns, never fails, when app code reads an identity header directly instead of taking the user from SESAME: a string key on `$_SERVER` or `$_ENV`, the quoted argument of `getenv()` or `apache_getenv()`, the quoted first argument of the request's `header()`, `server()`, `hasHeader()`, `getHeader()` or `getHeaderLine()` methods (and of `get()` on a `->headers` object), or a key on the result of `getallheaders()` or `apache_request_headers()` (rule id `proctor/raw-identity-header`). Only SESAME's gateway driver may read those headers: it verifies the signed assertion first (CREED auth-3). Warnings only.
- Changed: the stack's CI needs GitLab 18.7 or later; BEST_PRACTICES 8.7 now names the floor, and the install page lists it under Requirements and names git.wayne.edu as the canonical repository.
- Changed: The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Added: The Proof page records its last run.
- Added: `node-eol` grades every Node version the repository pins (`.nvmrc`, `.node-version`, package.json `volta.node`, an official `node` image in `.gitlab-ci.yml` or on a Dockerfile `FROM` line) against the policy's node lines as of `--today`, with the same table as php-floor: active passes, security-only warns with the days left, eol fails, and past the grace window it fails as `eol_grace_expired` (rule id `proctor/node-eol`). `engines.node` warns at worst (`proctor/node-eol/engines`) and an odd major always warns (`proctor/node-eol/odd-major`). Silent when the policy has no node component.
- Note: node-eol can turn a passing run into a failing one: an app that pins an end-of-life Node line (Node 20 or older under the bundled policy, as of 2026-04-30) now fails. Node 20 left security support on 2026-04-30 and is past the grace window, so it fails as `eol_grace_expired`, which no override softens: move to Node 22 or 24.
- Added: `env-outside-config` warns for every `env()` call in a Laravel app outside its config files, which returns null after `php artisan config:cache` (rule id `proctor/env-outside-config`); a call carrying Larastan's ignore marker for the same rule is an info note (`proctor/env-outside-config/suppressed`). Warnings only.
- Added: `app-key` warns on a committed real env file (`proctor/app-key/committed-env`), a real APP_KEY or APP_PREVIOUS_KEYS committed in an env file or `config/app.php` (`proctor/app-key/committed-key`, the key never printed), and the cookie session driver (`proctor/app-key/cookie-sessions`). A real env file is graded only when git lists it or the run is in CI. Warnings only.
- Added: `served-folder` warns when a committed file under `public/` is a dump, backup, editor copy, log, env file or `adminer*.php` (`proctor/served-folder/debug-file`) or calls `phpinfo()` (`proctor/served-folder/phpinfo`). `world-writable` warns on `chmod 777` (and `0777`, `a+rwx`, `ugo+rwx`) in scripts, Dockerfiles, Makefiles, CI and composer or npm scripts, and on a PHP `chmod()` with `0777` (rule id `proctor/world-writable`). Warnings only.
- Added: a new docs page, App checks, for the seven checks above.

- Added: composer-audit applies CREED's `abandoned_packages` rule (rule id `proctor/composer-audit/abandoned`): it passes the policy's value to the audit as `--abandoned`, fails each abandoned package under `fail` (the bundled policy's value and the default), warns under `report` and stays quiet under `ignore`, naming the suggested replacement (`vendor/pkg is abandoned; use replacement/pkg instead`) or saying there is none; an app's `config.policy.abandoned.ignore` (or legacy `config.audit.ignore-abandoned`) entry or a package-scoped override downgrades the fail to a warning. Abandoned packages were always warnings before, so an app that locks one now fails under the bundled policy.
- Added: a committed accessibility gate for the site (`scripts/a11y.sh` on Linux and macOS, `scripts/a11y.ps1` on Windows): axe-core with the WCAG 2.2 AA tag set in a real Chromium browser via Playwright, run in Docker, checks every HTML page under `site/` (the front page and all docs pages) in light and dark mode at 360 px and 1280 px, and any violation fails the run.
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Changed: composer-version now also fails when the Composer on PATH is below the CREED policy's `tools.composer.minimum` (2.8.0 in the bundled policy), naming the policy in the message; the CVE floors (2.2.24 on the 2.2 LTS line, 2.7.7 on every other line) stay as absolute floors underneath it, and a policy without a composer minimum behaves exactly as before.
- Added: ci-pipelines warns when a command segment of a GitLab job script line (`script`, `before_script` or `after_script`) or a GitHub step run starts with `composer update` or `composer require` (rule id `proctor/ci-pipelines/composer-update`): CI must install from the lock (BEST_PRACTICES rule 3.7). A warning, never a fail.
- Added: two checks for apps that lock laravel/framework, reading CREED's Laravel PHP ranges and support dates from the policy: `laravel-eol` classifies the locked line as of `--today` (active passes, security-only warns naming the end date and the days left, eol fails, past the security end date plus the grace window it fails as `eol_grace_expired`; rule id `proctor/laravel-eol`), and `laravel-php` fails when composer.json's `require.php` allows no PHP version inside the policy's PHP range for the locked Laravel major, or allows PHP below the range's floor, and passes otherwise (an open upper bound such as `^8.2` passes; rule id `proctor/laravel-php`). No finding when the lock has no laravel/framework.

## 0.6.0 (2026-11-05)

- This is a pre-1.0 minor: consumers that pin `^0.5` stay on 0.5.x until they move their constraint to `^0.6`. Three changes can turn a passing run into a failing one (see the Note entries below).

- Changed: the docs' dark mode is the exam hall after hours (warm graphite paper with the same faint rules, cream type, a pale green ink for links, gold for Copy, focus and card tops) instead of a dark green page; WSU green stays on the seal and the footer band. On phones, wide tables keep readable columns and scroll in their frame, every table that scrolls shows a soft edge shade, and the stacked pager cards run the full column. Two front-page touches, only when reduced motion is off: the current page's bubble in the docs menu is pencilled in as the page lands, and the pager cards take the red pen's tick on hover (`docs/theme/proctor.css`).
- Fixed: on the front page, a command or printout too wide for its box (the install command, the report carbons, the notes and the hall examples) takes a tab stop while it scrolls sideways, so keyboard users can scroll it, with a gold focus ring on the dark slabs (axe `scrollable-region-focusable`).
- Changed: the docs site wears PROCTOR's brand: the front page masthead and main menu (Docs marked current), ruled paper, Young Serif titles, the install slab with the gold Copy tab, answer-sheet tables, note slips for callouts and the green footer band, with a derived dark mode and motion only when reduced motion is off (`docs/_header.html`, `docs/theme/proctor.css` and a `theme` key in `docs/docs.json`).
- Changed: the vendored docs builder and kit are synced from Squire's unified builder (theme hook, callouts, code tabs, task-list labels, a working Copy button, keyboard-reachable scrolling code blocks).
- Changed: updated the Symfony packages in composer.lock to patched releases (symfony/yaml and symfony/filesystem security advisories, 2026-11-04).
- Changed: composer-audit warns (instead of failing) for advisories on packages that composer.lock lists only in packages-dev, marking the message with ` [dev dependency]`; a name in both lists counts as runtime and still fails at or above the threshold.
- Added: `release-phar` GitLab job, tag-triggered only: it builds `build/proctor.phar` in the pinned `composer:2.10.3` image without Docker-in-Docker, verifies it with `php build/proctor.phar --version`, writes `build/proctor.phar.sha256`, uploads both files to the project's generic package registry under the tag (refusing to overwrite an existing file), and attaches them as asset links to the GitLab Release for the tag via curl and the GitLab API. The phar is published on every release from the next one on.
- Changed: composer-audit fails when the composer executable is not on PATH or the app has no composer.lock (rule id `proctor/composer-audit/skipped`, message starting with `not audited:`); an active `composer-audit` override in policy-overrides.json with a reason and an expiry downgrades those fails to warnings.
- Note: this is a pre-1.0 behavior change: consumers on `^0.5` that have no lock, or run where Composer is not on PATH, will now fail until the audit can run or an override is recorded.
- Changed: composer-audit fails each ignore entry in composer.json that no active `composer-audit` override backs (rule id `proctor/composer-audit/unbacked-ignore`); an override backs the entry when its `advisory` field equals the entry's advisory id case-insensitively, its `package` field matches the entry's package, or it carries no scope at all, and a backing override is not reported unused.
- Note: this is a pre-1.0 behavior change: consumers on `^0.5` that carry audit ignore entries in composer.json without matching overrides will now fail until each entry is backed.
- Changed: `--policy` and `--today` are refused in CI (any of `CI`, `GITLAB_CI` or `GITHUB_ACTIONS` set to a value other than `0` or `false`) with a tool error (exit 2) unless the job sets `PROCTOR_ALLOW_OVERRIDES=1`: in CI the policy and the date must come from the stack, not from the app being checked, so a pipeline that could pass its own policy or date could grade itself. Pre-1.0 behavior change for consumers on ^0.5 whose pipelines pass these options: set `PROCTOR_ALLOW_OVERRIDES: "1"` in a trusted job (a protected or group-level CI variable, not the app's .gitlab-ci.yml), or drop the option and name the policy with `PROCTOR_POLICY`, which stays allowed in CI.

## 0.5.0 (2026-11-03)

- This is a pre-1.0 minor: consumers that pin `^0.4` stay on 0.4.x until they move their constraint to `^0.5`. One check that used to pass now fails: composer-audit fails when the audit produced no parseable output (see the first Changed entry below).

- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Changed: composer-audit fails when the audit runs but its stdout is not parseable JSON (rule id `proctor/composer-audit/could-not-run`), with a message that starts with `not audited:` and carries the first stderr line; a green run that audited nothing is worse than a red one.

- Added: composer-config warns when composer.json switches Composer's dependency policy off: `config.policy` set to `false` (rule id `proctor/composer-config/policy-off`), `policy.malware` set to `false` (rule id `proctor/composer-config/policy-malware-off`) or `policy.advisories` set to `false` (rule id `proctor/composer-config/policy-advisories-off`). A warning, never a fail.
- Added: ci-pipelines warns when the pipeline environment switches Composer's audit or blocking policy off: `COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` set to anything but an empty string, `0` or `false`, or `COMPOSER_AUDIT_ABANDONED` set to `ignore` (rule id `proctor/ci-pipelines/composer-audit-off`). A warning, never a fail.
- Added: the composer-audit ignore-entries count now also reads `config.policy.advisories.ignore-severity` and `config.policy.abandoned.ignore`, plus the legacy fallbacks `config.audit.ignore-severity` and `config.audit.ignore-abandoned` while their matching `config.policy` block is absent (rule id `proctor/composer-audit/ignore-entries`). A warning, never a fail.
- Added: docs site built from docs/ with generated command and environment reference
- Added: a composer-audit override can name the one advisory or package it covers with the optional `advisory` and `package` fields of policy-overrides.json (case-insensitive, `*` wildcard on the package, both present means both must match), so a scoped override downgrades only the matching failures; on any other check, or with a value that is not a non-empty string, the file is rejected (exit 2), and a scoped override that matches no failure earns the unused warning with its scope in the text.

## 0.4.0 (2026-11-03)

- This is a pre-1.0 minor: consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The new checks warn and never fail.

- Added: composer-audit warns when composer.json carries audit ignore entries, reporting only their count so they move to the dated override file (rule id `proctor/composer-audit/ignore-entries`). A warning, never a fail.
- Added: overrides warns when an active entry in policy-overrides.json matches no failure (rule id `proctor/overrides/unused`), so a stale approval is removed instead of rotting. A warning, never a fail.
- Added: unbounded-constraints warns on a repository URL that is plain http, because a network attacker can then change what Composer downloads (rule id `proctor/unbounded-constraints/insecure-url`). A warning, never a fail.
- Added: unbounded-constraints warns when composer.json sets minimum-stability below stable (dev, alpha, beta or rc), because every package may then resolve to a pre-release (rule id `proctor/unbounded-constraints/minimum-stability`). A warning, never a fail.
- Fixed: composer-audit no longer passes when Composer's own audit failed: a non-zero exit code with no advisory, ignored-advisory or abandoned finding in the JSON is now a fail (rule id `proctor/composer-audit/exit-code`).
- Added: preflight-pinned warns when a Preflight component is included at a ref that is not a commit SHA, or when an app overrides the script of an included Preflight job.
- Added: lock-committed warns when composer.lock content-hash differs from composer.json.
- Fixed: the report prints raw, so a package name or constraint that looks like a formatting tag is shown as written.
- Fixed: BEST_PRACTICES tags match the code (nine stale "Not yet" tags re-tagged, the pipeline claim in 3.1 corrected).
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.
- Fixed: the BEST_PRACTICES gap list marks the items already built.
- Fixed: BEST_PRACTICES tags for 4.5, 4.7, 5.6, 6.4 and 8.3 match the code.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- Fixed: BEST_PRACTICES tags for 1.3 and 8.1 match the code.

## 0.3.1 (2026-11-02)

- Fixed: mysql56 no longer warns on HTTP ->json() calls, or on long strings that are not indexed or unique.
- Changed: composer audit runs with --no-plugins --no-interaction and a minimal explicit environment (PATH plus the variables composer needs to start), so inherited COMPOSER_* switches no longer reach it.
- Fixed: text output strips terminal control characters from app-supplied strings (finding messages, override reasons and approvers); each one prints as a visible ?.

## 0.3.0 (2026-11-01)

- Added: wayneui-forked-views warns when forked WayneUI views or assets are committed in the app.
- Added: wayneui-outdated warns when the locked or copied WayneUI is behind the latest release. A new opt-in setting, `extra.proctor."wayneui.copies"`, can make a stale committed `wayne-ui.css` copy fail instead of warn.
- Added: instant-client-committed warns when Oracle Instant Client (instantclient zips or folders, libclntsh and similar libraries) is committed to the repo, with its size and the git rm fix. A warning, not a failure.
- Fixed: a wildcard package ignore pattern (for example laravel/*) now hides audit findings the way Composer does.

## 0.2.8 (2026-10-30)

- Fixed: composer-audit findings named every advisory "unknown". PROCTOR now reads Composer's real audit JSON, and each finding reads `<package> <version>: <CVE or advisory id> <title> (<severity>)`; the JSON report carries the advisory details.
- Fixed: the app's ignore list matched nothing. `config.policy.advisories.ignore-id`, `config.policy.advisories.ignore` (by package) and the legacy `config.audit.ignore` are read in both their list and map shapes, matching any of the advisory's ids.
- Fixed: a package ignore rule hides findings only the way Composer would: not when it sets `on-audit: false` (a block-only unblock, such as Booster's temporary one), and, when it carries a `constraint`, only for locked versions that satisfy it. Wildcard package patterns are not matched yet, so those findings stay visible.

- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire, and SQUIRE_PROCTOR=enforce can stop a release on failing checks).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## 0.2.7 (2026-10-29)

- Added: ci-pipelines skips the GitHub workflow for a private stack package that sets extra.proctor.github-mirror to false.
- Changed: project names written as words except acronyms.
- Fixed: stale capability claims in the site and docs.
- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

## 0.2.6 (2026-10-29)

- Changed: the proof page moves to a private site that needs a Wayne State sign-in.
- `portability`: stops flagging Laravel's generated cache. In a Laravel app (an `artisan` file at the project root) the top-level `bootstrap/cache/` directory is now skipped, so the files `php artisan optimize` writes there (config.php, routes-v7.php, services.php, packages.php, events.php) no longer raise warnings and hide real findings. A raw-PHP app that keeps its own `bootstrap/cache/` folder is scanned as before.

## 0.2.5 (2026-10-29)

- CREED reader re-exported from CREED 0.1.2: versions older than the oldest listed line are now end of life instead of refused.

## 0.2.4 (2026-10-29)

### Fixed

- A scan of a large app no longer exits 255 with "Allowed memory size exhausted": each file's syntax tree is dropped before the next file is parsed, and `bin/proctor` raises a memory limit below 512M to 512M (a higher limit or -1 is left alone).
- Reports carry the real version: JSON, SARIF and text read the installed `waynestate/proctor` version from Composer, with `0.2.3` as the fallback, instead of the stale `0.1.0`.

## 0.2.3 (2026-10-29)

- `overrides` guards (PROCTOR-6, PROCTOR-33): an entry in policy-overrides.json that names a check that is not registered in the run, or names the `overrides` check itself, is now a tool error (exit 2) instead of being silently accepted; the valid ids are built from the registered checks. An active override that expires within 14 days of `--today` (the expiry day counts) now warns (`proctor/overrides/expiring`); a warning never changes the exit code.

## 0.2.2 (2026-10-29)

- `composer-config`: new check (PROCTOR-17, PROCTOR-18): warns when composer.json sets `allow-plugins` to `true`, `secure-http` to `false`, `lock` to `false` or `audit.block-insecure` to `false`, and notes when `require.php` is set without `config.platform.php`. Warning and info only: never a fail, so an app that passes today keeps passing.

## 0.2.1 (2026-10-29)

- PHPStan now includes Pinstripe's raw preset (`vendor/waynestate/pinstripe/phpstan/raw.neon`) instead of a hand copy of its parameters, so level 8, the ignore-reporting flags and the deprecation checks follow the coding standard from one place. PROCTOR keeps only its own paths and PHP version range. The new deprecation rules caught one call: `bin/proctor` now registers its command with `Application::addCommand()`, because `add()` is deprecated since Symfony 7.4.
- The CREED policy now resolves without `--policy`: the option, then the `PROCTOR_POLICY` environment variable, then the app's `vendor/waynestate/creed/policy/policy.json` (when at least as fresh as the bundled copy), then the policy bundled with PROCTOR. Every report names the policy in use (origin, path, `refreshed_on`, age in days); the `json` format gains a `policy` object and the `text` format a policy line.
- `policy-age`: new warning check (`proctor/policy-age`): warns when the policy in use is more than 60 days old, or when a non-bundled policy has no `refreshed_on` date. Warning only: an old policy never fails a run.
- An app with no composer.json now gets a verdict (php-floor fails) instead of exit 2.

## 0.2.0 (2026-10-29)

- `repo-url-credentials`: new warning check (D-034): flags credentials written into package source URLs in
  composer.json repository entries, composer.lock source/dist URLs, and .npmrc auth lines. The secret is masked as
  `***` in the message. Fix by moving the token to `auth.json`, which is gitignored, or a CI variable, and using
  one registry URL without credentials.
- Rule ids: every finding now carries a stable, namespaced rule id (`proctor/<check-id>` for a check's default
  finding, `proctor/<check-id>/<rule>` for a named rule). It is exposed additively as `rule_id` in the `json`
  format, as `ruleId` in `sarif`, and as `check_name` in `codequality` (previously the check id). The full set of
  45 rule ids is documented in the README; `tests/RuleIdContractTest.php` keeps the table in step with the checks.
- `portability`: PHP files are matched on the syntax tree (nikic/php-parser with names resolved) instead of line by
  line regular expressions. Comments and strings no longer trigger a rule, calls split over lines are caught,
  and `use function ... as ...` aliases resolve. Rules live as data in `Waynestate\Proctor\Code\BannedPatterns`.
- `portability`: a call through a variable (`$f()`) is reported as an info "cannot analyse" note, once per file.
- `portability`: the parse-error warning now names the file ("could not parse src/Foo.php: ...").
- Docs: `BEST_PRACTICES.md` names `Policy::fromFile()` instead of the deleted `JsonFilePolicySource`.
- `vendor/bin/proctor` now works when PROCTOR is installed as a dependency (it finds the host app's autoloader).

## 0.1.0 (2026-10-27)

- First tagged release.
