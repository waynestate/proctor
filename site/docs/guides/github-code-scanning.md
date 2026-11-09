---
title: "GitHub code scanning | PROCTOR docs"
description: "How the sarif format feeds GitHub code scanning on the GitHub mirror through the official upload action."
url: https://proctor.apps.wayne.edu/docs/guides/github-code-scanning.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# GitHub code scanning[#](https://proctor.apps.wayne.edu/docs/guides/github-code-scanning.html#github-code-scanning)

The `sarif` format feeds GitHub code scanning on the GitHub mirror. This page adds the workflow that uploads the log with the official action, pinned to a full commit SHA. Run the check with `--format=sarif`, and let the scanner show the findings on the files. PROCTOR now files the mark sheet in the format the head office already reads; no more translating the exam results by hand.

## The workflow[#](https://proctor.apps.wayne.edu/docs/guides/github-code-scanning.html#the-workflow)

Add this workflow to the mirror. It sets up PHP, installs the app's dependencies from the lock (PROCTOR and CREED among them, as dev dependencies), runs the check and uploads the resulting log:

```yaml
name: proctor
on: [push, pull_request]
permissions:
  contents: read
  security-events: write
jobs:
  proctor:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
      - uses: shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962 # 2.40.0
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --no-progress
      # No --policy here: CI refuses it, and PROCTOR reads vendor/waynestate/creed's policy itself.
      - run: vendor/bin/proctor check --format=sarif > proctor.sarif
      - uses: github/codeql-action/upload-sarif@9b3254085494b55a0f7c4c2b3d2d52cc712ce370 # v4.39.1
        if: always()
        with:
          sarif_file: proctor.sarif
```

Three choices in it are on purpose, and each matches a PROCTOR check:

- Every `uses:` is pinned to a full commit SHA with the version in a comment; `ci-pipelines` warns on a tag or a branch (`proctor/ci-pipelines/unpinned-uses`).
- The job runs `composer install`, never `composer require` or `composer update`: CI installs from the lock (`proctor/ci-pipelines/composer-update`).
- There is no `--policy` and no `--today`. In CI (`GITHUB_ACTIONS` is set) PROCTOR refuses both with exit 2 unless a trusted job sets `PROCTOR_ALLOW_OVERRIDES=1`, so an app cannot grade itself against a policy or a date it picks. Without `--policy` it reads `vendor/waynestate/creed/policy/policy.json`, or its bundled copy; see [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html).

`if: always()` uploads the log even when the check exits 1, which is exactly when you want to read it.

## What the log contains[#](https://proctor.apps.wayne.edu/docs/guides/github-code-scanning.html#what-the-log-contains)

The uploaded `proctor.sarif` is a SARIF 2.1.0 log: one run, one rule per check, one result per non-pass finding.

- The driver block names the tool `proctor`, its version, and the repository's information URI.
- The rules list holds one entry per distinct rule id in the report, in first-seen order, each with a short description carrying the id.
- Each result holds `ruleId`, `level`, `message.text` and one location. The level maps `fail` to `error`, `warning` to `warning`, and any other non-pass status to `note`.
- The location uses the finding's path (backslashes normalized to forward slashes) and its line as `region.startLine`; a finding without a path points at the app's `composer.json`.
- Each result also carries `partialFingerprints` with the finding's fingerprint under `proctorFingerprint/v1`, the stable identity of the finding.

See [Formats](https://proctor.apps.wayne.edu/docs/guides/formats.html) for the shape of every format.

## Related[#](https://proctor.apps.wayne.edu/docs/guides/github-code-scanning.html#related)

- [Formats](https://proctor.apps.wayne.edu/docs/guides/formats.html)
- [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html)
- [CI checks](https://proctor.apps.wayne.edu/docs/guides/check-ci.html)
- [Rule ids](https://proctor.apps.wayne.edu/docs/reference/rule-ids.html)
