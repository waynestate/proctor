# PROCTOR best practices

Written against PROCTOR as of 0.8.0 and checked against 0.11.0 on 2026-11-09 (the truth pass); later changes are in CHANGELOG.md.
Items marked "(second pass)" were added or changed in the second pass; see the section of that name near the end.

## What this covers

PROCTOR is the stack's one command for measuring a PHP app against the WSU policy file (`policy.json` from CREED):
`vendor/bin/proctor check`. It looks at the PHP floor, patch currency, unbounded Composer constraints, a committed
lock file, `composer audit` results and dated overrides. It prints text, JSON or GitLab Code Quality.

This guide is for two groups:

- **WSU developers** who run PROCTOR on an app, read its output, and write overrides.
- **PROCTOR maintainers** who add checks, change output, or decide how the gate behaves.

It lists practices for this domain: dependency and version policy checks, and the design of a checker that runs in CI.
Each practice says what to do, why, where the idea comes from, and how the stack holds it today.

PHP that has reached end of life is not supported. PROCTOR treats it as a failure, and so does the rest of the stack.

### The five tags

| Tag | Meaning |
|---|---|
| `Enforced` | Code makes the bad outcome impossible, or the build fails. The entry names what. |
| `Checked` | PROCTOR or CI warns or fails. The entry names the check. |
| `Documented` | Written down only. Nothing fails if it is ignored. |
| `Partially` | Part of it is Enforced or Checked; the entry names the part that is not. |
| `Not yet` | A gap. Neither code nor text covers it today. |

Tags come from the PROCTOR repo (`src/`, `tests/`, `README.md`, `bin/proctor`) and the stack design documents as read
on 2026-10-27. Where a design document describes something that is not built, the tag is `Documented` or `Not yet`.

---

## 1. Policy as data

### 1.1 Keep dates and thresholds in the policy file, not in code
Support dates, the grace window and the audit severity threshold are read from the policy file at run time. The tool
holds no dates. A floor ahead of every listed line passes as active, one below the oldest listed line fails as end of
life, and any other unknown version is a failure.

Why: one file changes when a version's dates change, and every tool agrees.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's version-policy decision.

**How the stack holds it: Enforced.** `Policy::fromFile()` derives the state of each version from the file, and
`PolicyStateTest` pins the boundary days. A floor ahead of every listed line passes as active
(`src/Creed/Policy.php:147`), a floor below the oldest listed line fails as end of life (`src/Creed/Policy.php:150`),
and a version that matches neither raises `Unknown version of <component>: <version>`
(`src/Creed/Policy.php:153`), which the php-floor check reports as a failure (`src/Check/PhpFloorCheck.php:60`).
The tool holds no dates.

### 1.2 Take the policy and the date from a source the app cannot change
In CI the policy file and "today" must come from the stack, not from the app being checked. If the app can pick
`--policy` or `--today`, it can pass itself.

Why: a gate that the checked party configures is not a gate.

Sources: [OWASP Top 10 CI/CD Security Risks, CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/);
[GitLab: how included configuration is merged](https://docs.gitlab.com/ci/yaml/includes/).

**How the stack holds it: Partially.** Since 0.2.1, `--policy` is optional: without it PROCTOR resolves the policy from
the `PROCTOR_POLICY` environment variable, then the app's vendored copy (when at least as fresh as the bundled copy),
then the bundled copy, and every report names the policy in use. Since 0.6.0, in CI `--policy` and `--today` are
refused unless `PROCTOR_ALLOW_OVERRIDES` is exactly `1` (`src/Policy/CiGuard.php`), while `PROCTOR_POLICY` stays
allowed. Preflight's `proctor_command` input is free text, so `preflight-gates` (Unreleased) warns
(`proctor/preflight-gates/proctor-command`) when it runs neither `proctor check` nor `proctor gate`, or pipes PROCTOR
or follows it with `||` so a failing check passes; `preflight-pinned` warns when an app re-declares an included
Preflight job's script, and `preflight-gates` when it sets `allow_failure` on a gate (on the job or in one of its
`rules:`), turns the gate off with `when: never` (`proctor/preflight-gates/skipped`) or leaves it optional with
`when: manual` (`proctor/preflight-gates/manual`). What remains: an app can still change the vendored policy, and
override other job keys (the image, the variables). An app that sets
`PROCTOR_ALLOW_OVERRIDES` to `1` in its own pipeline is flagged: the `ci-pipelines` check warns
(`proctor/ci-pipelines/allow-overrides`) when the GitLab or GitHub pipeline environment sets it. A warning, not a
fail: PROCTOR's own pipeline sets it on purpose, and the right home for it (a protected or group-level variable) is
outside the file, where no check can see it.

### 1.3 Version the policy schema and read newer files without breaking
A checker should accept a policy file with a newer minor schema or extra fields and ignore what it does not know.
Rejecting any other version breaks every pinned older checker on the day the policy gains a field.

Why: the stack's version policy says older checkers must still read newer policy files.

Sources: the stack's version-policy decision; [GitLab Code Quality format: only the listed fields are processed](https://docs.gitlab.com/ci/testing/code_quality/)
(an example of a reader that ignores extras).

**How the stack holds it: Checked.** `Policy::__construct` (the generated `Waynestate\Proctor\Creed\Policy`) only
requires `schema_version` to be present, takes any value, reads `components`, `tools` and `rules`, and ignores every
other key, so a pinned older checker still reads a newer policy file and the check runs normally. A policy with no
`schema_version` is still refused. Tests: `tests/PolicyVersionTest.php` (`testOlderReaderAcceptsNewerSchemaVersionAndUnknownKeys`,
`testCheckRunsNormallyWithNewerPolicy`) load `tests/fixtures/policy-newer.json` (schema_version 2 plus unknown keys)
and pass.

### 1.4 Read the banned-constraint list from the policy
The policy has `rules.banned_constraints`. The check must use it, so a change to the rule needs no PROCTOR release.

Why: otherwise the policy file says one thing and the tool does another.

Sources: the stack's version-policy decision.

**How the stack holds it: Checked.** `UnboundedConstraintsCheck` reads the policy's `rules.banned_constraints` as glob
patterns, in addition to `*`, `dev-` branches, `@dev` flags and open upper bounds.

---

## 2. The PHP floor

### 2.1 Do not declare a PHP version that is past security support
The lowest PHP version an app allows must still be supported. Active is a pass, security-only is a warning, and end of
life is a failure.

Why: an end-of-life branch gets no security fixes, and a declared floor decides what Composer will install.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php).

**How the stack holds it: Checked.** `php-floor` fails on `eol` and `eol_grace_expired`, warns on `security`.
On 2026-10-27 PHP 8.2 is security-only (ends 2026-12-31), 8.3 ends 2027-12-31, 8.4 ends 2028-12-31 and 8.5 ends
2029-12-31. (Second pass: these dates match php.net as read on 2026-10-27, and match the endoflife.date PHP API.
PHP 8.4 leaves active support on 2026-12-31, so from 2027-01-01 an app with an 8.4 floor gets the security-only
warning.)

### 2.2 Compute the floor from the constraint, not from the PHP that runs the check
Take the lowest version the `require.php` constraint allows, and reduce it to major and minor. Do not use the version
of the PHP binary that happens to run the tool.

Why: `^8.2 || ^8.4` and `>=7.4` both have a floor that a running-PHP check would miss.

Sources: [Composer: versions and constraints](https://getcomposer.org/doc/articles/versions.md).

**How the stack holds it: Enforced.** `PhpFloorCheck` parses the constraint with `composer/semver` intervals and takes
the start of the first interval. A missing or empty constraint, or one that allows no stable version, fails.

### 2.3 Keep a short grace window, then allow no exceptions
After end of life there is a fixed number of days in which the failure can still be waived with a dated override. After
that the failure stands.

Why: it gives owners a bounded, visible path to upgrade, and it ends.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's version-policy decision.

**How the stack holds it: Enforced.** `rules.eol_grace_days` sets the window. `OverrideApplier` never downgrades a
failure in the `eol_grace_expired` state, whatever overrides the app carries.

### 2.4 Set `config.platform.php` to the floor
When the lock is resolved against the lowest supported PHP, it cannot contain a package that needs a newer one. Without
it, a lock built on PHP 8.5 may not install on the floor.

Why: Composer's `platform` setting fakes the PHP version for resolution, and the docs advise checking platform
requirements at deploy time because the setting weakens dependency checks.

Sources: [Composer config: platform](https://getcomposer.org/doc/06-config.md).

**How the stack holds it: Checked.** `composer-config` notes (info) when `require.php` is set and `config.platform.php`
is not, so the gap shows on every run.

### 2.5 Check the PHP that will serve the app, not only what composer.json declares
The deploy image is the real floor. A declared `^8.4` on an app that deploys on an older image proves nothing.

Why: declared and actual versions drift.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's deploy-gate decision.

**How the stack holds it: Checked.** `proctor gate` ships (registered in `bin/proctor`, implemented in
`src/Console/GateCommand.php`): it runs the deploy-time checks, ratchets them against `--baseline`, and exits 0
for go, 1 for no-go and 2 when it could not decide. Its `--php-runtime` names the PHP that will serve the app,
and since Squire 0.9.0 Squire's release script passes it by default (`SQUIRE_PHP_RUNTIME`: `auto`, the PHP that
runs the script, or `skip`, or an `X.Y.Z`). Two checks grade it: `unpatched` for patch currency, and `php-floor`
(Unreleased) against `require.php` and the policy. A runtime below the constraint fails
(`proctor/php-floor/runtime-mismatch`), one above it warns, and one inside it passes when active and warns when
security-only or end of life (`proctor/php-floor/runtime`; end of life only warns because the floor already fails
then). Test: `tests/PhpFloorRuntimeTest.php`. The gate is
platform-neutral: on Citadel, Squire's release script (`scripts/release.sh`) calls it on every release (a report,
not a stop, unless `SQUIRE_PROCTOR=enforce`), and other hosts run the same gate as a CI job.

### 2.6 A tool must pass its own check
The stack's own repos use the same policy as apps. A floor that PROCTOR would fail must be raised in a new major
before the date.

Why: a tool that cannot meet its own rule has no standing to enforce it.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's version-policy decision.

**How the stack holds it: Partially.** PROCTOR's own pipeline includes the Preflight `proctor` component; it checks
`deploy/` against CREED's policy and blocks the pipeline when that check fails. What remains: PROCTOR requires
`php: ^8.3`, and its pipeline tests 8.3, 8.4 and 8.5. PHP 8.3 is security-only and reaches end of life on 2027-12-31,
so the floor must move to `^8.4` before then or the floor check fails on PROCTOR itself.

### 2.7 Put the end date in the security-only warning (second pass)
A warning that says "security-only" gives the owner no deadline. Print the date security support ends and the days left.

Why: the owner can plan from a date. A bare warning is easy to leave for later, and 8.3 is already in this state and
8.4 joins it on 2027-01-01.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php).

**How the stack holds it: Checked.** `PhpFloorCheck` prints the end date and the days left, e.g. "php floor 8.3 is
in security-only support until 2027-12-31 (430 days left)": the date is the policy file's `security_until`, the days
are the whole days from the run's date (`--today`) to it, with "1 day left" for one and "ends today" for zero.
`PhpFloorCheckTest` pins the mid-window, one-day and last-day messages.

---

## 3. Dependency constraints and the lock file

### 3.1 Allow no `*` and no `dev-` branches in runtime requirements
A runtime package must be tied to a released version range.

Why: `*` and branch constraints let any commit or release into production on the next update, with no review.

Sources: [Composer: versions and constraints](https://getcomposer.org/doc/articles/versions.md).

**How the stack holds it: Checked.** `unbounded-constraints` fails for each `require` entry that is `*` or contains a
`dev-` token, including `dev-master as 1.0` and `dev-master || ^2.0`. `require-dev`, `php`, `ext-*` and `lib-*` are
skipped.

### 3.2 Treat open upper bounds, `@dev` flags and dev stability as unbounded too
`>=1.0` has no upper limit, and the Composer docs warn that it can pull in breaking releases. A flag such as `^1.0@dev`
or `minimum-stability: dev` opens the same hole by another route.

Why: the same risk as 3.1 with different spelling.

Sources: [Composer: versions and constraints](https://getcomposer.org/doc/articles/versions.md);
[Composer basic usage: stability](https://getcomposer.org/doc/01-basic-usage.md).

**How the stack holds it: Checked.** `unbounded-constraints` fails for each `require` entry whose constraint is `*`,
names a `dev-` branch, carries an `@dev` flag, has a lower bound with no upper bound (`>=1.0`), or matches one of the
policy's `banned_constraints` patterns. A `minimum-stability` below stable (`dev`, `alpha`, `beta` or `rc`) is a
warning (`proctor/unbounded-constraints/minimum-stability`), because every package may then resolve to a pre-release.
`require-dev` is still not looked at.

### 3.3 Reject path and non-HTTPS repositories in committed files
A `path` repository points at a local folder that other machines and CI do not have. A plain-HTTP repository can be
tampered with in transit.

Why: both make the lock file unreproducible or unsafe.

Sources: [Composer config: secure-http](https://getcomposer.org/doc/06-config.md); the stack's one-repo-per-project decision.

**How the stack holds it: Checked.** The `unbounded-constraints` check warns on committed `path` and `vcs`
repositories (rule id `proctor/unbounded-constraints/repository`) and on a repository URL that is plain http
(rule id `proctor/unbounded-constraints/insecure-url`); warnings, not failures.

### 3.4 Commit `composer.lock` and install from it
Applications and libraries both commit the lock so everyone installs the same versions.

Why: without it, each install resolves fresh and can pick up a release nobody reviewed.

Sources: [Composer basic usage: the lock file](https://getcomposer.org/doc/01-basic-usage.md).

**How the stack holds it: Checked.** `lock-committed` fails when `composer.lock` is absent from the checked path. A lock on
disk is not proof that it is committed: the `proctor` CI component runs `composer install` first, and Composer writes a
lock when none is committed. So `lock-tracked` asks git and warns (`proctor/lock-tracked`) when the lock is on disk but
git does not track it, git-ignored included. It runs git, so ADVISOR's in-process `proctor_check` skips it, and
outside a git repository it says nothing.

### 3.5 Require the lock to match `composer.json`
A lock whose content hash no longer matches `composer.json` describes a different tree from the one declared.

Why: an audit of a stale lock reports on packages the next install will not use.

Sources: [Composer CLI: validate and install](https://getcomposer.org/doc/03-cli.md);
[Composer basic usage](https://getcomposer.org/doc/01-basic-usage.md).

**How the stack holds it: Checked.** `lock-committed` warns (`proctor/lock-committed/out-of-sync`) when the lock's
`content-hash` differs from the hash PROCTOR computes from composer.json with Composer's own algorithm (the relevant
keys plus `config.platform`, sorted and md5-hashed), so a stale lock that still installs is visible instead of silent.
Warning only: a stale lock never fails a run. (Second pass note kept: the Composer 2.11.0-RC1 changelog, dated
2026-10-22, says a change to the `policy` config now marks the lock as outdated. RC1 is not a stable release, and
`policy` is not part of the hash input this check reads.)

### 3.6 Make Composer configuration explicit and safe
Do not set `allow-plugins` to `true`. Do not turn off `secure-http` or TLS. Leave `lock` on.

Why: plugins run code during install, and an open allow-list lets any dependency add one.

Sources: [Composer config: allow-plugins, secure-http, lock](https://getcomposer.org/doc/06-config.md).

**How the stack holds it: Checked.** `composer-config` warns when `allow-plugins` is `true`, `secure-http` is `false`,
`lock` is `false` or `audit.block-insecure` is `false`. Warning only: an app that passes today keeps passing.

### 3.7 Install from the lock in CI and never re-tag a release (second pass)
In CI run `composer install`, which reproduces the locked versions. Do not run `composer update` in a deploy pipeline.
Package authors publish a new version instead of moving an existing tag.

Why: the 2026 Packagist attacks (laravel-lang on 2026-05-22 and intercom-php) worked by changing existing git tags.
Packagist says `install` reproduces the locked commit hashes, while `update`, `require` and `update -W` refresh metadata
and can rewrite lock entries even when a version was re-tagged. OWASP's 2025 Top 10 now lists "Software Supply Chain
Failures" as A03 and notes that only 11 CVEs map to its CWEs, so an advisory-only audit will miss most of it.

Sources: [Packagist: an update on Composer and Packagist supply chain security, 2026-05-27](https://blog.packagist.com/an-update-on-composer-packagist-supply-chain-security);
[OWASP Top 10 2025, A03 Software Supply Chain Failures](https://top10.owasp.org/2025/A03_2025-Software_Supply_Chain_Failures).

**How the stack holds it: Partially.** The Preflight `proctor` component runs `composer install`, so it uses the lock.
PROCTOR's `ci-pipelines` check warns (`proctor/ci-pipelines/composer-update`) when a command segment of a GitLab job
script line or a GitHub step run starts with `composer update` or `composer require`. It reads `.gitlab-ci.yml` with
the local files it includes (Unreleased) and every workflow in `.github/workflows`. What remains: the finding is a
warning, not a fail, and remote, project and component includes are not read.

### 3.8 Keep locked components on the latest patch
A locked component that is behind the policy's `latest_patch` for its version line is a warning, not a failure: a
patch may not be urgent, and the owner decides when to move. The policy carries `latest_patch` and
`latest_patch_checked` per version line, so currency is a policy decision, not a hard-coded one.

Why: a patch release usually carries security fixes, and a lock that lags the latest patch is a known, bounded risk.
Warning (not failing) keeps the gate from blocking on a patch that is not yet urgent.

Sources: the stack's version-policy decision.

**How the stack holds it: Checked.** `unpatched` reads laravel from composer.lock (`laravel/framework`), vue and
inertia from package-lock.json (`vue`, `@inertiajs/vue3`), and the PHP runtime from `--php-runtime`, and warns when a
locked version is below the policy's `latest_patch` for its major.minor line. No finding when the component, its
version line, or `latest_patch` is absent from the policy.

---

## 4. Advisories and the audit

### 4.1 Audit the lock and fail at the policy's severity threshold
Run `composer audit --locked` and compare each advisory with `rules.audit_severity_threshold`. Fail at or above it and
warn below it.

Why: a known vulnerability in a locked package is the most direct risk a dependency check can find.

Sources: [Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Checked.** `composer-audit` runs `composer audit --format=json --locked` and decides from the
JSON, not from the exit code alone. The threshold must be one of `low`, `medium`, `high`, `critical` or the run ends
with a tool error.

### 4.2 Count an unknown severity as failing
An advisory with no severity, or one the tool does not recognize, is treated as if it were at the threshold.

Why: unknown input must not turn into a pass.

Sources: [Semgrep: blocking findings and errors](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci)
(a tool that fails on findings and states how it treats errors; second pass: that page says nothing about unknown
severities, so it is an analogy only and not a source for this rule).

**How the stack holds it: Enforced.** `isAtOrAboveThreshold` returns true for a null or unknown severity.

### 4.3 Never let a skipped audit look like a pass
If `composer` is not on the PATH, the lock is missing, or the advisory source cannot be reached, the result must say
"not audited" and, in CI, fail unless the owner chose otherwise.

Why: a green run that audited nothing is worse than a red one.

Sources: [Composer CLI: audit (`--ignore-unreachable`)](https://getcomposer.org/doc/03-cli.md);
[OpenSSF Scorecard: Vulnerabilities](https://github.com/ossf/scorecard/blob/main/docs/checks.md).

**How the stack holds it: Checked.** Unparsable audit output fails (rule id `proctor/composer-audit/could-not-run`)
with a message that starts with `not audited:` and carries the first stderr line, so a run whose audit produced nothing
readable is red instead of green. A missing `composer` or a missing lock fails the same way (rule id
`proctor/composer-audit/skipped`, message `not audited: ...`). The owner chooses otherwise by recording a
`composer-audit` entry in `policy-overrides.json` with a reason and an expiry, which turns the failure into a warning
until it expires (the stack maintainer's decision of 2026-11-03). Preflight's `composer-audit` job also fails on Composer's own exit code.

### 4.4 Keep advisory ignores out of `composer.json`
Composer lets an app silence advisories in its own `composer.json` (`config.audit.ignore`, or in Composer 2.10
`config.policy.advisories.ignore-id`). Those ignores have no expiry and are invisible to the override file.
(Second pass: `audit.ignore` is now marked deprecated in the Composer docs. It takes a plain list, a map of id to
reason, or a detailed form with an `apply` value of `audit`, `block` or `all`. `ignore-severity` and `ignore-abandoned`
do the same job for whole severities and packages, so a check must look at all three.)

Why: they bypass the dated, visible override process the policy depends on.

Sources: [Composer config: audit and policy](https://getcomposer.org/doc/06-config.md);
[Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/).

**How the stack holds it: Checked.** PROCTOR runs `composer audit` and acts on its output: an ignore that matches
an advisory still downgrades that advisory to a warning, and a count warning (rule id
`proctor/composer-audit/ignore-entries`) reports how many ignore entries the app keeps in `composer.json`
(`config.policy.advisories.ignore-id`, `config.policy.advisories.ignore`, `config.policy.advisories.ignore-severity`,
`config.policy.abandoned.ignore`, or the legacy `config.audit.ignore`, `config.audit.ignore-severity` and
`config.audit.ignore-abandoned` while their matching `config.policy` block is absent), never naming an id, package
or reason, so they move to the dated override file. Since 0.6.0, each entry must also be backed by an active `composer-audit` override (rule id `proctor/composer-audit/unbacked-ignore`): an unbacked entry fails, a backed one warns. Tests: `tests/IgnoreBackingTest.php`.
Tests: `tests/ComposerAuditCheckTest.php` (`testIgnoreEntriesInComposerJsonAreReportedAsOneWarningWithoutNamingThem`,
`testSeverityAndAbandonedIgnoresAreCountedInTheIgnoreEntriesWarning`, `testLegacyAuditSeverityAndAbandonedIgnoresAreCounted`,
`testAuditFallbacksAreIgnoredWhenThePolicyBlockExists`).

### 4.5 Cover malware and abandoned-package results
Composer 2.10 audits malware by default. Abandoned packages also fail the audit by default: the Composer docs give
`abandoned.audit` a default of `fail` (the `audit.abandoned` default moved from `report` to `fail` in Composer 2.7).
A policy check should either read those results or say it does not.

Why: the advisory list is no longer the only thing `composer audit` can fail on. (Second pass correction: the first
pass said 2.10 "reports" abandoned packages. By default it fails on them, so an app with an abandoned package gets a
red `composer audit` and, today, a green PROCTOR.)

Sources: [Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/);
[Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Checked.** `ComposerAuditCheck` reads `advisories`, `ignored-advisories` and `abandoned` from
Composer's JSON. Abandoned packages follow the policy's `abandoned_packages` rule (since 0.7.0, default `fail`): under
`fail` each package the app does not ignore is a failure (rule `proctor/composer-audit/abandoned`), under `report` a
warning, under `ignore` nothing. Malware entries are caught by the exit-code finding (rule
`proctor/composer-audit/exit-code`) when Composer exits non-zero and the JSON carries nothing readable. Tests:
`tests/ComposerAuditCheckTest.php` (`testAbandonedPackageWithAReplacementFailsUnderTheFailPolicy`,
`testAbandonedPackagesAreWarningsUnderTheReportPolicy`, `testNonZeroExitWithNothingReadableFails`).

### 4.6 Pin the Composer version that does the audit
Defaults have changed between Composer releases (the abandoned-package default moved in 2.7, and 2.10 added
`config.policy` and made `config.audit` a fallback). The same lock can audit differently on two Composer versions.

Why: results should depend on the lock and the policy, not on which Composer the runner has.

Sources: [Composer config: audit](https://getcomposer.org/doc/06-config.md);
[Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/).

**How the stack holds it: Checked.** Since 0.2.0, the `composer-version` check fails when the Composer on PATH carries
the command-injection holes CVE-2024-35241 and CVE-2024-35242 (2.2.x below 2.2.24, or any other 2.x below 2.7.7) or is
Composer 1, and warns when composer cannot be run; the text report's header line names the composer version used.
(Second pass: the Composer 2.10.2 changelog lists "Fixed `audit` command to output the audit result to stdout".
PROCTOR reads only stdout, so a Composer older than 2.10.2 is one more reason to set a minimum. Composer 2.11.0-RC1
was released 2026-10-22 and 2.10.3 is the latest stable in the changelog; check before pinning.) Since then 2.11.0
(2026-10-29) and 2.11.1 (2026-10-30) have shipped as stable; see 4.11 for what the stack pins.

### 4.7 Audit again on a schedule
New advisories appear after code is merged. A weekly scheduled pipeline finds them without a commit.

Why: a lock that was clean on Monday can be vulnerable on Friday.

Sources: [GitLab: pipeline schedules](https://docs.gitlab.com/ci/pipelines/schedules/).
(Second pass correction: the first pass cited the OpenSSF Scorecard checks page. That page says Vulnerabilities uses
OSV and Dependency-Update-Tool looks for Dependabot or Renovate. It says nothing about scheduled scanning, so it was
the wrong source for this rule.)

A GitLab schedule runs with the permissions of its owner, and it goes `Inactive` if the owner is blocked or removed
from the project. Give each schedule a named team owner and have a Maintainer take it over when someone leaves.
GitLab's built-in Dependency Scanning page lists "Tier: Ultimate" and does not mention PHP or Composer, so do not
assume it covers this job on a Free or Community instance.

**How the stack holds it: Documented.** Preflight's README has a "Run it on a schedule" section with the weekly pipeline
schedule steps. A document, not a gate: nothing fails if an app skips it.

### 4.8 Run a patched Composer, because PROCTOR feeds it files the app controls (second pass)
Set a minimum Composer version for the job that runs PROCTOR, and fail with a clear message below it.

Why: in April 2026 Composer fixed two command-injection flaws in its Perforce handling, CVE-2026-40176 (CVSS 7.8) and
CVE-2026-40261 (CVSS 8.8). A malicious `composer.json` can trigger them. They are fixed in Composer 2.9.6 and 2.2.27
(LTS), and the news report says Composer runs the injected commands even when Perforce is not installed. A third
flaw, CVE-2026-84361 (advisory dated 2026-09-08), lets a malicious dependency run commands during `install` or
`update` when the `p4` client is present, and is fixed in 2.10.3 and 2.2.30. The Composer changelog also lists
security fixes in 2.10.1, 2.10.2 and 2.10.3 (package-name validation, bin-path traversal, URL and token leaks). The
news report says Packagist.org was scanned for the April flaws and showed no exploitation. PROCTOR reads the app's `composer.json` and runs
`composer audit` against it, so a weak Composer is part of PROCTOR's own exposure.

Sources: [The Hacker News: Composer Perforce flaws, 2026-04-14](https://thehackernews.com/2026/04/new-php-composer-flaws-enable-arbitrary.html);
[GitLab advisory database: CVE-2026-84361](https://advisories.gitlab.com/composer/composer/composer/CVE-2026-84361/);
[Composer changelog](https://raw.githubusercontent.com/composer/composer/main/CHANGELOG.md). I read the two
advisory pages through summaries and did not confirm the CVSS scores against NVD.

**How the stack holds it: Partially.** PROCTOR now reads the Composer version, names it in the text report's header
line, and the `composer-version` check (see 4.6) fails when the Composer on PATH carries the command-injection holes
CVE-2024-35241 and CVE-2024-35242 or is Composer 1. Since 0.7.0, the same check also fails when the
Composer on PATH is below the CREED policy's `tools.composer.minimum` (2.8.0 in the bundled policy), naming the
policy in the message; the CVE floors stay as absolute floors underneath it. Preflight's `php-test` and `proctor` jobs
install the Composer release named by their `composer_version` input (default `2.11.1` in Preflight 0.8.0, the pin
PROCTOR's own pipeline uses; 0.4.0 installed 2.10.3), which carries the 2026 Perforce fixes, and since Preflight 0.4.0 that input refuses a
release older than 2.9.6. What remains: the policy minimum (2.8.0) sits below those fixes in 2.9.6 and 2.10.3, so the
check passes a Composer that lacks them, and an app on an older Preflight pin can still set `composer_version` lower.

### 4.9 Report an app that switches the dependency policy off (second pass)
Look for `config.policy` set to `false`, `policy.malware` or `policy.advisories` set to `false`, and the matching
`config.audit` keys, in `composer.json`. Also treat `COMPOSER_NO_BLOCKING`, `COMPOSER_NO_AUDIT` and
`COMPOSER_AUDIT_ABANDONED` in the CI environment as findings.

Why: the Composer docs say `policy: false` disables all dependency policy enforcement, and the Packagist follow-up
post notes that a project can turn its malware policy off in its own `composer.json`. The environment variables
override the `block` setting of each policy.

Sources: [Composer config: policy](https://getcomposer.org/doc/06-config.md);
[Composer CLI: environment variables](https://getcomposer.org/doc/03-cli.md);
[Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/).

**How the stack holds it: Checked.** Since 0.2.2, the `composer-config` check reads the `config` block of
composer.json: it warns when `allow-plugins` is `true`, `secure-http` is `false`, `lock` is `false` or
`audit.block-insecure` is `false`, when `config.policy` is `false` (rule id `proctor/composer-config/policy-off`) or
when `policy.malware` or `policy.advisories` is the boolean `false` (rule ids
`proctor/composer-config/policy-malware-off` and `proctor/composer-config/policy-advisories-off`; an object value for
`policy.advisories` with ignore lists is not a finding), and notes `require.php` without `config.platform.php`.
The `ci-pipelines` check reads the pipeline environment: it warns (rule id
`proctor/ci-pipelines/composer-audit-off`) when `.gitlab-ci.yml` or `.github/workflows/ci.yml` sets
`COMPOSER_NO_AUDIT` or `COMPOSER_NO_BLOCKING` to anything but an empty string, `0` or `false`, or
`COMPOSER_AUDIT_ABANDONED` to `ignore`, in the GitLab top-level or job `variables:` (a scalar or a map with a
`value` key) or the GitHub top-level, job or step `env:`. A warning, never a fail, for stack packages and other
projects alike.

### 4.10 Do not trust a clean JSON result when Composer's exit code is non-zero (second pass)
If the JSON has no advisories but `composer audit` exited 1, treat it as a finding or a tool error. Composer 2.10.0
made the audit exit code "always 0 (success) or 1 if anything failed the audit", and the CLI docs say 1 also covers
failing because required packages are missing.

Why: abandoned packages, malware and custom policies can fail the audit without an entry under `advisories`.

Sources: [Composer changelog, 2.10.0](https://raw.githubusercontent.com/composer/composer/main/CHANGELOG.md);
[Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Checked.** `ComposerAuditCheck` compares the exit code with the parsed result: when the
audit exits non-zero and the JSON produced no advisory, ignored-advisory or abandoned finding, the check fails with
one finding (rule id `proctor/composer-audit/exit-code`) instead of passing, so a malware or policy block, or an
error, cannot hide behind an empty advisory list. Composer's own exit code 2 is a solver error and is reported the
same way. An exit code of 0 with an empty result still passes, and any exit code with advisories or abandoned
findings behaves as before (abandoned packages follow the policy's `abandoned_packages` rule, which defaults to
`fail`).

### 4.11 Watch the cooldown policy and decide on it later (second pass)
Composer 2.11.0-RC1 (2026-10-22) adds a `cooldown` policy that withholds newly published versions for a set period. The
Composer docs describe it as off by default. The Packagist post of 2026-05-27 lists a minimum release age as planned
work, because of the tag-modification attacks.

Why: a new release that is compromised is usually found within days, so a short wait removes most of that exposure.

Sources: [Composer changelog, 2.11.0-RC1](https://raw.githubusercontent.com/composer/composer/main/CHANGELOG.md);
[Composer config](https://getcomposer.org/doc/06-config.md);
[Packagist supply chain update](https://blog.packagist.com/an-update-on-composer-packagist-supply-chain-security).
The claim that most compromises are found within days is mine and is not backed by a source I read.

**How the stack holds it: Checked.** The `composer-cooldown` check (since 0.12.0) warns when composer.json sets no
`config.policy.cooldown.period` (`proctor/composer-cooldown/missing`) or turns blocking off
(`proctor/composer-cooldown/not-blocking`), and since 0.13.0 it reads the period and exemptions from the CREED policy
(`tools.composer.cooldown`: 3 days, `waynestate/*` exempt, CREED 0.1.10) and warns when an app's period is shorter
(`proctor/composer-cooldown/too-short`). It warns only and is not in `proctor gate`. Every Sourdough shape sets the
3-day cooldown with the `waynestate/*` exemption (Sourdough main, 2026-11-10). History, kept for the record:
Composer 2.11 is stable: 2.11.0 shipped on 2026-10-29 and 2.11.1 on 2026-10-30
(getcomposer.org download page and the composer/composer releases page, read 2026-11-08), and the 2.11.0 notes add the
`cooldown` policy (`config.policy.cooldown.period`, default `null`, so off). The move to 2.11 is done in the tools
(read 2026-11-09): Squire's kit pins `composer:2.11.1` by digest in its base fallback image since 0.10.1, and so do
the deploy layers already regenerated from it (HELMET, SESAME); Squire's own CI jobs use `composer:2.11.1`;
Preflight 0.8.0 installs 2.11.1 by default in its `php-test` and `proctor` components (`composer_version`; 0.4.0
installed 2.10.3); and PROCTOR's own CI jobs, phar build scripts and deploy layer (Squire 0.10.7) pin
`composer:2.11.1` by digest. Apps on an older Preflight pin still get the older default until they move to `@0.8.0`. Decision (the stack maintainer, 2026-11-03): when 2.11 is in the base images, Sourdough apps set a 3-day
cooldown and PROCTOR warns when an app has none. That condition was met on 2026-11-10, and all three parts are built.

### 4.12 Decide whether dev dependencies count (second pass)
`composer audit --locked` audits `require-dev` packages too. Composer offers `--no-dev`, and `--direct` from
2.11.0-RC1 on (per its changelog).

Why: a vulnerable test tool is not in the deployed image, and failing the gate on it sends owners to fix the wrong
thing. The reverse is also possible: a dev dependency can run during CI.

Sources: [Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Partially.** Since 0.6.0 the `composer-audit` check carries the decision: an advisory on a
package that composer.lock lists only in packages-dev is a warning at any severity, its message ending in
` [dev dependency]`; a name in both lists counts as runtime, and so does a name the lock does not list at all. Tests:
`tests/ComposerAuditCheckTest.php` (`testDevOnlyAdvisoryIsAWarningWithTheDevDependencySuffix`,
`testPackageInBothListsStillFails`). What remains: the policy file carries no rule for it, so the choice cannot be
changed per policy.

---

## 5. Overrides

### 5.1 Give every override a reason, an expiry and an approver
An override is a JSON entry with `check`, `reason`, `expires` (a real calendar date) and `approved_by`. A missing or
invalid field stops the run.

Why: an exception without an end date becomes the permanent state.

Sources: the stack's version-policy decision; [PHPStan baseline: entries cannot carry reasons](https://phpstan.org/user-guide/baseline)
(the weakness that dated overrides avoid).

**How the stack holds it: Enforced.** `OverrideFile` rejects the file (exit 2) for a missing field or a date that does
not round-trip, such as `2027-13-99`. An override expires the day after its `expires` date, and `overrides` then fails.

### 5.2 Print every override on every run
The output lists each override, active or expired, with its reason and approver.

Why: an exception nobody sees cannot be questioned.

Sources: the stack's version-policy decision.

**How the stack holds it: Enforced.** Text and JSON output include every override entry, and tests assert the
`OVERRIDE` line.

### 5.3 Prefer expiring exceptions to a permanent baseline
Static-analysis tools adopt legacy code with a baseline file. A baseline grows when it is regenerated, has no
comments, and stays forever.

Why: dated overrides force the debt back onto the owner's calendar.

Sources: [PHPStan: baseline](https://phpstan.org/user-guide/baseline).

**How the stack holds it: Enforced.** `overrides` fails on an expired entry, so an exception cannot outlive its date
without a visible change.

### 5.4 Scope an override to one thing
An override should name a package or advisory, not a whole check.

Why: a check-wide override also hides every new problem of that kind during the window.

Sources: [Composer config: ignores can carry a reason and scope](https://getcomposer.org/doc/06-config.md).

**How the stack holds it: Checked.** A `composer-audit` override may carry an optional `advisory` (an advisory id or
CVE, matched case-insensitively against the finding's advisory id or CVE) and an optional `package` (a package name,
`*` wildcard allowed, matched case-insensitively against the audited package); both present means both must match the
same advisory, and a scoped override never covers a fail that carries no advisory data. On any other check, or with a
value that is not a non-empty string, the entry is a tool error (exit 2). Other checks stay check-wide, and a scoped
override that matches no failure earns the unused warning with its scope in the text. Tests: `tests/ScopedOverrideTest.php`.

### 5.5 Cap how long an override can run
The expiry should not be later than the related forced-upgrade deadline.

Why: a far-future date is a permanent override with extra steps.

Sources: the stack's deploy-gate decision.

**How the stack holds it: Checked.** `OverridesCheck` fails an override whose end date is more than the policy's
`rules.override_max_days` away (90 days when the key is absent) with the finding `proctor/overrides/too-long`, and
such an override is not applied; `OverrideFile` itself still accepts any valid date. Test: `tests/OverrideGuardsTest.php`
(`testAnOverrideOneDayPastTheMaximumFailsAndIsNotApplied`).

### 5.6 Do not let an override waive the override check, and reject unknown check names
An active override cannot turn a fail of the `overrides` check into a warning, and an override naming a check nobody
runs is a tool error (exit 2) that names the valid ids.

Why: the expiry rule is only as strong as the rule that enforces it.

Sources: [OWASP CICD-SEC-1, Insufficient Flow Control Mechanisms](https://owasp.org/www-project-top-10-ci-cd-security-risks/).

**How the stack holds it: Checked.** `OverrideApplier` never downgrades a fail of the `overrides` check, and an
override naming a check nobody runs is a tool error (exit 2) that names the valid ids. Tests:
`tests/OverrideGuardsTest.php` (`testUnknownCheckTargetIsAToolErrorNamingTheIdAndTheValidOnes`,
`testTheApplierStillNeverDowngradesTheOverridesCheck`).

### 5.7 Have someone other than the author approve an override
`approved_by` is a free-text string. The file sits in the app repo and its author can edit it.

Why: an approval that nobody checks is a note.

Sources: [GitLab protected branches: Code Owner approval is Premium or Ultimate](https://docs.gitlab.com/user/project/repository/branches/protected/).

**How the stack holds it: Partially.** Checked in CI: the `override-approver` check (`proctor check` only) reads git
blame for each entry and reports `proctor/override-approver/self-approved` when `approved_by` names a person who wrote
one of its lines (author name, email, its local part or a noreply username; a line holding only `approved_by` is the
approver's signature). With no git history to read, including lines a shallow clone credits to its oldest commit, it
reports `proctor/override-approver/could-not-run`, never a pass. CREED's `rules.override_self_approval` makes both
`warn` (the default) or `fail`. Not checked: that the approver exists or really approved. git.wayne.edu runs GitLab
Community Edition, which has no Code Owner approval (second pass: the protected-branches page still lists it as Premium
and Ultimate), so the merge request approval is the human control and the check is its tripwire. The deploy gate keeps
platform-held overrides with the platform admins (Citadel's today, through Squire's `citadel` target) and does not run
the check. Tests: `tests/OverrideApproverCheckTest.php` (`testAnOverrideWrittenAndApprovedInOneCommitBySamePersonWarns`,
`testAnApproverWhoExtendsTheirOwnApprovalIsSelfApproved`, `testAShallowCloneNeverCreditsOldLinesToTheBoundaryCommit`,
`testTheFailPolicyMakesASelfApprovalAFailure`).

### 5.8 Report an override that matches nothing, and one close to expiry (second pass)
Print a warning when an active override targets a check that raised no failure, and when an expiry is within a set
number of days.

Why: PHPStan tells you when a baseline entry no longer matches an error and asks you to remove it
(`reportUnmatchedIgnoredErrors`). A stale exception is clutter that hides the real ones. A warning before expiry means
the owner fixes the cause instead of finding a red build.

Sources: [PHPStan: baseline](https://phpstan.org/user-guide/baseline).
The warning-before-expiry part is my own suggestion and I have no prior-art source for the number of days.

**How the stack holds it: Checked.** `OverridesCheck` warns (`proctor/overrides/expiring`) for an active entry that
expires within 14 days of `--today` (the expiry day counts), and `OverrideApplier::unusedWarnings()` warns
(`proctor/overrides/unused`) for an active entry whose check raised no failure, so a stale approval is visible
instead of silent. Both are warnings; neither changes the exit code. Tests: `tests/OverrideGuardsTest.php`
(`testExpiringOverrideWarnsAndOnlyTheMissingComposerAuditFails`, `testActiveOverrideThatMatchesNoFailureWarns`,
`testOverrideThatDowngradesAFailureIsNotReportedAsUnused`).

---

## 6. Output and exit codes

### 6.1 Use three distinct exit codes
`0` for pass, `1` for findings that fail, `2` for a tool error such as a bad option, an unreadable policy or a malformed
file.

Why: a pipeline must be able to tell "the app is bad" from "the checker broke".

Sources: [Semgrep: exit codes for findings and errors](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci);
[Composer CLI: exit codes](https://getcomposer.org/doc/03-cli.md).
(Second pass correction: the Semgrep page documents only `1` for blocking findings and `0` otherwise, and says
`semgrep ci` returns 0 on internal errors by default. So it is an example of the opposite choice and supports 7.2, not
a separate tool-error code. Composer's process exit codes are 0 OK, 1 generic error and 2 dependency-solving error.
The three-code split here is PROCTOR's own design and is a sound one, but these two sources do not prove it.)

**How the stack holds it: Enforced.** `bin/proctor` returns 2 for tool errors (unknown command included) and `Report`
accepts only 0 or 1. `CheckCommandTest` asserts the codes on 16 fixture apps.

### 6.2 Publish results in the platform's native report format
For GitLab, emit Code Quality JSON and declare it as `artifacts:reports:codequality`. Import results directly, not
through the deprecated CodeClimate template.

Why: the merge request shows the findings with no extra tooling.

Sources: [GitLab Code Quality](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Enforced.** `CodeQualityWriter` writes the required fields, relative paths, and severities
`major`, `minor` and `info` (the tool-error report uses `blocker`, `CodeQualityWriter::writeToolError`).
`CodeQualityShapeTest` checks the shape. The CodeClimate template was deprecated in GitLab
17.3 and is planned for removal in 19.0; PROCTOR does not use it. (The accepted severities are `info`, `minor`,
`major`, `critical` and `blocker`. The parser rejects a UTF-8 byte order mark.)

### 6.3 Make fingerprints stable and free of line numbers
A fingerprint identifies a finding across runs. GitLab compares them with the target branch to show new and resolved
problems.

Why: a line number changes with every edit, which would make old findings look new.

Sources: [GitLab Code Quality: fingerprints](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Enforced.** `Fingerprint::of` hashes the rule id, path and message with SHA-256 (`src/Report/Fingerprint.php`).

### 6.4 Always write a valid report, even when the tool fails
On exit 2 the report file should still be valid JSON (`[]` or one finding that names the error).

Why: an empty file turns a clear tool error into a second parse error in the merge request.

Sources: [GitLab Code Quality: report requirements](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Checked.** `bin/proctor` catches `ToolError` and, in whatever format was selected, writes
that format's tool-error report to stdout (`TextWriter`, `JsonWriter`, `CodeQualityWriter` and `SarifWriter` each
have a `writeToolError`; the codequality one lists the error as a single issue), so the artifact is never an empty
file.
Test: `tests/CodeQualityShapeTest.php` (`testToolErrorInCodeQualityFormatListsTheErrorAsOneIssue`).

Second pass: this matters because GitLab says artifacts under `artifacts:reports` are "always uploaded, regardless of
the job results", and the Preflight component sets `artifacts:when: always`, so the report written on exit 2 does
reach GitLab and is parsed there.

### 6.5 Offer an interchange format when a second consumer needs it
SARIF 2.1.0 is the OASIS standard for static-analysis results. GitLab Code Quality is enough for merge requests today.

Why: other platforms and editors read SARIF.

Sources: [OASIS SARIF v2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html).

**How the stack holds it: Enforced.** `SarifWriter` writes a SARIF 2.1.0 log: one run, one rule per distinct namespaced
rule id (`$finding->ruleId`, `src/Report/SarifWriter.php`), and one result per non-pass finding, with `fail` mapped to
`error`, `warning` to `warning` and `info` to `note`. `SarifShapeTest` checks the shape. GitHub code scanning reads it
through `github/codeql-action/upload-sarif`. PROCTOR files the mark sheet in the format the head office already
reads, so nobody translates the exam results by hand.

### 6.6 Make runs reproducible
The date is injectable so a test gives the same answer on any day.

Why: policy results depend on dates, and tests must not drift.

Sources: the stack's version-policy decision.

**How the stack holds it: Enforced.** `--today` is validated as a real `YYYY-MM-DD` date, and every CLI test runs with a
fixed date and a fixed fixture policy. Since 0.6.0, `--policy` and `--today` are refused in CI unless
`PROCTOR_ALLOW_OVERRIDES` is exactly `1` (`src/Policy/CiGuard.php`, called from `RunOptions`, which `proctor check` and `proctor gate` both use). Tests:
`tests/CiGuardTest.php` (`testTodayIsRefusedInCiWithTheExactMessage`, `testAllowOverridesExactlyOneAllowsTheOptions`).

### 6.7 Never carry meaning by color alone (second pass)
Each result line starts with a word (`PASS`, `INFO`, `WARN`, `FAIL`), and the last line says `RESULT: pass` or
`RESULT: fail`. If color is ever added, it only repeats the word, and the `NO_COLOR` variable turns it off.

Why: WCAG Success Criterion 1.4.1 (Level A) says color must not be the only cue. The same holds in a terminal and in a
CI log viewer, for people who cannot tell the colors apart and for logs that strip ANSI codes. The `NO_COLOR`
convention says a non-empty `NO_COLOR` variable should prevent ANSI color.

Sources: [WCAG 2.2: Use of Color](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color.html);
[NO_COLOR](https://no-color.org/).

**How the stack holds it: Enforced.** `TextWriter` writes plain text with a status word on every line and emits no ANSI
codes. A CLI test runs a passing and a failing fixture app and asserts the text output carries no escape byte, and a
writer test feeds an escape sequence and a carriage return through a finding message.

### 6.8 Escape strings that come from the app before printing them (second pass)
Messages contain package names, constraints and override reasons, all read from files the app controls. Escape them
before they reach the console writer.

Why: Symfony Console treats `<info>`-style tags in text passed to `writeln()` as formatting, and its docs describe
`OutputFormatter::escape()` for untrusted strings. A crafted package name could alter or hide a line in the log. I did
not find a primary source for raw terminal control characters in CI logs, so that part is a hypothesis.

Sources: [Symfony Console: coloring and tags](https://symfony.com/doc/current/console/coloring.html).

**How the stack holds it: Enforced.** `TextWriter` replaces terminal control characters (every C0 except newline and
tab, plus DEL and the C1 block) with a visible `?` in finding messages, override reasons and approvers, and `bin/proctor`
prints the report with `OutputInterface::OUTPUT_RAW`, so a tag-like package name or constraint reaches the log literally
instead of being parsed as a formatting tag. A CLI test pins it.

---

## 7. Adopting a rule without blocking fixes

### 7.1 Block regressions first, not every old violation
A gate that blocks only new problems lets owners ship fixes while the total can only fall. Diff-aware scanning in other
tools works this way.

Why: blocking all old violations on day one freezes the fixes that would clear them.

Sources: [Semgrep: diff-aware scanning and baseline commit](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci);
[PHPStan: baseline](https://phpstan.org/user-guide/baseline).

**How the stack holds it: Checked.** `proctor gate` (since 0.7.0, `src/Gate/Gate.php`) ratchets each fail against a
baseline: blocked on a first deploy, held when the baseline failed the same rule id, blocked as a regression when the
baseline passed it, waived when it only says the advisory data was unavailable, and staged (logged, deploy goes ahead)
when the policy's gate stage for its check is `report` or `warn` today. The stage comes from the policy via
`Policy::gateStage()` (`src/Creed/Policy.php:465`), which reads `gate.stages` and falls back to `gate.default_stage`,
then to `block`.

### 7.2 Keep gate mode small, offline and fast, and let a checker bug fail open
At deploy time the check should need no network and no app boot, finish in seconds, and exit so that an internal error
does not stop a deploy.

Why: a gate that can take down deploys on its own bug will be switched off.

Sources: [Semgrep: internal errors do not block](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci);
the stack's enforcement-points and deploy-gate decisions.

**How the stack holds it: Partially.** `proctor gate` ships (since 0.7.0): it runs only the deploy-time checks,
needs no app boot, and `--timeout` caps the one step that touches the network, `composer audit` (60 seconds in the
gate, 300 in `proctor check`). What remains: `composer-audit` still needs the network to fetch advisories, so an
offline gate warns instead of failing (missing advisory data only warns).

---

## 8. The tool itself

### 8.1 Run the checker without installing the app
Run PROCTOR from its own install and point `--path` at the app. Do not first run `composer install` on the app.

Why: installing an app runs its plugins and scripts, and an app on an old PHP cannot install on a new image, so the
check never runs.

Sources: [OWASP CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/);
[Composer CLI: `--no-plugins`, `--no-scripts`](https://getcomposer.org/doc/03-cli.md); the stack's deploy-gate decision.

**How the stack holds it: Partially.** PROCTOR builds as a single-file PHAR: `scripts/build-phar.ps1` and
`scripts/build-phar.sh` produce `build/proctor.phar`, and the README's "Single-file download" section (and the
install page) gives the download and checksum commands for Linux, macOS and Windows next to `composer require`. The
pipeline's `release-phar` job (`.gitlab-ci.yml`) builds the PHAR on every tag, verifies it runs, writes
`build/proctor.phar.sha256`, uploads both to the project's generic package registry and attaches them to the GitLab
Release, refusing to overwrite a file the tag already published. Preflight's `proctor` component
(`templates/proctor/template.yml`) runs `composer install --no-interaction --no-progress --no-plugins --no-scripts
--ignore-platform-reqs` on the app first, so the app's plugins and scripts do not run in that job and an app on an old
PHP still installs. What remains: that job still installs the app's dependencies and runs the app's own
`vendor/bin/proctor`, not the PHAR; moving it to the PHAR is Preflight's change.

### 8.2 Start child processes without a shell
Pass arguments as an array so a path or value cannot become a command.

Why: it removes a whole class of injection.

Sources: [OWASP CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/).

**How the stack holds it: Enforced.** `ProcessRunner` calls `proc_open` with an argument array.

### 8.3 Put a timeout on every child process
A hung `composer audit` must not hold a runner until the job limit.

Why: a stuck advisory call otherwise burns runner time and hides the real fault.

Sources: [GitLab CI: `timeout`](https://docs.gitlab.com/ci/yaml/).

**How the stack holds it: Checked.** `ProcessRunner` runs every child under a deadline (default 300 s, settable with
`--timeout`), reads stdout and stderr with `stream_select`, and kills the child and reports it when the deadline
passes, so a hung `composer audit` cannot hold the runner. Test: `tests/ProcessRunnerTest.php`
(`testChildProcessOverTimeoutIsKilledAndReported`).

### 8.4 Test every exit path with a fixture app
Each result a user can see needs a fixture that produces it.

Why: a checker that is wrong in silence is dangerous.

Sources: [GitLab: test with a sample project that exercises the tool](https://docs.gitlab.com/ci/components/).

**How the stack holds it: Checked.** PROCTOR's pipeline runs PHPUnit on PHP 8.3 to 8.5 through the Preflight
`php-test` component, with fixtures for pass, end of life, unbounded and expired override. The audit parsing path
is covered by `tests/ComposerAuditCheckTest.php`, which unit-tests the parsing with a stub runner, so no fixture
needs a real `composer audit`.

### 8.5 Sign and pin releases, and publish provenance
A release is a tag that Composer users can pin, with a signature or provenance that says where it was built.

Why: the checker runs in many pipelines, so a swapped release is a wide attack.

Sources: [SLSA v1.0 build levels](https://slsa.dev/spec/v1.0/levels) (page marked retired; v1.2 is current);
[OpenSSF Scorecard: Signed-Releases](https://github.com/ossf/scorecard/blob/main/docs/checks.md);
[CISA and NSA: Defending CI/CD environments](https://cisa.gov/news-events/alerts/2023/06/28/cisa-and-nsa-release-joint-guidance-defending-continuous-integrationcontinuous-delivery-cicd).

**How the stack holds it: Partially.** Releases are tagged (0.1.0 through 0.16.0), and since 0.2.4 the reports read the
installed `waynestate/proctor` version from Composer, with the in-code constant as the fallback. What remains: no
release carries a signature or provenance that says where it was built.

### 8.6 Start Composer with plugins off and a known environment (second pass)
Run `composer audit` with `--no-plugins` and `--no-interaction`, and pass an explicit environment instead of the
caller's.

Why: Composer reads many `COMPOSER_*` variables (`COMPOSER_NO_BLOCKING`, `COMPOSER_NO_AUDIT`, `COMPOSER_AUDIT_ABANDONED`,
`COMPOSER_AUTH`) that override `composer.json`, and `--no-plugins` is a documented global option. A check that must
not be talked out of its answer should not inherit settings from the job around it. I did not verify whether `audit`
loads installed plugins, so this is a precaution.

Sources: [Composer CLI: global options and environment variables](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Enforced.** The audit command line carries `--no-plugins` and `--no-interaction`, and the
audit runs with an explicit minimal environment (PATH plus HOME, or APPDATA and USERPROFILE on Windows, COMPOSER_HOME,
TEMP/TMP, SystemRoot on Windows), so the job around it cannot set `COMPOSER_NO_AUDIT` or friends. A test captures the
command line and the environment. Other child processes (the composer version probe, the WayneUI release lookup) still
inherit the caller's environment; this practice is about the audit. It uses an argument array (8.2).

### 8.7 Treat the CI platform as part of the trust base (second pass)
Keep the GitLab instance and its runners on a patched release, and say so in the Preflight docs. The minimum
supported GitLab version is 18.7: run 18.7 or later, and keep it patched. The stack's own instances run GitLab 19.5.1
CE.

Why: GitLab's patch page for 2026-10-12 describes CVE-2026-97229, a CVSS 10.0 integer overflow in the CI/CD regular
expression compiler of CE and EE, exploitable by an unauthenticated user, fixed in 19.2.8, 19.3.5 and 19.4.2. A gate
that runs on an unpatched server proves little. This is outside PROCTOR's code, but the stack's guide should name it.
The stack's CI components also need GitLab 18.7 or later: Preflight's templates use `spec:component`, which Preflight
practice 2.11 records as generally available in 18.7 (unverified against GitLab's own page). On an older GitLab the
gates are silently lost.

Sources: [GitLab patch release 19.4.2, 19.3.5, 19.2.8](https://docs.gitlab.com/releases/patches/patch-release-gitlab-19-4-2-released/).
Other 2026 GitLab CVEs came to me only through news summaries and I did not verify them.

**How the stack holds it: Documented.** The floor is GitLab 18.7, set by `spec:component` in Preflight's CI
components, and the install page lists it under Requirements. 18.7 is a feature floor, not a security floor: the patch note above covers only
the 19.2, 19.3 and 19.4 lines, so an owner on 18.x must check the patch status of their own instance. PROCTOR cannot
see the server version.

---

## Gaps for the backlog

Practices tagged `Not yet`, `Documented` or `Partially`, each one line naming what is still open. Highest value first.
A practice that is now `Checked` or `Enforced` leaves the list; CHANGELOG.md records when it closed.

1. 1.2: Partly done: in CI `--policy` and `--today` are refused unless `PROCTOR_ALLOW_OVERRIDES` is exactly `1` (0.6.0); `preflight-gates` warns when Preflight's `proctor_command` input runs no PROCTOR or masks its exit code, and when a re-declared gate job may fail, never runs (`when: never`) or waits for a click (`when: manual`), but an app's `.gitlab-ci.yml` can still override other included job keys (the image, the variables) and the vendored policy; the `ci-pipelines` check warns (`proctor/ci-pipelines/allow-overrides`) when an app's pipeline sets `PROCTOR_ALLOW_OVERRIDES` to `1` (see 1.2).
2. 8.1: Partly done: the PHAR build exists and the Preflight job installs the app with `--no-plugins --no-scripts`; the PHAR is built and attached to each GitLab Release by the `release-phar` job and the README documents it, but the Preflight job still installs the app's dependencies and runs its `vendor/bin/proctor`, not the PHAR (Preflight's change; see 8.1).
3. 2.6: Partly done: PROCTOR's own pipeline runs the Preflight `proctor` component on `deploy/`, and the job blocks the pipeline; raise PROCTOR's floor from `^8.3` to `^8.4` before PHP 8.3 reaches end of life on 2027-12-31 (see 2.6).
4. 5.7: Partly done: the `override-approver` check flags an approver who wrote the entry (git blame; rule ids `proctor/override-approver/self-approved`, `proctor/override-approver/could-not-run`), warn or fail per CREED `rules.override_self_approval`; that the approver really approved still rests on the merge request, since GitLab Community Edition has no Code Owner approval (see 5.7).
5. 4.7: Documented: Preflight's README carries the weekly "Run it on a schedule" steps, but nothing fails when an app has no schedule (see 4.7).
6. 8.5: Partly done: releases are tagged (0.1.0 through 0.16.0); sign them and publish provenance that says where each was built (see 8.5).
7. 7.2: Partly done: `proctor gate` (0.7.0) runs only the deploy-time checks with no app boot and a 60-second audit timeout; `composer-audit` still needs the network, so an offline gate only warns that advisory data is missing (see 7.2).

Added in the second pass (2026-10-27):

8. 4.8: Partly done: the `composer-version` check fails below the CVE-2024 floors and the policy's `tools.composer.minimum` (0.7.0), but that minimum is 2.8.0, below the 2026 Perforce fixes in 2.9.6 and 2.10.3 (see 4.8).
9. 3.7: Partly done: the Preflight `proctor` component installs from the lock, and the `ci-pipelines` check warns (`proctor/ci-pipelines/composer-update`) when CI runs `composer update` or `composer require`; the finding is a warning, not a fail; it reads `.gitlab-ci.yml` with its local includes and every GitHub workflow, but not remote, project or component includes (see 3.7).
10. 4.12: Partly done: `composer-audit` (0.6.0) warns instead of failing for an advisory on a package that composer.lock lists only in packages-dev, marked ` [dev dependency]`, and a name in both lists still fails; the policy file carries no rule for it (see 4.12).
11. 8.7: Documented: the floor is GitLab 18.7 (`spec:component`), named in the install page's requirements; patch status on 18.x is the owner's to check (see 8.7).

## Second pass (2026-10-27)

A second reader re-checked the first pass against primary sources and read the code again. Search tools were limited,
and the search budget ran out before I could look for prior art at other universities, so none is cited. The
comparable tools used are PHPStan, Semgrep, OpenSSF Scorecard and Composer itself.

**Added (12 practices, plus a note under 3.5):** 2.7 end date in the security-only warning; 3.7 install from the lock, never re-tag; 4.8
minimum Composer version; 4.9 app switches the dependency policy off; 4.10 Composer exit code against parsed output;
4.11 cooldown policy; 4.12 dev dependencies; 5.8 stale and near-expiry overrides; 6.7 color is never the only cue;
6.8 escape app-supplied strings; 8.6 plugins off and a known environment; 8.7 patched CI platform. The 3.5 note covers
the Composer 2.11 lock check.

**Corrected or tightened:**
- 4.5: abandoned packages fail `composer audit` by default (since Composer 2.7). The first pass said 2.10 "reports"
  them.
- 4.7: the OpenSSF Scorecard page was the wrong source for scheduled audits. Replaced by the GitLab schedules page,
  with a note on schedule owners.
- 6.1: the Semgrep page does not document a tool-error exit code, and Composer's own exit code 2 means something
  else. The PROCTOR design stands; the sources only partly support it.
- 4.2: the Semgrep page is an analogy for unknown severities, not a source.
- 4.4: the `audit.ignore` setting is deprecated in favor of `policy.advisories.ignore-id`, and `ignore-severity` and
  `ignore-abandoned` also silence results.
- 4.6: added the Composer 2.10.2 stdout fix, the current stable (2.10.3) and the 2.11.0-RC1 date.
- 6.4: GitLab uploads report artifacts even when the job fails, so the empty file on exit 2 does reach GitLab.
- 6.2: GitLab also accepts `critical` and `blocker`, and rejects a byte order mark.
- 2.1: dates confirmed against php.net and endoflife.date; added the 2027-01-01 change for PHP 8.4.

**Confirmed, no change:** PHP support dates; Composer 2.10 `config.policy`, malware default and `audit.*` fallback;
CodeClimate template deprecation (17.3) and removal plan (19.0); Code Owner approval is Premium and Ultimate; PHPStan
baseline entries carry no reason; SLSA v1.0 page is retired and 1.2 is current; Semgrep fails open on internal errors.

**Not verified:** whether `composer audit` loads installed plugins (8.6); the proc_open pipe deadlock (8.3); the CVSS
scores of the Composer CVEs against NVD (4.8); what GitLab does with an empty Code Quality file (6.4); whether GitLab's
Dependency Scanning supports Composer (4.7); the final status of NIST SSDF 1.2, which my search showed only as a draft
(December 2025 initial public draft), so it is not cited; a claim from one search result that Composer 2.9 blocks
insecure installs. The Composer changelog says 2.9.0-RC1 added blocking of insecure versions on updates, but not on
`install`.

**Instructions in fetched pages:** none seen.

## Sources

All checked on 2026-10-27.

| Title | URL |
|---|---|
| Composer: command-line interface (audit, validate, install) | https://getcomposer.org/doc/03-cli.md |
| Composer: configuration (audit, policy, platform, allow-plugins, secure-http, lock) | https://getcomposer.org/doc/06-config.md |
| Composer: basic usage (lock file, stability) | https://getcomposer.org/doc/01-basic-usage.md |
| Composer: versions and constraints | https://getcomposer.org/doc/articles/versions.md |
| Composer 2.10 release (Packagist blog) | https://blog.packagist.com/composer-2-10-release/ |
| GitLab: Code Quality | https://docs.gitlab.com/ci/testing/code_quality/ |
| GitLab: CI/CD YAML reference | https://docs.gitlab.com/ci/yaml/ |
| GitLab: use CI/CD configuration from other files (merge behavior) | https://docs.gitlab.com/ci/yaml/includes/ |
| GitLab: CI/CD components | https://docs.gitlab.com/ci/components/ |
| GitLab: protected branches | https://docs.gitlab.com/user/project/repository/branches/protected/ |
| PHP: supported versions | https://www.php.net/supported-versions.php |
| PHPStan: baseline | https://phpstan.org/user-guide/baseline |
| Semgrep: blocking findings and errors in CI | https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci |
| OASIS SARIF v2.1.0 | https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html |
| OWASP Top 10 CI/CD Security Risks | https://owasp.org/www-project-top-10-ci-cd-security-risks/ |
| OpenSSF Scorecard checks | https://github.com/ossf/scorecard/blob/main/docs/checks.md |
| SLSA v1.0 build levels | https://slsa.dev/spec/v1.0/levels |
| CISA and NSA: Defending CI/CD environments | https://cisa.gov/news-events/alerts/2023/06/28/cisa-and-nsa-release-joint-guidance-defending-continuous-integrationcontinuous-delivery-cicd |

Added in the second pass, all checked on 2026-10-27:

| Title | URL |
|---|---|
| Composer changelog (2.9.x, 2.10.x, 2.11.0-RC1) | https://raw.githubusercontent.com/composer/composer/main/CHANGELOG.md |
| Packagist: an update on Composer and Packagist supply chain security (2026-05-27) | https://blog.packagist.com/an-update-on-composer-packagist-supply-chain-security |
| The Hacker News: Composer Perforce command-injection flaws (2026-04-14) | https://thehackernews.com/2026/04/new-php-composer-flaws-enable-arbitrary.html |
| GitLab advisory database: CVE-2026-84361 (Composer, Perforce URL) | https://advisories.gitlab.com/composer/composer/composer/CVE-2026-84361/ |
| GitLab patch release 19.4.2, 19.3.5, 19.2.8 (CVE-2026-97229) | https://docs.gitlab.com/releases/patches/patch-release-gitlab-19-4-2-released/ |
| GitLab: pipeline schedules | https://docs.gitlab.com/ci/pipelines/schedules/ |
| GitLab: dependency scanning (tier note) | https://docs.gitlab.com/user/application_security/dependency_scanning/ |
| OWASP Top 10 2025: A03 Software Supply Chain Failures | https://top10.owasp.org/2025/A03_2025-Software_Supply_Chain_Failures |
| endoflife.date: PHP release data (API, cross-check of php.net) | https://endoflife.date/api/php.json |
| Symfony Console: coloring and tags | https://symfony.com/doc/current/console/coloring.html |
| WCAG 2.2 Understanding: Use of Color (SC 1.4.1) | https://www.w3.org/WAI/WCAG22/Understanding/use-of-color.html |
| NO_COLOR | https://no-color.org/ |

Pages from the first pass that were re-read in the second pass: PHP supported versions, Composer config, Composer CLI,
Composer 2.10 release, GitLab Code Quality, GitLab CI/CD YAML reference, GitLab protected branches, PHPStan baseline,
Semgrep blocking and errors, OpenSSF Scorecard checks, SLSA v1.0 levels.

Stack design decisions used for tagging: the stack's enforcement-points decision, the stack's version-policy decision, the stack's deploy-gate decision, the stack's one-repo-per-project decision, the PROCTOR repo, and the Preflight repo.

## Platform assumptions

### The portability check
An app must run on Citadel, OpenShift, Coolify or raw Docker, so platform facts belong in env or config, not in code.

**How the stack holds it: Checked.** The `portability` check warns on absolute host paths, hard-coded proxy
addresses, sessions on local disk, and `php_value` or `php_flag` lines in `.htaccess` (they do nothing under
php-fpm). It reports warnings, not failures, while the stack stages the rule.

### The mysql56 check
Citadel's shared MySQL server is 5.6, so migrations and app code must avoid MySQL features newer than 5.6.

**How the stack holds it: Checked.** The `mysql56` check (`src/Check/Mysql56Check.php`) warns on JSON columns and JSON
queries, generated columns, `renameColumn`/`renameIndex`, vector, invisible and spatial columns, `Schema::hasColumn`-style
introspection, raw SQL with 5.7+ syntax, the upsert row alias, indexed strings over 191 characters, date columns defaulting
to the current time, and the `db:show`/`db:monitor` commands, in `database/migrations/`, `app/`, `routes/` and
`config/database.php`. It also warns when `app/Providers/AppServiceProvider.php` does not call `Schema::defaultStringLength(191)`.
It reads the AST, so a pattern in a comment or string is not flagged. Warnings, not failures: an app on its own newer
database is not affected.

## CI pipelines

### The ci-pipelines check
CI runs on git.wayne.edu, and the GitHub copy of a stack package is a push mirror for Packagist and npm, so the hard
part of the CI contract is the GitLab pipeline: for stack packages `.gitlab-ci.yml` must exist and run `composer ci`,
and a reusable workflow must be pinned to a commit SHA so what the pipeline runs cannot move out from under it. The
GitHub workflow is optional for every project: a missing one is no finding, and one that exists is checked as
warnings only. Other projects are not part of the stack contract: a GitLab pipeline that runs `composer ci` is still
expected, but a missing or incomplete one is a warning.

**How the stack holds it: Enforced.** For stack packages (composer.json `name` starting with `waynestate/`), the
`ci-pipelines` check fails when `.gitlab-ci.yml` is missing, unparseable, or runs no `composer ci`.
`.github/workflows/ci.yml` is optional for every project: a missing file is no finding, and a present one is checked
as warnings only, never fails. A private stack package (composer.json `extra.proctor.github-mirror` set to `false`)
must never be on GitHub, so a shipped workflow warns (`proctor/ci-pipelines/github-private`). A GitHub `uses:` ref
that is not a 40-hex commit SHA is a warning either way.
`CiPipelinesCheckTest` pins the fixture outcomes for stack packages and other projects alike, and
`CheckCommandTest` runs the good app and the acme apps through the whole CLI.

### Pin Preflight components to a release tag or a commit SHA and leave their jobs alone
An app that includes a Preflight component should pin it to a release tag (such as `@0.3.0`) or a commit SHA rather
than a branch, `~latest` or a partial version, so the pipeline cannot change out from under the app between releases,
and it should not re-declare the `script` of a job the component already defines, because that hides what the
component was meant to run. A release tag is a pin because published tags in the stack are never moved or deleted; a partial version such as `0.3` is not, because GitLab resolves it to the newest matching release.

**How the stack holds it: Checked.** The `preflight-pinned` check warns, never fails, when a `.gitlab-ci.yml` include
of any CI component (an `include:` entry with a `component:` value) is at a ref that is neither a full release tag
(`MAJOR.MINOR.PATCH`, optional leading `v`) nor a 40-lowercase-hex commit SHA (`proctor/preflight-pinned/unpinned`; a
branch, `~latest`, a partial version or no `@` all warn), and, Preflight-only, when the app
declares one of the Preflight job names (`proctor`, `php-test`, `composer-audit`, `sbom`, `pint`, `phpstan`, `eslint`,
`renovate`, `package-publish`) with `script`, `before_script` or `after_script` while a Preflight component (a
`component:` path containing `/preflight/`) is included (`proctor/preflight-pinned/script-override`).
`PreflightPinnedCheckTest` pins the fixture outcomes for
pinned and unpinned includes and for overridden jobs.

### Flag a pipeline that skips or weakens Preflight
Preflight's practice 3.3 asks for a check that flags an app with no Preflight include, an unpinned include,
`allow_failure` on a gate, or no `proctor` job: on GitLab Community Edition an app can leave an include out or soften
it, and a standard nobody can see being skipped is not a standard. The unpinned include is `preflight-pinned`'s
finding (above).

**How the stack holds it: Checked.** The `preflight-gates` check reads `.gitlab-ci.yml` as YAML and warns, never
fails, on the other three: no `component:` include whose path contains `/preflight/`
(`proctor/preflight-gates/no-include`); a gate component (every Preflight component but `renovate` and
`package-publish`) included with the `allow_failure` input set to `true`, or its job re-declared with `allow_failure`
set to anything but `false` or a `rules:` entry with `allow_failure: true` (`proctor/preflight-gates/allow-failure`); a re-declared gate job that never runs,
`when: never` or `rules:` that all say so (`proctor/preflight-gates/skipped`), or that GitLab leaves optional, `when: manual`
without `allow_failure: false` (`proctor/preflight-gates/manual`); and no Preflight `proctor` include and no job
whose `script` runs `proctor check` or `proctor gate` (`proctor/preflight-gates/no-proctor-job`). A warning, not a
fail: Sourdough's pipeline keeps `allow_failure` on its deploy layer's proctor job while its findings are fixed
(PROCTOR's own dropped it once its deploy layer passed). `PreflightGatesCheckTest` pins each rule, its line and its message. What remains: the check sees only the
committed file, so a fleet view across every app is ROLLCALL's to build.

## Framework lines

### The laravel-eol check
CREED's handbook asks an app to run a Laravel release that still gets security fixes. The policy already records each
Laravel line's active and security dates, and the reader can classify a version against them; what was missing was a
check that applies the verdict to the lock.

**How the stack holds it: Checked.** The `laravel-eol` check classifies the locked `laravel/framework` version against
the policy as of `--today`: active passes, security-only warns naming the end date and the days left, eol fails, and a
line past the security end date plus the grace window fails as `eol_grace_expired` (rule id `proctor/laravel-eol`). An
active override downgrades the eol fail, never the grace-expired one. No finding without `laravel/framework` in
composer.lock. `LaravelEolCheckTest` pins the fixture outcomes for every state and for the override.

### The laravel-php check
CREED's handbook asks an app to match its framework release to a PHP version it supports. The policy records each
Laravel major's PHP range; what was missing was a check that compares the range with the app's declared floor.

**How the stack holds it: Checked.** The `laravel-php` check fails when composer.json's `require.php` allows no PHP
version inside the policy range for the locked Laravel major, or when its lowest allowed version is below the range's
floor, and passes otherwise; the upper end is open by design, so `^8.2` passes (rule id `proctor/laravel-php`). No finding without `laravel/framework` in composer.lock, without a recorded range, or without a
`require.php`: php-floor owns the missing-constraint verdict. `LaravelPhpCheckTest` pins the fixture outcomes for the
passes (the skeleton, a caret above the floor, a tilde), both fail rules (below the floor, no overlap), the override and the skips.

### The node-eol check
CREED's handbook asks an app to build and run on a Node line that still gets security fixes, and the policy records
each Node line's dates. What was missing was a check that reads which Node a repository actually names.

**How the stack holds it: Checked.** The `node-eol` check grades every Node version the repository pins (`.nvmrc`,
`.node-version`, package.json `volta.node`, an official `node` image in `.gitlab-ci.yml` or on a Dockerfile `FROM`
line) with the same table as `php-floor`: active passes, security-only warns with the days left, eol fails, and past
the grace window it fails as `eol_grace_expired`, which no override softens. `engines.node` is a declaration, so its
lowest major warns at worst (`proctor/node-eol/engines`); an odd major warns wherever it is named
(`proctor/node-eol/odd-major`). Silent when the policy has no node component. `NodeEolCheckTest` pins every source and
state.

### The mysql-eol check
CREED's handbook asks an app to run a database engine line that still gets security fixes, and the policy records
each MySQL and MariaDB line's dates. What was missing was a check that reads which database a repository actually names.

**How the stack holds it: Checked.** The `mysql-eol` check grades every MySQL or MariaDB version the repository names
(an official `mysql` or `mariadb` image on an `image:` line in a root `docker-compose*.yml` or `compose*.yml`, and
`SQUIRE_MYSQL_IMAGE=mysql:<version>` in `.env.squire.example` or `.env.example`) with the same table as `php-floor`,
except a supported version stays silent instead of passing and nothing fails: active is silent, security-only warns
with the days left, and eol and `eol_grace_expired` warn until Citadel's shared database server offers a supported
MySQL; then eol fails again. A `@sha256:` digest
after the tag is tolerated; the version comes from the tag. Silent when the policy has no mysql or mariadb component.
`MysqlEolCheckTest` pins every source and state.

## App code and the served folder

### The env-outside-config check
After `php artisan config:cache`, Laravel no longer loads .env, so `env()` outside config/ returns null in production.

**How the stack holds it: Checked.** The `env-outside-config` check warns for every `env()` call in a Laravel app
outside the top-level `config/` (PHP read on the syntax tree, Blade by a quoted-argument pattern that skips `@env`). A
call carrying Larastan's own ignore marker for the same rule is an info note (`proctor/env-outside-config/suppressed`).
Warnings only. `EnvOutsideConfigCheckTest` pins the flagged calls, the skipped look-alikes and the suppressed note.

### The raw-identity-header check
Drawbridge, the gateway, signs the session into the X-Sesame-Assertion header and passes the user's claims as request
headers; CREED's handbook says the gateway driver accepts only the signed assertion, never a bare header (auth-3).

**How the stack holds it: Checked.** The `raw-identity-header` check warns, never fails, when app code reads an
identity header directly: a string key on `$_SERVER` or `$_ENV`, the quoted argument of `getenv()` or `apache_getenv()`,
the quoted first argument of a method named `header`, `server`, `hasHeader`, `getHeader` or `getHeaderLine` (and of
`get()` on a `->headers` object), or a key on the result of `getallheaders()` or `apache_request_headers()`. PHP is read
on the syntax tree, so a name in a comment or a log string is not flagged; names match case-insensitively with `-` and
`_` interchangeable, and the `HTTP_` prefix Apache adds to request headers is accepted where the read comes from
`$_SERVER` or the environment. Since 0.8.0, a direct read of `X-Sesame-Assertion` (the signed assertion Drawbridge
sends) is flagged too (`src/Check/RawIdentityHeaderCheck.php:285`); `PHP_AUTH_USER` is basic-auth material and is not
an identity claim, so it is not flagged. Warnings only. `RawIdentityHeaderCheckTest` pins the flagged reads, the
skipped look-alikes and the clean pass.

### The app-key check
Whoever holds a Laravel APP_KEY can forge encrypted payloads, so the key never lives in the repository, and a cookie
session driver makes a leaked key worse.

**How the stack holds it: Checked.** The `app-key` check warns on a committed real env file
(`proctor/app-key/committed-env`), a real key in any committed env file or literal in `config/app.php`
(`proctor/app-key/committed-key`, the key never printed), and the cookie session driver
(`proctor/app-key/cookie-sessions`). A real env file is graded only when PROCTOR knows it is committed (git lists it,
or the run is in CI), so a developer's own `.env` never raises a flag. Rotation after a leak stays a process.
`AppKeyCheckTest` pins every rule, the local-only skip and the CI grading.

### The vite-secrets check
Vite exposes every variable whose name starts with `VITE_` to the client code it bundles, so the value reaches every
visitor's browser. Sourdough's practice treats the prefix as public and keeps secrets on the PHP side.

**How the stack holds it: Checked.** The `vite-secrets` check warns, never fails, on a secret-looking `VITE_` name
(a word `SECRET`, `TOKEN`, `PASSWORD`, `PASSWD`, `PASSPHRASE`, `CREDENTIAL` or `APIKEY`, a word starting with
`PRIVATE`, `API_KEY` and its kin, or `VITE_APP_KEY`) declared in a committed env file
(`proctor/vite-secrets/env-file`) or read through `import.meta.env` in `resources/js/` or `src/`
(`proctor/vite-secrets/source`). Example env files are always read; any other `.env*` only when git lists it or the
run is in CI, and only the name is matched, never the value. Pusher and Reverb app keys are public by design and pass.
`ViteSecretsCheckTest` pins both rules, the name list, the look-alikes that pass and the local-only skip. What
remains: a name that does not say secret (`VITE_STRIPE`) is not caught, and Vite's `envPrefix` option, which can widen
the exposed prefix, is not read.

### The served-folder and world-writable checks
A file in `public/` can be downloaded by anyone, and a world-writable folder can be changed by any process on the host.

**How the stack holds it: Checked.** `served-folder` warns when a committed file under the top-level `public/` calls
`phpinfo()` or is a dump, backup, editor copy, log, env file or `adminer*.php`. `world-writable` warns on `chmod 777`
(and `0777`, `a+rwx`, `ugo+rwx`) in shell scripts, Dockerfiles, Makefiles, `.gitlab-ci.yml`, composer and npm scripts,
and on a PHP `chmod()` with the literal `0777`. Both warn, never fail. Whether the container runs as a non-root user is
a runtime fact and stays outside PROCTOR. `ServedFolderCheckTest` and `WorldWritableCheckTest` pin the outcomes.
