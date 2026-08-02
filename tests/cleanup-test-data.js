#!/usr/bin/env node
/*
 * Removes entities left behind by an interrupted Newman run.
 *
 * A completed run cleans up after itself: every brewer it creates is deleted by
 * the end of its folder, and the FK cascade takes the beers and locations that
 * hang off it. But the runs are sequential and dependent, so an abort part way
 * through -- --bail on a failed assertion, a dropped connection, Ctrl-C --
 * strands everything created up to that point. Against production that leaves
 * junk in /brewer, in search, and in Algolia.
 *
 * Every entity the collection creates is named with the TEST_PREFIX below, so
 * cleanup is an exact string match, never a heuristic. Nothing without that
 * prefix is ever touched.
 *
 * Two passes, because deleting the test brewers is not sufficient on its own:
 * a handful of tests reassign a test beer or location to a *real* brewer
 * (BEER-95, BEER-102, L-82, L-127 all move one to Ballast Point). Those escape
 * the cascade and have to be deleted by id. Rather than hardcode that brewer,
 * pass 2 reads the collection for literal UUIDs assigned to environment
 * variables and checks each of those brewers for prefixed children -- so it
 * stays correct if the reassignment targets change.
 *
 * Usage:
 *   node tests/cleanup-test-data.js tests/staging.env.json            # dry run
 *   node tests/cleanup-test-data.js tests/staging.env.json --apply    # delete
 */

const fs = require('fs');
const path = require('path');

const TEST_PREFIX = '[Postman API Test] ';
const PAGE_SIZE = 500;

const args = process.argv.slice(2);
const apply = args.includes('--apply');
const envPath = args.find((a) => !a.startsWith('--'));

if (!envPath) {
    console.error('Usage: node tests/cleanup-test-data.js <env.json> [--apply]');
    process.exit(2);
}

const env = {};
for (const v of JSON.parse(fs.readFileSync(envPath, 'utf8')).values || []) {
    env[v.key] = v.value;
}

const baseUrl = env.base_url;
const apiKey = env.api_key_admin;

if (!baseUrl || !apiKey) {
    console.error(`${envPath} is missing base_url or api_key_admin.`);
    process.exit(2);
}

const auth = 'Basic ' + Buffer.from(apiKey + ':').toString('base64');

async function api(method, endpoint) {
    const res = await fetch(`https://${baseUrl}${endpoint}`, {
        method,
        headers: { Authorization: auth, Accept: 'application/json' },
    });
    const text = await res.text();
    let body = null;
    try {
        body = JSON.parse(text);
    } catch {
        // Non-JSON response (an Apache error page, say) — surface it as-is.
    }
    return { status: res.status, body, text };
}

// Walks /brewer a page at a time. The catalog is ~6,800 brewers, so this is
// ~14 requests — cheap enough to prefer over /brewer/search, which depends on
// the FULLTEXT index being in sync and would silently miss a stranded row.
async function findTestBrewers() {
    const found = [];
    let cursor = Buffer.from('0').toString('base64');
    let scanned = 0;

    for (;;) {
        const { status, body, text } = await api(
            'GET',
            `/brewer?count=${PAGE_SIZE}&cursor=${encodeURIComponent(cursor)}`
        );
        if (status !== 200 || !body || !Array.isArray(body.data)) {
            throw new Error(`GET /brewer returned ${status}: ${text.slice(0, 200)}`);
        }

        scanned += body.data.length;
        for (const b of body.data) {
            if (typeof b.name === 'string' && b.name.startsWith(TEST_PREFIX)) {
                found.push(b);
            }
        }

        if (!body.has_more || !body.next_cursor) break;
        cursor = body.next_cursor;
    }

    return { found, scanned };
}

// Brewer ids the collection references as literal UUIDs — i.e. real catalog
// brewers it borrows rather than ones it creates. Test entities reassigned to
// these survive the cascade.
function reassignmentTargets() {
    const collection = path.join(__dirname, 'Catalog.beer.postman_collection.json');
    if (!fs.existsSync(collection)) return [];

    const raw = fs.readFileSync(collection, 'utf8');
    const ids = new Set();
    const re = /environment\.set\(\s*['"][^'"]*brewer[^'"]*['"]\s*,\s*['"]([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})['"]/gi;
    let m;
    while ((m = re.exec(raw)) !== null) ids.add(m[1].toLowerCase());
    return [...ids];
}

async function findStrandedChildren(brewerIDs) {
    const stranded = [];

    for (const id of brewerIDs) {
        for (const [endpoint, type] of [['beer', 'beer'], ['locations', 'location']]) {
            const { status, body } = await api('GET', `/brewer/${id}/${endpoint}`);
            if (status !== 200 || !body || !Array.isArray(body.data)) continue;

            for (const row of body.data) {
                if (typeof row.name === 'string' && row.name.startsWith(TEST_PREFIX)) {
                    stranded.push({ type, id: row.id, name: row.name, parent: id });
                }
            }
        }
    }

    return stranded;
}

(async () => {
    console.log(`Target:  ${baseUrl}`);
    console.log(`Mode:    ${apply ? 'APPLY (will delete)' : 'dry run'}\n`);

    const { found: brewers, scanned } = await findTestBrewers();
    console.log(`Scanned ${scanned} brewers; ${brewers.length} carry the test prefix.`);

    const targets = reassignmentTargets();
    const stranded = await findStrandedChildren(targets);
    console.log(
        `Checked ${targets.length} borrowed brewer(s) for reassigned test rows; found ${stranded.length}.\n`
    );

    if (!brewers.length && !stranded.length) {
        console.log('Nothing to clean up.');
        return;
    }

    for (const s of stranded) console.log(`  ${s.type.padEnd(8)} ${s.id}  ${s.name}`);
    for (const b of brewers) console.log(`  brewer   ${b.id}  ${b.name}`);

    if (!apply) {
        console.log('\nDry run — nothing deleted. Re-run with --apply to remove these.');
        return;
    }

    let deleted = 0;
    let failed = 0;

    // A successful DELETE is 204 with no body. Checking for 200 instead marked
    // every successful removal as FAILED and exited 1, which in turn tripped the
    // "Cleanup failed" warning in run-tests.sh on runs that had cleaned up fine.
    // 200 is still accepted in case an endpoint ever answers with a body.
    const deletedOK = (status, body) => status === 204 || (status === 200 && body && !body.error);

    // Strays first: they belong to brewers that are staying, so nothing else
    // will remove them.
    for (const s of stranded) {
        const { status, body } = await api('DELETE', `/${s.type}/${s.id}`);
        if (deletedOK(status, body)) {
            deleted++;
        } else {
            failed++;
            console.error(`  FAILED ${s.type} ${s.id} -> ${status} ${body?.error_msg || ''}`);
        }
    }

    // Then the test brewers; their own beers and locations cascade.
    for (const b of brewers) {
        const { status, body } = await api('DELETE', `/brewer/${b.id}`);
        if (deletedOK(status, body)) {
            deleted++;
        } else {
            failed++;
            console.error(`  FAILED brewer ${b.id} -> ${status} ${body?.error_msg || ''}`);
        }
    }

    console.log(`\nDeleted ${deleted}; ${failed} failed.`);
    if (failed) process.exit(1);
})().catch((err) => {
    console.error(err.message);
    process.exit(1);
});
