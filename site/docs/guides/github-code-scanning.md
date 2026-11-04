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

Add this workflow to the mirror. It sets up PHP, installs PROCTOR, runs the check against the app's CREED policy file, and uploads the resulting log:

```yaml
name: proctor
on: [push, pull_request]
permissions:
  security-events: write
jobs:
  proctor:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer require waynestate/proctor --dev
      # policy.json is the CREED policy file
      - run: vendor/bin/proctor check --format=sarif --policy=policy.json > proctor.sarif
      - uses: github/codeql-action/upload-sarif@9b3254085494b55a0f7c4c2b3d2d52cc712ce370 # v4.39.1
        with:
          sarif_file: proctor.sarif
```

The `--policy=policy.json` argument names the app's CREED policy file; see [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html) for how PROCTOR finds one when you do not name it.

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
