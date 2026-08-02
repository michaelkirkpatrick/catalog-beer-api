#!/bin/sh
#
# Runs the Newman suite and then cleans up, whatever happened.
#
# The point is the `cleanup` step running unconditionally. The tests are
# sequential and dependent, and each folder deletes its own brewer at the end,
# so any early exit -- a failed assertion under --bail, a dropped connection,
# Ctrl-C -- strands every entity created up to that point. Calling newman
# directly is fine; just know that a run that stops early leaves data behind
# unless you follow it with cleanup-test-data.js.
#
# Usage:
#   tests/run-tests.sh tests/staging.env.json
#   tests/run-tests.sh tests/staging.env.json --bail
#
# Exits with Newman's status, so CI still sees a failed suite as a failure.

set -u

ENV_FILE="${1:-}"
if [ -z "$ENV_FILE" ]; then
    echo "Usage: tests/run-tests.sh <env.json> [newman args...]" >&2
    exit 2
fi
shift

DIR=$(cd "$(dirname "$0")" && pwd)
COLLECTION="$DIR/Catalog.beer.postman_collection.json"

# Ctrl-C should still reach the cleanup below rather than killing the script.
trap '' INT

newman run "$COLLECTION" -e "$ENV_FILE" "$@"
STATUS=$?

trap - INT

echo
echo "--- Cleaning up test data ---"
node "$DIR/cleanup-test-data.js" "$ENV_FILE" --apply || {
    echo "Cleanup failed. Re-run manually:" >&2
    echo "  node tests/cleanup-test-data.js $ENV_FILE --apply" >&2
}

exit $STATUS
