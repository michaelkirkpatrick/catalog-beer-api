#!/bin/sh
#
# Runs the Newman suite and then cleans up, whatever happened.
#
# The point is the `cleanup` step running unconditionally. The tests are
# sequential and dependent, and each folder deletes its own brewer at the end,
# so any early exit -- a failed assertion under --bail, a dropped connection,
# Ctrl-C, a `kill`, a closed terminal -- strands every entity created up to that
# point. Calling newman directly is fine; just know that a run that stops early
# leaves data behind unless you follow it with cleanup-test-data.js.
#
# What that cleanup reaches: brewers, beers and locations. All three carry the
# `[Postman API Test] ` name prefix, so cleanup-test-data.js finds them by name.
# The three test users it does not. /users has no list endpoint, so there is
# nothing to scan, and their ids live only in the running collection's memory --
# `User - End Requests` is the last folder, so an interrupted run essentially
# always strands them. Removing those means digging the ids out of the Newman
# output, or the users table, by hand.
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

# Interrupting the script must stop the run, not skip the cleanup below, so INT,
# TERM and HUP are all handled the same way. Newman has to run in the background
# for that to work: while the shell is waiting on a foreground child it defers
# traps until that child exits, so a `kill` would let the whole suite run to
# completion before anything was cleaned up. Backgrounding costs the free Ctrl-C
# behaviour -- a non-interactive shell sets asynchronous children to ignore
# SIGINT, so it no longer reaches newman through the process group -- which is
# why the handler signals it explicitly.
NEWMAN_PID=''
stop_newman() {
    if [ -n "$NEWMAN_PID" ]; then
        kill -TERM "$NEWMAN_PID" 2>/dev/null
    fi
    return 0
}
trap stop_newman INT TERM HUP

newman run "$COLLECTION" -e "$ENV_FILE" "$@" &
NEWMAN_PID=$!

# A trapped signal makes `wait` return early while the run is still going. Keep
# waiting until newman is really gone, or cleanup would race it and delete
# entities out from under requests still in flight.
while :; do
    wait "$NEWMAN_PID"
    STATUS=$?
    kill -0 "$NEWMAN_PID" 2>/dev/null || break
done

trap - INT TERM HUP

echo
echo "--- Cleaning up test data ---"
node "$DIR/cleanup-test-data.js" "$ENV_FILE" --apply || {
    echo "Cleanup failed. Re-run manually:" >&2
    echo "  node tests/cleanup-test-data.js $ENV_FILE --apply" >&2
}

exit $STATUS
