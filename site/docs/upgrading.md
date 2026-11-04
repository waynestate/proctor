---
title: "Upgrading | PROCTOR docs"
description: "How to upgrade PROCTOR to a newer release and note the changes that affect your runs."
url: https://proctor.apps.wayne.edu/docs/upgrading.html
llms: https://proctor.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Upgrading[#](https://proctor.apps.wayne.edu/docs/upgrading.html#upgrading)

PROCTOR is the invigilator, so upgrading it is a seat change, not a new exam: same hall, fresher pen. This page tells you how to move an app from one PROCTOR release to the next and which changes affect your runs. Start with the release that has a break, then work down to the additive ones.

## 0.4.0[#](https://proctor.apps.wayne.edu/docs/upgrading.html#0-4-0)

Released 2026-11-03. This is a pre-1.0 minor: consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. The new checks warn and never fail.

### Breaking[#](https://proctor.apps.wayne.edu/docs/upgrading.html#breaking)

- Consumers that pin `^0.3` stay on 0.3.x until they move their constraint to `^0.4`. That constraint move is the only break in this release.

### Deprecated[#](https://proctor.apps.wayne.edu/docs/upgrading.html#deprecated)

- None recorded for this release.

### Steps[#](https://proctor.apps.wayne.edu/docs/upgrading.html#steps)

1. Move the PROCTOR constraint in your composer.json from `^0.3` to `^0.4`.
2. Apply it with the install command from the README:
   ```bash
   composer require waynestate/proctor --dev
   ```
3. Re-run the check so the new checks take part:
   ```bash
   vendor/bin/proctor check
   ```

### How to verify[#](https://proctor.apps.wayne.edu/docs/upgrading.html#how-to-verify)

- Run `vendor/bin/proctor check --format=json` and confirm the `version` field reads `0.4.0`.
- Confirm the checks added in this release appear in the report. They warn and never fail, so the exit code of a run that passed before should not change:
  - `composer-audit` reports the count of audit ignore entries (`proctor/composer-audit/ignore-entries`).
  - `overrides` warns when an active entry matches no failure (`proctor/overrides/unused`).
  - `unbounded-constraints` warns on a repository URL that is plain http (`proctor/unbounded-constraints/insecure-url`) and on a `minimum-stability` below stable (`proctor/unbounded-constraints/minimum-stability`).
  - `preflight-pinned` warns when a Preflight component is included at a ref that is not a commit SHA, or when an app overrides the script of an included Preflight job.
  - `lock-committed` warns when the composer.lock content-hash differs from composer.json.

The other released versions (0.1.0 through 0.3.1) record no break in the changelog; they add checks and fixes, and the upgrade command is the same for every move:

```bash
composer require waynestate/proctor --dev
```

## Related[#](https://proctor.apps.wayne.edu/docs/upgrading.html#related)

- [Install](https://proctor.apps.wayne.edu/docs/install.html)
- [Quick start](https://proctor.apps.wayne.edu/docs/guides/quick-start.html)
- [Checks](https://proctor.apps.wayne.edu/docs/guides/checks-overview.html)
- [Policy](https://proctor.apps.wayne.edu/docs/guides/policy.html)
- [Exit codes](https://proctor.apps.wayne.edu/docs/reference/exit-codes.html)
- [Troubleshooting](https://proctor.apps.wayne.edu/docs/troubleshooting.html)
