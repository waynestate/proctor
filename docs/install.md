---
title: Install
description: Install PROCTOR as a Composer dev dependency so the command is available as vendor/bin/proctor.
---

# Install

## Requirements

- PHP `^8.3`. composer.json pins the platform to `8.3.0`, so the package resolves against 8.3 and runs on anything newer.
- No PHP extensions. composer.json declares no `ext-*` requirement; the runtime libraries (`symfony/console`, `symfony/yaml`, `composer/semver`, `nikic/php-parser`) arrive as Composer packages.
- Composer, for the main install path below. The single-file PHAR route needs Docker instead, and no PHP or Composer on the host.

Where the code lives: the canonical repository is on nougat, [enterprise-apps/proctor](https://nougat.snu.gs/enterprise-apps/proctor). git.wayne.edu and GitHub ([waynestate/proctor](https://github.com/waynestate/proctor)) are read-only mirrors of it. Open issues and merge requests on nougat, not on the mirrors. Your proctor keeps one answer sheet, and this is it; the mirrors hold photocopies.

### Single-file download

PROCTOR can also be built as a single-file PHAR, so Preflight and apps without Composer access can run it as one file. Build it with the script for your OS:

```powershell tab="Windows (PowerShell)"
.\scripts\build-phar.ps1
```

```sh tab="Linux / macOS"
scripts/build-phar.sh
```

Each script writes `build/proctor.phar` and verifies it by printing PROCTOR's version. The phar is not published yet (there is no release step), so build it from a checkout rather than downloading it.

## Install

```
composer require waynestate/proctor --dev
```

The command is then `vendor/bin/proctor`.

## Verify

Run the check against the app:

```
vendor/bin/proctor check
```

Expected: the text report. A header line with the tool and the versions of the external tools used (composer), a line naming the policy in use (origin, path, refresh date), one line per finding, one line per override, then a RESULT line. Exit codes: 0 for pass (warnings allowed), 1 for one or more failures, 2 for a tool error. A proctor that prints a mark sheet is a proctor that is on duty.

## Next steps

- [Quick start](guides/quick-start.md): run the first check and read the report.
- [Reference: commands](reference/commands.md): every command and option, with the [rule ids](reference/rule-ids.md) and [exit codes](reference/exit-codes.md) alongside.
