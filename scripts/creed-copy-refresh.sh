#!/bin/sh
# creed-copy-refresh.sh: the weekly creed-copy-refresh job in .gitlab-ci.yml runs this.
#
# It clones CREED main with the job token, re-exports the generated CREED reader
# copy in src/Creed, rebuilds what the copy feeds (the docs site and the agent-ready
# files), proves it with composer creed-copy:check and the full composer ci, and only
# then commits and pushes to main with the job token. Any red step stops the script
# before the commit, so a red run leaves main untouched.
#
# It runs only inside GitLab CI (Linux, the composer image), never on a developer
# machine. To refresh by hand, follow MAINTAINING.md. The proctor grades its own
# copy of the rulebook before it hands it back.
set -eu

die() {
    echo "creed-copy-refresh: $1" >&2
    exit 1
}

# The job sets all of these. Only the names are tested here, never printed.
[ -n "${CI_JOB_TOKEN:-}" ] || die "the job token is missing; this script runs only in the creed-copy-refresh CI job"
[ -n "${CI_SERVER_HOST:-}" ] || die "CI_SERVER_HOST is not set; this script runs only in GitLab CI"
[ -n "${CI_PROJECT_PATH:-}" ] || die "CI_PROJECT_PATH is not set; this script runs only in GitLab CI"
[ -n "${CI_DEFAULT_BRANCH:-}" ] || die "CI_DEFAULT_BRANCH is not set; this script runs only in GitLab CI"

TMP="$(mktemp -d)"
# The only rm -rf in this script, and it only ever points at $TMP.
trap 'rm -rf "$TMP"' EXIT

# Show what git said with any credential in a URL masked. GitLab masks the job
# token in the log as well; this is the belt to go with those braces.
show_masked() {
    sed 's#gitlab-ci-token:[^@]*@#gitlab-ci-token:[MASKED]@#g' "$1" >&2
}

# 1. Dependencies, for the builders and composer ci.
composer install --no-interaction --no-progress --prefer-dist

# 2. CREED main, cloned with the job token (PROCTOR is on CREED's job-token allowlist).
if ! git clone -q "https://gitlab-ci-token:${CI_JOB_TOKEN}@${CI_SERVER_HOST}/enterprise-apps/creed.git" "$TMP/creed" 2>"$TMP/clone.log"; then
    show_masked "$TMP/clone.log"
    die "could not clone CREED with the job token"
fi
VERSION="$(git -C "$TMP/creed" describe --tags --always)"
echo "CREED main is $VERSION"

# 3. Export, the same command a person runs by hand.
php "$TMP/creed/bin/export.php" --namespace="Waynestate\Proctor\Creed" --to=src/Creed

# 4. Nothing new: stop here, green. The export stamps src/Creed/manifest.json with
# CREED's git describe, so a CREED commit that changes nothing in the copy still
# moves that one line. creed-copy:check ignores the stamp, and so does this job:
# a stamp-only change is put back rather than pushed as a weekly no-op commit.
CHANGED="$(git status --porcelain -- src/Creed)"
if [ "$CHANGED" = " M src/Creed/manifest.json" ] \
    && [ -z "$(git diff -U0 -- src/Creed/manifest.json | grep '^[-+]' | grep -v -e '^--- ' -e '^+++ ' -e '"creed_version"')" ]; then
    echo "only the creed_version stamp in src/Creed/manifest.json moved; keeping the committed copy"
    git checkout -- src/Creed/manifest.json
    CHANGED=""
fi
if [ -z "$CHANGED" ]; then
    echo "CREED copy already matches CREED $VERSION; nothing to do"
    exit 0
fi

# 5. Rebuild what the copy feeds, with the commands composer ci checks for freshness
# (composer docs and composer site:agent-ready).
php bin/docs-site.php
php bin/agent-ready.php

# 6. Prove it. set -eu stops the script on the first red, before any commit or push.
CREED_PATH="$TMP/creed" composer creed-copy:check
# The handbook id test reads CREED through CREED_PATH, so it needs the clone.
CREED_PATH="$TMP/creed" composer ci

# 7. Commit exactly what this job wrote, by name, never git add -A.
git add -- src/Creed site/index.md site/llms.txt site/llms-full.txt site/robots.txt site/sitemap.xml site/docs docs/reference/commands.md docs/reference/configuration.md
LEFTOVER="$(git diff --name-only; git ls-files --others --exclude-standard)"
if [ -n "$LEFTOVER" ]; then
    echo "$LEFTOVER" >&2
    die "the refresh changed files outside the staged list (above); nothing was committed or pushed"
fi
git -c user.name="CREED copy bot" -c user.email=creed-copy-bot@noreply.invalid commit -q -m "Refresh the generated CREED copy from CREED $VERSION (weekly job)"

# No force, ever: if main moved since this pipeline started, the push is rejected,
# main stays as it is, and next week's run tries again from the new main.
if ! git push -q "https://gitlab-ci-token:${CI_JOB_TOKEN}@${CI_SERVER_HOST}/${CI_PROJECT_PATH}.git" "HEAD:refs/heads/${CI_DEFAULT_BRANCH}" 2>"$TMP/push.log"; then
    show_masked "$TMP/push.log"
    die "the push to ${CI_DEFAULT_BRANCH} was rejected (main probably moved); main is untouched and next week's run tries again"
fi
echo "pushed the CREED $VERSION copy to ${CI_DEFAULT_BRANCH}"
