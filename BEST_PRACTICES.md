# PROCTOR best practices

Status: draft, checked against sources on 2026-10-27. Applies to PROCTOR v0.1.0.

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

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); ADR-0003 (internal).

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

Sources: ADR-0003 (internal); [GitLab Code Quality format: only the listed fields are processed](https://docs.gitlab.com/ci/testing/code_quality/)
(an example of a reader that ignores extras).

**How the stack holds it: Not yet.** `JsonFilePolicySource` fails with "unsupported schema_version" unless the value is
exactly `1`. That is the opposite of what ADR-0003 asks for.

### 1.4 Read the banned-constraint list from the policy
The policy has `rules.banned_constraints`. The check must use it, so a change to the rule needs no PROCTOR release.

Why: otherwise the policy file says one thing and the tool does another.

Sources: ADR-0003 (internal).

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
2029-12-31.

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

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); ADR-0003 (internal).

**How the stack holds it: Enforced.** `rules.eol_grace_days` sets the window. `OverrideApplier` never downgrades a
failure in the `eol_grace_expired` state, whatever overrides the app carries.

### 2.4 Set `config.platform.php` to the floor
When the lock is resolved against the lowest supported PHP, it cannot contain a package that needs a newer one. Without
it, a lock built on PHP 8.5 may not install on the floor.

Why: Composer's `platform` setting fakes the PHP version for resolution, and the docs advise checking platform
requirements at deploy time because the setting weakens dependency checks.

Sources: [Composer config: platform](https://getcomposer.org/doc/06-config.md).

**How the stack holds it: Not yet.** PROCTOR's own `composer.json` sets `platform.php` to `8.2.0`, but no check asks
other apps to do the same.

### 2.5 Check the PHP that will serve the app, not only what composer.json declares
The deploy image is the real floor. A declared `^8.4` on an app that deploys on an older image proves nothing.

Why: declared and actual versions drift.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); ADR-0006 (internal).

**How the stack holds it: Documented.** ADR-0006 proposes `proctor gate` against the target image tag. The command does
not exist yet, and the Citadel hook it needs is not agreed.

### 2.6 A tool must pass its own check
The stack's own repos use the same policy as apps. A floor that PROCTOR would fail must be raised in a new major
before the date.

Why: a tool that cannot meet its own rule has no standing to enforce it.

Sources: [PHP supported versions](https://www.php.net/supported-versions.php); ADR-0003 (internal).

**How the stack holds it: Not yet.** PROCTOR requires `php: ^8.2`. PHP 8.2 is already security-only and reaches end of
life on 2026-12-31, so the floor check fails on PROCTOR itself from 2027-01-01. PROCTOR's own pipeline does not run the
`proctor` component yet.

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

Sources: [Composer config: secure-http](https://getcomposer.org/doc/06-config.md); ADR-0008 (internal).

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
real test. ADR-0006 lists "present and matches" as a gate rule.

### 3.6 Make Composer configuration explicit and safe
Do not set `allow-plugins` to `true`. Do not turn off `secure-http` or TLS. Leave `lock` on.

Why: plugins run code during install, and an open allow-list lets any dependency add one.

Sources: [Composer config: allow-plugins, secure-http, lock](https://getcomposer.org/doc/06-config.md).

**How the stack holds it: Not yet.** No check reads the `config` block.

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
(a tool that fails on findings and states how it treats errors).

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
Composer lets an app silence advisories in its own `composer.json` (`config.audit.ignore`, or `config.policy` in
Composer 2.10). Those ignores have no expiry and are invisible to the override file.

Why: they bypass the dated, visible override process the policy depends on.

Sources: [Composer config: audit and policy](https://getcomposer.org/doc/06-config.md);
[Composer 2.10 release](https://blog.packagist.com/composer-2-10-release/).

**How the stack holds it: Not yet.** PROCTOR runs `composer audit` and acts on its output. It has no check that
reports an ignore entry in `composer.json`, so an app can silence an advisory without any override.

### 4.5 Cover malware and abandoned-package results
Composer 2.10 audits malware by default and reports abandoned packages. A policy check should either read those results
or say it does not.

Why: the advisory list is no longer the only thing `composer audit` can fail on.

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
the version.

### 4.7 Audit again on a schedule
New advisories appear after code is merged. A weekly scheduled pipeline finds them without a commit.

Why: a lock that was clean on Monday can be vulnerable on Friday.

Sources: [OpenSSF Scorecard: Vulnerabilities, Dependency-Update-Tool](https://github.com/ossf/scorecard/blob/main/docs/checks.md).

**How the stack holds it: Not yet.** No PREFLIGHT or PROCTOR document asks for a scheduled run.

---

## 5. Overrides

### 5.1 Give every override a reason, an expiry and an approver
An override is a JSON entry with `check`, `reason`, `expires` (a real calendar date) and `approved_by`. A missing or
invalid field stops the run.

Why: an exception without an end date becomes the permanent state.

Sources: ADR-0003 (internal); [PHPStan baseline: entries cannot carry reasons](https://phpstan.org/user-guide/baseline)
(the weakness that dated overrides avoid).

**How the stack holds it: Enforced.** `OverrideFile` rejects the file (exit 2) for a missing field or a date that does
not round-trip, such as `2027-13-99`. An override expires the day after its `expires` date, and `overrides` then fails.

### 5.2 Print every override on every run
The output lists each override, active or expired, with its reason and approver.

Why: an exception nobody sees cannot be questioned.

Sources: ADR-0003 (internal).

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

Sources: ADR-0006 (internal).

**How the stack holds it: Documented.** ADR-0006 states the rule for the Citadel gate. `OverrideFile` accepts any
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

**How the stack holds it: Not yet.** ADR-0006 moves override storage to Citadel admins for the deploy gate. In CI there
is no check that the approver exists or differs from the author, and git.wayne.edu runs GitLab Community Edition, which
has no code owner approval.

---

## 6. Output and exit codes

### 6.1 Use three distinct exit codes
`0` for pass, `1` for findings that fail, `2` for a tool error such as a bad option, an unreadable policy or a malformed
file.

Why: a pipeline must be able to tell "the app is bad" from "the checker broke".

Sources: [Semgrep: exit codes for findings and errors](https://docs.semgrep.dev/semgrep-ci/configuring-blocking-and-errors-in-ci);
[Composer CLI: exit codes](https://getcomposer.org/doc/03-cli.md).

**How the stack holds it: Enforced.** `bin/proctor` returns 2 for tool errors (unknown command included) and `Report`
accepts only 0 or 1. `CheckCommandTest` asserts the codes on four fixture apps.

### 6.2 Publish results in the platform's native report format
For GitLab, emit Code Quality JSON and declare it as `artifacts:reports:codequality`. Import results directly, not
through the deprecated CodeClimate template.

Why: the merge request shows the findings with no extra tooling.

Sources: [GitLab Code Quality](https://docs.gitlab.com/ci/testing/code_quality/).

**How the stack holds it: Enforced.** `CodeQualityWriter` writes the required fields, relative paths, and severities
`major`, `minor` and `info`. `CodeQualityShapeTest` checks the shape. The CodeClimate template was deprecated in GitLab
17.3 and is planned for removal in 19.0; PROCTOR does not use it.

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

### 6.5 Offer an interchange format when a second consumer needs it
SARIF 2.1.0 is the OASIS standard for static-analysis results. GitLab Code Quality is enough for merge requests today.

Why: other platforms and editors read SARIF.

Sources: [OASIS SARIF v2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html).

**How the stack holds it: Not yet.** Low priority. Add it only when a consumer asks.

### 6.6 Make runs reproducible
The date is injectable so a test gives the same answer on any day.

Why: policy results depend on dates, and tests must not drift.

Sources: ADR-0003 (internal).

**How the stack holds it: Enforced.** `--today` is validated as a real `YYYY-MM-DD` date, and every CLI test runs with a
fixed date and a fixed fixture policy. The same switch is a bypass if an app can set it in CI (see 1.2).

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
ADR-0002 and ADR-0006 (internal).

**How the stack holds it: Documented.** `proctor gate` does not exist. The current `composer-audit` check needs the
network and sets no timeout.

---

## 8. The tool itself

### 8.1 Run the checker without installing the app
Run PROCTOR from its own install and point `--path` at the app. Do not first run `composer install` on the app.

Why: installing an app runs its plugins and scripts, and an app on an old PHP cannot install on a new image, so the
check never runs.

Sources: [OWASP CICD-SEC-4 Poisoned Pipeline Execution](https://owasp.org/www-project-top-10-ci-cd-security-risks/);
[Composer CLI: `--no-plugins`, `--no-scripts`](https://getcomposer.org/doc/03-cli.md); ADR-0006 (internal).

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

### 8.4 Test every exit path with a fixture app
Each result a user can see needs a fixture that produces it.

Why: a checker that is wrong in silence is dangerous.

Sources: [GitLab: test with a sample project that exercises the tool](https://docs.gitlab.com/ci/components/).

**How the stack holds it: Checked.** PROCTOR's pipeline runs PHPUnit on PHP 8.2 to 8.5 through the PREFLIGHT
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

Internal design documents used for tagging: ADR-0002, ADR-0003, ADR-0006, ADR-0008, the PROCTOR repo, and the PREFLIGHT repo.
