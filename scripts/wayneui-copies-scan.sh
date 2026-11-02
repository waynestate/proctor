#!/bin/sh
# The wayneui-copies job needs the project root so PROCTOR can see
# site/assets/wayne-ui/ and the root composer.json. The test fixtures
# hold destructive deploy scripts on purpose, so a scan of the real root
# would fail on those planted findings. This script gives the proctor a
# clean exam room: it stages a copy of the root without tests/fixtures
# and runs PROCTOR on that copy.
set -eu

PROCTOR_BIN="${PROCTOR_BIN:-deploy/vendor/bin/proctor}"
# The default policy is the copy CREED installs under deploy/. The CI job
# installs it before this script runs; a local run may have only the root
# vendor/, in which case PROCTOR falls back to its bundled CREED policy.
POLICY_IS_DEFAULT=1
if [ -z "${POLICY:-}" ]; then
    POLICY="deploy/vendor/waynestate/creed/policy/policy.json"
else
    POLICY_IS_DEFAULT=0
fi

ROOT="$(pwd)"
case "$POLICY" in
    /*) ;;
    *) POLICY="$ROOT/$POLICY" ;;
esac

# Pass --policy only when the file is here. An explicit POLICY that is
# missing stays an error; the implicit default may fall back to the copy
# bundled with PROCTOR.
POLICY_ARG=""
if [ -f "$POLICY" ]; then
    POLICY_ARG="--policy=$POLICY"
elif [ "$POLICY_IS_DEFAULT" -ne 1 ]; then
    echo "wayneui-copies-scan: policy file not found: $POLICY" >&2
    exit 2
fi

set -f
set -- $PROCTOR_BIN
set +f
ABS_PROCTOR_BIN=""
for ARG in "$@"; do
    case "$ARG" in
        /*) ;;
        -*) ;;
        */*) ARG="$ROOT/$ARG" ;;
    esac
    ABS_PROCTOR_BIN="$ABS_PROCTOR_BIN $ARG"
done
PROCTOR_BIN="${ABS_PROCTOR_BIN# }"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

tar --exclude=./.git --exclude=./vendor --exclude=./deploy/vendor --exclude=./node_modules --exclude=./tests/fixtures -cf - . | tar -xf - -C "$STAGE"

# shellcheck disable=SC2086
status=0
$PROCTOR_BIN check --path="$STAGE" $POLICY_ARG || status=$?
exit "$status"
