# PROCTOR best practices

Status: draft, checked against sources on 2026-10-27, second pass done the same day. Applies to PROCTOR v0.1.0.
Items marked "(second pass)" were added or changed in the second pass; see the section of that name near the end.

## What this covers

PROCTOR is the stack's one command for measuring a PHP app against the WSU policy file (`policy.json` from CREED):
`vendor/bin/proctor check`. It looks at the PHP floor, unbounded Composer constraints, a committed lock file,
`composer audit` results and dated overrides. It prints text, JSON or GitLab Code Quality.

This guide is for two groups:

- **WSU developers** who run PROCTOR on an app, read its output, and write overrides.
- **PROCTOR maintainers** who add checks, change output, or decide how the gate behaves.

It lists practices for this domain: dependency and version policy checks, and the design of a checker that runs in CI.
Each practice says what to do, why, where the idea comes from, and how the stack holds it today.

PHP that has reached end of life is not supported. PROCTOR treats it as a failure, and so does the rest of the stack.

### The four tags

| Tag | Meaning |
|---|---|
| `Enforced` | Code makes the bad outcome impossible, or the build fails. The entry names what. |
| `Checked` | PROCTOR or CI warns or fails. The entry names the check. |
| `Documented` | Written down only. Nothing fails if it is ignored. |
| `Not yet` | A gap. Neither code nor text covers it today. |

Tags come from the PROCTOR repo (`src/`, `tests/`, `README.md`, `bin/proctor`) and the stack design documents as read
on 2026-10-27. Where a design document describes something that is not built, the tag is `Documented` or `Not yet`.

---

## 1. Policy as data

### 1.1 Keep dates and thresholds in the policy file, not in code
Support dates, the grace window and the audit severity threshold are read from the policy file at run time. The tool
holds no dates. A PHP version missing from the policy is a failure, not a pass.

Why: one file changes when a version's dates change, and every tool agrees.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's version-policy decision.

**How the stack holds it: Enforced.** `JsonFilePolicySource` derives the state of each version from the file.
`PolicyStateTest` pins the boundary days. An unknown version raises "version X not in policy", which the php-floor
check reports as a failure.

### 1.2 Take the policy and the date from a source the app cannot change
In CI the policy file and "today" must come from the stack, not from the app being checked. If the app can pick
`--policy` or `--today`, it can pass itself.

Why: a gate that the checked party configures is not a gate.

Sources: [OWASP Top 10 CI/CD Security Risks, CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/);
[GitLab: how included configuration is merged](https://docs.gitlab.com/ci/yaml/includes/).

**How the stack holds it: Not yet.** `--policy` is required and takes any path. `--today` is accepted on every run.
PREFLIGHT's `proctor_command` input is free text, and the main `.gitlab-ci.yml` of an app overrides included job keys,
so an app can change both. The plan is for PROCTOR to bundle CREED's policy once CREED v0.1.0 is tagged.

### 1.3 Version the policy schema and read newer files without breaking
A checker should accept a policy file with a newer minor schema or extra fields and ignore what it does not know.
Rejecting any other version breaks every pinned older checker on the day the policy gains a field.

Why: ADR-0003 says older checkers must still read newer policy files.

Sources: the stack's version-policy decision; [GitLab Code Quality format: only the listed fields are processed](https://docs.gitlab.com/ci/testing/code_quality/)
(an example of a reader that ignores extras).

**How the stack holds it: Not yet.** `JsonFilePolicySource` fails with "unsupported schema_version" unless the value is
exactly `1`. That is the opposite of what ADR-0003 asks for.

### 1.4 Read the banned-constraint list from the policy
The policy has `rules.banned_constraints`. The check must use it, so a change to the rule needs no PROCTOR release.

Why: otherwise the policy file says one thing and the tool does another.

Sources: the stack's version-policy decision.

**How the stack holds it: Not yet.** `UnboundedConstraintsCheck` hard-codes `*` and `dev-` in code. The policy list is
validated nowhere and read nowhere.

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

**How the stack holds it: Not yet.** PROCTOR's own `composer.json` sets `platform.php` to `8.3.0`, but no check asks
other apps to do the same.

### 2.5 Check the PHP that will serve the app, not only what composer.json declares
The deploy image is the real floor. A declared `^8.4` on an app that deploys on an older image proves nothing.

Why: declared and actual versions drift.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's deploy-gate decision.

**How the stack holds it: Documented.** ADR-0006 proposes `proctor gate` against the target image tag. The command does
not exist yet. The gate is platform-neutral: on Citadel it runs from SQUIRE's `citadel` target, whose hook is not
agreed yet, and other hosts run the same gate as a CI job.

### 2.6 A tool must pass its own check
The stack's own repos use the same policy as apps. A floor that PROCTOR would fail must be raised in a new major
before the date.

Why: a tool that cannot meet its own rule has no standing to enforce it.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); the stack's version-policy decision.

**How the stack holds it: Not yet.** PROCTOR requires `php: ^8.3`, and its pipeline tests 8.3, 8.4 and 8.5. PHP 8.3
is security-only and reaches end of life on 2027-12-31, so the floor must move to `^8.4` before then or the floor
check fails on PROCTOR itself. PROCTOR's own pipeline does not run the `proctor` component yet.

### 2.7 Put the end date in the security-only warning (second pass)
A warning that says "security-only" gives the owner no deadline. Print the date security support ends and the days left.

Why: the owner can plan from a date. A bare warning is easy to leave for later, and 8.3 is already in this state and
8.4 joins it on 2027-01-01.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php).

**How the stack holds it: Not yet.** `PhpFloorCheck` prints "php floor X is in security-only support" and nothing more.
The policy file already holds the date.

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

**How the stack holds it: Not yet.** A token that does not start with `dev-` and is not `*` passes. `>=`, `@dev`,
`minimum-stability` and the `dev-main` form in `require-dev` are not looked at.

### 3.3 Reject path and non-HTTPS repositories in committed files
A `path` repository points at a local folder that other machines and CI do not have. A plain-HTTP repository can be
tampered with in transit.

Why: both make the lock file unreproducible or unsafe.

Sources: [Composer config: secure-http](https://getcomposer.org/doc/06-config.md); the stack's one-repo-per-project decision.

**How the stack holds it: Not yet.** ADR-0008 says Check flags committed `path` repositories. No such check exists.

### 3.4 Commit `composer.lock` and install from it
Applications and libraries both commit the lock so everyone installs the same versions.

Why: without it, each install resolves fresh and can pick up a release nobody reviewed.

Sources: [Composer basic usage: the lock file](https://getcomposer.org/doc/01-basic-usage.md).

**How the stack holds it: Checked.** `lock-committed` fails when `composer.lock` is absent from the checked path. In CI
the working tree is a clone, so presence means committed. On a developer machine the check cannot tell a lock that is
git-ignored.

### 3.5 Require the lock to match `composer.json`
A lock whose content hash no longer matches `composer.json` describes a different tree from the one declared.

Why: an audit of a stale lock reports on packages the next install will not use.

Sources: [Composer CLI: validate and install](https://getcomposer.org/doc/03-cli.md);
[Composer basic usage](https://getcomposer.org/doc/01-basic-usage.md).

**How the stack holds it: Not yet.** PROCTOR checks only that the file exists. `composer validate --check-lock` does the
real test. ADR-0006 lists "present and matches" as a gate rule. (Second pass: the Composer 2.11.0-RC1 changelog,
dated 2026-10-22, says a change to the `policy` config now marks the lock as outdated. So a lock-in-sync check will
start to report that case once apps move to Composer 2.11. RC1 is not a stable release.)

### 3.6 Make Composer configuration explicit and safe
Do not set `allow-plugins` to `true`. Do not turn off `secure-http` or TLS. Leave `lock` on.

Why: plugins run code during install, and an open allow-list lets any dependency add one.

Sources: [Composer config: allow-plugins, secure-http, lock](https://getcomposer.org/doc/06-config.md).

**How the stack holds it: Not yet.** No check reads the `config` block.

### 3.7 Install from the lock in CI and never re-tag a release (second pass)
In CI run `composer install`, which reproduces the locked versions. Do not run `composer update` in a deploy pipeline.
Package authors publish a new version instead of moving an existing tag.

Why: the 2026 Packagist attacks (laravel-lang on 2026-05-22 and intercom-php) worked by changing existing git tags.
Packagist says `install` reproduces the locked commit hashes, while `update`, `require` and `update -W` refresh metadata
and can rewrite lock entries even when a version was re-tagged. OWASP's 2025 Top 10 now lists "Software Supply Chain
Failures" as A03 and notes that only 11 CVEs map to its CWEs, so an advisory-only audit will miss most of it.

Sources: [Packagist: an update on Composer and Packagist supply chain security, 2026-05-27](https://blog.packagist.com/an-update-on-composer-packagist-supply-chain-security);
[OWASP Top 10 2025, A03 Software Supply Chain Failures](https://top10.owasp.org/2025/A03_2025-Software_Supply_Chain_Failures).

**How the stack holds it: Not yet.** The PREFLIGHT `proctor` component runs `composer install`, so it uses the lock.
Nothing stops a custom job from running `update`, and PROCTOR cannot see the pipeline.

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
An advisory with no severity, or one the tool does not recognise, is treated as if it were at the threshold.

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

**How the stack holds it: Not yet.** A missing `composer` or lock gives an `info` finding, and unparsable output gives a
`warning`. Both exit 0. PREFLIGHT's `composer-audit` job covers part of this because it fails on Composer's own exit
code.

### 4.4 Keep advisory ignores out of `composer.json`
Composer lets an app silence advisories in its own `composer.json` (`config.audit.ignore`, or in Composer 2.10
`config.policy.advisories.ignore-id`). Those ignores have no expiry and are invisible to the override file.
(Second pass: `audit.ignore` is now marked deprecated in the Composer docs. It takes a plain list, a map of id to
reason, or a detailed form with an `apply` value of `audit`, `block` or `all`. `ignore-severity` and `ignore-abandoned`
do the same job for whole severities and packages, so a check must look at all three.)

Why: they bypass the dated, visible override process the policy depends on.

Sources: [Composer config: audit and policy](https://getcomposer.org/doc/06-config.md);
[Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/).

**How the stack holds it: Not yet.** PROCTOR runs `composer audit` and acts on its output. It has no check that
reports an ignore entry in `composer.json`, so an app can silence an advisory without any override.

### 4.5 Cover malware and abandoned-package results
Composer 2.10 audits malware by default. Abandoned packages also fail the audit by default: the Composer docs give
`abandoned.audit` a default of `fail` (the `audit.abandoned` default moved from `report` to `fail` in Composer 2.7).
A policy check should either read those results or say it does not.

Why: the advisory list is no longer the only thing `composer audit` can fail on. (Second pass correction: the first
pass said 2.10 "reports" abandoned packages. By default it fails on them, so an app with an abandoned package gets a
red `composer audit` and, today, a green PROCTOR.)

Sources: [Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/);
[Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Not yet.** PROCTOR reads only the `advisories` key. A run can pass in PROCTOR while Composer's
own exit code is 1.

### 4.6 Pin the Composer version that does the audit
Defaults have changed between Composer releases (the abandoned-package default moved in 2.7, and 2.10 added
`config.policy` and made `config.audit` a fallback). The same lock can audit differently on two Composer versions.

Why: results should depend on the lock and the policy, not on which Composer the runner has.

Sources: [Composer config: audit](https://getcomposer.org/doc/06-config.md);
[Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/).

**How the stack holds it: Not yet.** PROCTOR runs whichever `composer` it finds first on the PATH and does not report
the version. (Second pass: the Composer 2.10.2 changelog lists "Fixed `audit` command to output the audit result to
stdout". PROCTOR reads only stdout, so a Composer older than 2.10.2 is one more reason to set a minimum. Composer
2.11.0-RC1 was released 2026-10-22 and 2.10.3 is the latest stable in the changelog; check before pinning.)

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

**How the stack holds it: Not yet.** No PREFLIGHT or PROCTOR document asks for a scheduled run.

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

**How the stack holds it: Not yet.** PROCTOR does not read the Composer version, and the PREFLIGHT component installs
whatever Composer is current at the time.

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

**How the stack holds it: Not yet.** This extends 4.4. PROCTOR looks at neither the `config` block nor the process
environment.

### 4.10 Do not trust a clean JSON result when Composer's exit code is non-zero (second pass)
If the JSON has no advisories but `composer audit` exited 1, treat it as a finding or a tool error. Composer 2.10.0
made the audit exit code "always 0 (success) or 1 if anything failed the audit", and the CLI docs say 1 also covers
failing because required packages are missing.

Why: abandoned packages, malware and custom policies can fail the audit without an entry under `advisories`.

Sources: [Composer changelog, 2.10.0](https://raw.githubusercontent.com/composer/composer/main/CHANGELOG.md);
[Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Not yet.** `ComposerAuditCheck` ignores the exit code once the output parses as JSON. That
is what makes the 4.5 gap possible. Note that Composer's own exit code 2 means a dependency-solving error, which is
not PROCTOR's meaning of 2.

### 4.11 Watch the cooldown policy and decide on it later (second pass)
Composer 2.11.0-RC1 (2026-10-22) adds a `cooldown` policy that withholds newly published versions for a set period. The
Composer docs describe it as off by default. The Packagist post of 2026-05-27 lists a minimum release age as planned
work, because of the tag-modification attacks.

Why: a new release that is compromised is usually found within days, so a short wait removes most of that exposure.

Sources: [Composer changelog, 2.11.0-RC1](https://raw.githubusercontent.com/composer/composer/main/CHANGELOG.md);
[Composer config](https://getcomposer.org/doc/06-config.md);
[Packagist supply chain update](https://blog.packagist.com/an-update-on-composer-packagist-supply-chain-security).
The claim that most compromises are found within days is mine and is not backed by a source I read.

**How the stack holds it: Not yet.** Do not require it until 2.11 is stable and the base images ship it.

### 4.12 Decide whether dev dependencies count (second pass)
`composer audit --locked` audits `require-dev` packages too. Composer offers `--no-dev`, and `--direct` from
2.11.0-RC1 on (per its changelog).

Why: a vulnerable test tool is not in the deployed image, and failing the gate on it sends owners to fix the wrong
thing. The reverse is also possible: a dev dependency can run during CI.

Sources: [Composer CLI: audit](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Not yet.** The policy does not say, and PROCTOR audits everything in the lock. This is a
policy decision for the stack, not a coding one.

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

**How the stack holds it: Not yet.** An active override for `composer-audit` turns every audit failure into a warning,
including advisories published after the override was written.

### 5.5 Cap how long an override can run
The expiry should not be later than the related forced-upgrade deadline.

Why: a far-future date is a permanent override with extra steps.

Sources: the stack's deploy-gate decision.

**How the stack holds it: Documented.** ADR-0006 states the rule for the deploy gate. `OverrideFile` accepts any
valid date.

### 5.6 Do not let an override waive the override check, and reject unknown check names
**Reading the code:** an active override whose `check` is `overrides` turns an expired entry's failure into a warning.
An override naming a check that does not exist is accepted and does nothing.

Why: the expiry rule is only as strong as the rule that enforces it.

Sources: [OWASP CICD-SEC-1, Insufficient Flow Control Mechanisms](https://owasp.org/www-project-top-10-ci-cd-security-risks/).

**How the stack holds it: Not yet.** No test covers either case. Both are visible in the output, which limits the harm.

### 5.7 Have someone other than the author approve an override
`approved_by` is a free-text string. The file sits in the app repo and its author can edit it.

Why: an approval that nobody checks is a note.

Sources: [GitLab protected branches: Code Owner approval is Premium or Ultimate](https://docs.gitlab.com/user/project/repository/branches/protected/).

**How the stack holds it: Not yet.** ADR-0006 moves override storage to the platform admins for the deploy gate
(Citadel's admins today, through SQUIRE's `citadel` target). In CI there
is no check that the approver exists or differs from the author, and git.wayne.edu runs GitLab Community Edition, which
has no code owner approval. (Second pass: the GitLab protected-branches page still lists Code Owner approval as
Premium and Ultimate.)

### 5.8 Report an override that matches nothing, and one close to expiry (second pass)
Print a warning when an active override targets a check that raised no failure, and when an expiry is within a set
number of days.

Why: PHPStan tells you when a baseline entry no longer matches an error and asks you to remove it
(`reportUnmatchedIgnoredErrors`). A stale exception is clutter that hides the real ones. A warning before expiry means
the owner fixes the cause instead of finding a red build.

Sources: [PHPStan: baseline](https://phpstan.org/user-guide/baseline).
The warning-before-expiry part is my own suggestion and I have no prior-art source for the number of days.

**How the stack holds it: Not yet.** `OverrideApplier` only downgrades failures it finds. `OverridesCheck` reports
only entries that have already expired.

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
accepts only 0 or 1. `CheckCommandTest` asserts the codes on four fixture apps.

### 6.2 Publish results in the platform's native report format
For GitLab, emit Code Quality JSON and declare it as `artifacts:reports:codequality`. Import results directly, not
through the deprecated CodeClimate template.

Why: the merge request shows the findings with no extra tooling.

Sources: [GitLab Code Quality](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Enforced.** `CodeQualityWriter` writes the required fields, relative paths, and severities
`major`, `minor` and `info`. `CodeQualityShapeTest` checks the shape. The CodeClimate template was deprecated in GitLab
17.3 and is planned for removal in 19.0; PROCTOR does not use it. (Second pass: re-checked on the GitLab page. It is
still "planned for removal in 19.0" with no date. The accepted severities are `info`, `minor`, `major`, `critical` and
`blocker`; PROCTOR uses only the first three, so an unwaivable end-of-life failure shows as `major`, the same as any
other failure. The parser rejects a UTF-8 byte order mark.)

### 6.3 Make fingerprints stable and free of line numbers
A fingerprint identifies a finding across runs. GitLab compares them with the target branch to show new and resolved
problems.

Why: a line number changes with every edit, which would make old findings look new.

Sources: [GitLab Code Quality: fingerprints](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Enforced.** `Fingerprint::of` hashes the check id, path and message with SHA-256.

### 6.4 Always write a valid report, even when the tool fails
On exit 2 the report file should still be valid JSON (`[]` or one finding that names the error).

Why: an empty file turns a clear tool error into a second parse error in the merge request.

Sources: [GitLab Code Quality: report requirements](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Not yet.** Tool errors go to stderr and stdout stays empty, so the PREFLIGHT redirect leaves an
empty artifact.

Second pass: this is worse than it looks. GitLab says artifacts under `artifacts:reports` are "always uploaded,
regardless of the job results", and the PREFLIGHT component sets `artifacts:when: always`. So the empty file does reach
GitLab on exit 2 and is parsed there. The GitLab Code Quality page does not say what GitLab does with an empty file, so
the exact failure in the merge request is unverified.

### 6.5 Offer an interchange format when a second consumer needs it
SARIF 2.1.0 is the OASIS standard for static-analysis results. GitLab Code Quality is enough for merge requests today.

Why: other platforms and editors read SARIF.

Sources: [OASIS SARIF v2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html).

**How the stack holds it: Not yet.** Low priority. Add it only when a consumer asks.

### 6.6 Make runs reproducible
The date is injectable so a test gives the same answer on any day.

Why: policy results depend on dates, and tests must not drift.

Sources: the stack's version-policy decision.

**How the stack holds it: Enforced.** `--today` is validated as a real `YYYY-MM-DD` date, and every CLI test runs with a
fixed date and a fixed fixture policy. The same switch is a bypass if an app can set it in CI (see 1.2).

### 6.7 Never carry meaning by color alone (second pass)
Each result line starts with a word (`PASS`, `INFO`, `WARN`, `FAIL`), and the last line says `RESULT: pass` or
`RESULT: fail`. If color is ever added, it only repeats the word, and the `NO_COLOR` variable turns it off.

Why: WCAG Success Criterion 1.4.1 (Level A) says color must not be the only cue. The same holds in a terminal and in a
CI log viewer, for people who cannot tell the colors apart and for logs that strip ANSI codes. The `NO_COLOR`
convention says a non-empty `NO_COLOR` variable should prevent ANSI color.

Sources: [WCAG 2.2: Use of Color](https://www.w3.org/WAI/WCAG22/Understanding/use-of-color.html);
[NO_COLOR](https://no-color.org/).

**How the stack holds it: Enforced.** `TextWriter` writes plain text with a status word on every line and emits no ANSI
codes. No test asserts the absence of color, so this holds by construction only.

### 6.8 Escape strings that come from the app before printing them (second pass)
Messages contain package names, constraints and override reasons, all read from files the app controls. Escape them
before they reach the console writer.

Why: Symfony Console treats `<info>`-style tags in text passed to `writeln()` as formatting, and its docs describe
`OutputFormatter::escape()` for untrusted strings. A crafted package name could alter or hide a line in the log. I did
not find a primary source for raw terminal control characters in CI logs, so that part is a hypothesis.

Sources: [Symfony Console: coloring and tags](https://symfony.com/doc/current/console/coloring.html).

**How the stack holds it: Not yet.** `bin/proctor` passes the writer output straight to `$output->writeln()`. No test
feeds a tag-like string through a finding.

---

## 7. Adopting a rule without blocking fixes

### 7.1 Block regressions first, not every old violation
A gate that blocks only new problems lets owners ship fixes while the total can only fall. Diff-aware scanning in other
tools works this way.

Why: blocking all old violations on day one freezes the fixes that would clear them.

Sources: [Semgrep: diff-aware scanning and baseline commit](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci);
[PHPStan: baseline](https://phpstan.org/user-guide/baseline).

**How the stack holds it: Documented.** ADR-0006 describes the ratchet and the stages `report`, `warn` and `block`.
PROCTOR v0.1.0 has no ratchet and no stage field.

### 7.2 Keep gate mode small, offline and fast, and let a checker bug fail open
At deploy time the check should need no network and no app boot, finish in seconds, and exit so that an internal error
does not stop a deploy.

Why: a gate that can take down deploys on its own bug will be switched off.

Sources: [Semgrep: internal errors do not block](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci);
the stack's enforcement-points and deploy-gate decisions.

**How the stack holds it: Documented.** `proctor gate` does not exist. The current `composer-audit` check needs the
network and sets no timeout.

---

## 8. The tool itself

### 8.1 Run the checker without installing the app
Run PROCTOR from its own install and point `--path` at the app. Do not first run `composer install` on the app.

Why: installing an app runs its plugins and scripts, and an app on an old PHP cannot install on a new image, so the
check never runs.

Sources: [OWASP CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/);
[Composer CLI: `--no-plugins`, `--no-scripts`](https://getcomposer.org/doc/03-cli.md); the stack's deploy-gate decision.

**How the stack holds it: Not yet.** The README tells apps to `composer require waynestate/proctor --dev`, and the
`proctor` component runs `composer install` on the app first. That also adds `symfony/console` and `composer/semver`
to the app's own dependency tree.

### 8.2 Start child processes without a shell
Pass arguments as an array so a path or value cannot become a command.

Why: it removes a whole class of injection.

Sources: [OWASP CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/).

**How the stack holds it: Enforced.** `ProcessRunner` calls `proc_open` with an argument array.

### 8.3 Put a timeout on every child process
A hung `composer audit` must not hold a runner until the job limit.

Why: a stuck advisory call otherwise burns runner time and hides the real fault.

Sources: [GitLab CI: `timeout`](https://docs.gitlab.com/ci/yaml/).

**How the stack holds it: Not yet.** `ProcessRunner` waits with `stream_get_contents` and has no limit.
(Second pass: it reads all of stdout, then all of stderr. A child that fills the stderr pipe while PROCTOR is still
reading stdout can block both sides. This is a well-known `proc_open` trap, but I did not reproduce it here and did
not check the PHP manual for it. GitLab's `timeout` keyword would stop the job in the end, which is a poor failure
mode, so a limit and non-blocking reads belong in the tool.)

### 8.4 Test every exit path with a fixture app
Each result a user can see needs a fixture that produces it.

Why: a checker that is wrong in silence is dangerous.

Sources: [GitLab: test with a sample project that exercises the tool](https://docs.gitlab.com/ci/components/).

**How the stack holds it: Checked.** PROCTOR's pipeline runs PHPUnit on PHP 8.3 to 8.5 through the PREFLIGHT
`php-test` component, with fixtures for pass, end of life, unbounded and expired override. The audit parsing path
has no test: the CLI tests remove `composer` from the PATH, so no fixture produces a real advisory.

### 8.5 Sign and pin releases, and publish provenance
A release is a tag that Composer users can pin, with a signature or provenance that says where it was built.

Why: the checker runs in many pipelines, so a swapped release is a wide attack.

Sources: [SLSA v1.0 build levels](https://slsa.dev/spec/v1.0/levels) (page marked retired; v1.2 is current);
[OpenSSF Scorecard: Signed-Releases](https://github.com/ossf/scorecard/blob/main/docs/checks.md);
[CISA and NSA: Defending CI/CD environments](https://cisa.gov/news-events/alerts/2023/06/28/cisa-and-nsa-release-joint-guidance-defending-continuous-integrationcontinuous-delivery-cicd).

**How the stack holds it: Not yet.** No release is tagged. `Report::VERSION` is a constant in code and must be kept in
step with the tag by hand.

### 8.6 Start Composer with plugins off and a known environment (second pass)
Run `composer audit` with `--no-plugins` and `--no-interaction`, and pass an explicit environment instead of the
caller's.

Why: Composer reads many `COMPOSER_*` variables (`COMPOSER_NO_BLOCKING`, `COMPOSER_NO_AUDIT`, `COMPOSER_AUDIT_ABANDONED`,
`COMPOSER_AUTH`) that override `composer.json`, and `--no-plugins` is a documented global option. A check that must
not be talked out of its answer should not inherit settings from the job around it. I did not verify whether `audit`
loads installed plugins, so this is a precaution.

Sources: [Composer CLI: global options and environment variables](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Not yet.** `ProcessRunner` calls `proc_open` with no environment argument, so the child
inherits everything, and the audit command line carries no `--no-plugins`. It does use an argument array (8.2).

### 8.7 Treat the CI platform as part of the trust base (second pass)
Keep the GitLab instance and its runners on a patched release, and say so in the PREFLIGHT docs.

Why: GitLab's patch page for 2026-10-12 describes CVE-2026-97229, a CVSS 10.0 integer overflow in the CI/CD regular
expression compiler of CE and EE, exploitable by an unauthenticated user, fixed in 19.2.8, 19.3.5 and 19.4.2. A gate
that runs on an unpatched server proves little. This is outside PROCTOR's code, but the stack's guide should name it.

Sources: [GitLab patch release 19.4.2, 19.3.5, 19.2.8](https://docs.gitlab.com/releases/patches/patch-release-gitlab-19-4-2-released/).
Other 2026 GitLab CVEs came to me only through news summaries and I did not verify them.

**How the stack holds it: Not yet.** No stack document names a minimum GitLab version. PROCTOR cannot see the server
version.

---

## Gaps for the backlog

Practices tagged `Not yet` or `Documented`, each one line. Highest value first.

1. 1.2: Bundle CREED's policy, and refuse `--policy` and `--today` in CI unless a trusted job sets them.
2. 8.1: Run PROCTOR from its own install without `composer install` on the app (PHAR or separate install).
3. 4.4: Report any `audit`/`policy` ignore entry in `composer.json` as a finding, and move ignores to the override file.
4. 4.3: Make a skipped or failed audit a failure in CI, with a `not audited` message.
5. 3.5: Add a lock-in-sync check (`composer validate --check-lock` or the content hash).
6. 5.6: Reject an override that targets `overrides` or an unknown check name, and add tests.
7. 2.6: Raise PROCTOR's own floor before 2027-01-01 and run the `proctor` component in its own pipeline.
8. 1.4: Read `banned_constraints` from the policy.
9. 3.2: Flag `>=` and other open upper bounds, `@dev` flags and `minimum-stability`.
10. 4.5: Read malware and abandoned results from `composer audit`.
11. 4.6: Record the Composer version in the report and pin it in CI.
12. 5.4: Let an override name a package or advisory id.
13. 5.5: Reject an `expires` date beyond a policy-set maximum.
14. 5.7: Require an approver who is not the author (needs review rules on the app repo).
15. 1.3: Accept newer policy schemas with unknown fields.
16. 3.3: Flag committed `path` and non-HTTPS repositories.
17. 3.6: Check `allow-plugins`, `secure-http` and `lock` in the `config` block.
18. 2.4: Ask for `config.platform.php` equal to the floor.
19. 8.3: Add a timeout to child processes.
20. 6.4: Write a valid empty report on tool errors.
21. 4.7: Add a scheduled audit pipeline to the PREFLIGHT docs.
22. 8.5: Tag, sign and publish provenance for releases.
23. 7.1 and 7.2: Build `proctor gate` with the ratchet, stages and fail-open exit.
24. 2.5: Check the target image tag once the gate exists.
25. 6.5: Add SARIF output if another consumer needs it.
26. 8.4 (tagged Checked, but the audit path is untested): Add a fixture and test that produces a real `composer audit`
    result.

Added in the second pass (2026-10-27):

27. 4.8: Set a minimum Composer version (2.10.3, or 2.2.30 on the LTS line) and fail below it. PROCTOR hands Composer
    files the app controls, and three Composer command-injection CVEs were fixed in 2026. High value, small change.
28. 4.10 and 4.5: Compare Composer's exit code with the parsed result, and read the abandoned and malware parts of the
    output. Abandoned packages fail `composer audit` by default, so PROCTOR can pass what Composer fails.
29. 6.4 (raise priority): Write a valid report on exit 2. GitLab uploads the report even when the job fails, so the
    empty file is parsed.
30. 4.9: Flag `config.policy: false`, a disabled malware or advisory policy, and `COMPOSER_NO_BLOCKING` or
    `COMPOSER_NO_AUDIT` in the job environment.
31. 8.6: Run Composer with `--no-plugins --no-interaction` and an explicit environment.
32. 6.8: Escape app-supplied strings before printing, and add a test with a tag-like package name.
33. 5.8: Warn on an override that matches nothing and on one close to expiry.
34. 2.7: Put the security-end date and days left in the security-only warning.
35. 3.7: Document "install from the lock, never update" in the PREFLIGHT guide, and ask authors not to re-tag.
36. 4.12: Decide in the policy whether `require-dev` packages count, then pass `--no-dev` or not.
37. 8.7: Name a minimum patched GitLab version in the PREFLIGHT guide.
38. 4.11: Re-read the Composer cooldown policy once 2.11 is stable, and decide whether to ask for it.
39. 6.7 (tagged Enforced by construction): Add a test that the text output contains no ANSI escape codes.

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
- 4.4: the `audit.ignore` setting is deprecated in favour of `policy.advisories.ignore-id`, and `ignore-severity` and
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
| GitLab: use CI/CD configuration from other files (merge behaviour) | https://docs.gitlab.com/ci/yaml/includes/ |
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

Internal design documents used for tagging: ADR-0002, ADR-0003, ADR-0006, ADR-0008, the PROCTOR repo, and the PREFLIGHT repo.

## Platform assumptions

### The portability check
An app must run on Citadel, OpenShift, Coolify or raw Docker, so platform facts belong in env or config, not in code.

**How the stack holds it: Checked.** The `portability` check warns on absolute host paths, hard-coded proxy
addresses, sessions on local disk, and `php_value` or `php_flag` lines in `.htaccess` (they do nothing under
php-fpm). It reports warnings, not failures, while the stack stages the rule.

## CI pipelines

### The ci-pipelines check
Every stack repo runs `composer ci` on both GitLab and GitHub (ADR-0012, decision 6), so the two pipeline files are
part of the contract, and a reusable workflow must be pinned to a commit SHA so the contract cannot move out from
under the pipeline.

**How the stack holds it: Enforced.** The `ci-pipelines` check fails when `.gitlab-ci.yml` or
`.github/workflows/ci.yml` is missing, unparseable, or runs no `composer ci`, and warns when a GitHub `uses:` ref
is not a 40-hex commit SHA. `CiPipelinesCheckTest` pins the fixture outcomes, and `CheckCommandTest` runs the good
app through the whole CLI.
