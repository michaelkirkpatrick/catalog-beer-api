<?php
/*--
Regression test for Post.class.php — the /news posts and the API changelog.
Needs a LOCAL MySQL (it creates and drops a scratch database named
cb_post_test); no network, no secrets file, no Apache.

    php tests/post.php [path/to/catalog-beer-schema.sql]

The schema path defaults to ../catalog-beer-mysql/catalog-beer-schema.sql.
Connection defaults to root@127.0.0.1 with no password (Homebrew MySQL);
override with CB_TEST_DB_HOST / CB_TEST_DB_USER / CB_TEST_DB_PASSWORD.

What it proves: only an admin key writes; reads are open but a draft is
invisible to a non-admin by id, by slug and in the list; the channel rules
(version and breaking are api-only, version required there) hold; slugs
default to v{version} and to the title; publishing stamps published_at and a
published post cannot lose it; slug and version conflicts are 409s that name
the field; the list orders newest first, filters by channel and paginates;
PATCH keeps absent fields and clears summary on null; DELETE is a 204.
--*/
if(php_sapi_name() !== 'cli'){
    exit(1);
}

define('ROOT', dirname(__DIR__) . '/public_html');
define('ENVIRONMENT', 'staging');
define('DB_HOST', getenv('CB_TEST_DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('CB_TEST_DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('CB_TEST_DB_PASSWORD') ?: '');
define('DB_NAME', 'cb_post_test');
// The site reads with a master key whose account is an admin (POST /login
// needs one). It must still never see a draft.
define('MASTER_API_KEYS', serialize(array('bbbbbbbb-0000-4000-8000-000000000003')));
date_default_timezone_set('America/Los_Angeles');
spl_autoload_register(function ($c) { require_once ROOT . '/classes/' . $c . '.class.php'; });

$schemaPath = $argv[1] ?? dirname(__DIR__, 2) . '/catalog-beer-mysql/catalog-beer-schema.sql';
if(!is_readable($schemaPath)){
    echo "Schema not found: $schemaPath\n";
    exit(1);
}

// ----- Scratch database: create, load schema, load fixtures -----
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
$admin->query("DROP DATABASE IF EXISTS " . DB_NAME);
$admin->query("CREATE DATABASE " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$admin->select_db(DB_NAME);
$sql = "SET FOREIGN_KEY_CHECKS=0;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;\n" . <<<'SQL'
INSERT INTO users (id,email,passwordHash,name,emailVerified,admin) VALUES
 ('aaaaaaaa-0000-4000-8000-000000000001','editor@example.com','x','Editor',1,1),
 ('aaaaaaaa-0000-4000-8000-000000000002','plain@example.com','x','Plain User',1,0),
 ('aaaaaaaa-0000-4000-8000-000000000003','site@example.com','x','Site (master key)',1,1);
INSERT INTO api_keys (id,userID) VALUES
 ('bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001'),
 ('bbbbbbbb-0000-4000-8000-000000000002','aaaaaaaa-0000-4000-8000-000000000002'),
 ('bbbbbbbb-0000-4000-8000-000000000003','aaaaaaaa-0000-4000-8000-000000000003');
SQL;
$admin->multi_query($sql);
do { if($r = $admin->store_result()){ $r->free(); } } while($admin->more_results() && $admin->next_result());
echo "scratch database " . DB_NAME . " loaded\n";

// ----- Scenario -----
$ADMIN = 'bbbbbbbb-0000-4000-8000-000000000001'; $PLAIN = 'bbbbbbbb-0000-4000-8000-000000000002'; $MASTER = 'bbbbbbbb-0000-4000-8000-000000000003';
function call($method, $function, $id, $key, $data = null, $get = []) {
    $_GET = $get; $p = new Post(); $p->api($method, $function, $id, $key, $get['count'] ?? 500, $get['cursor'] ?? base64_encode('0'), $data ?? new stdClass());
    return [$p->responseCode, $p->json, $p->responseHeader];
}
function check($label, $ok) { echo ($ok ? "PASS" : "FAIL") . "  $label\n"; if(!$ok){ $GLOBALS['fails']++; } }
$fails = 0;

// 1. writes are admin-only
[$code, $j] = call('POST', '', '', $PLAIN, (object)['channel' => 'news', 'title' => 'Hi', 'body' => '<p>x</p>']);
check("non-admin POST -> 401", $code === 401 && $j['error'] === true);
[$code, $j] = call('POST', '', '', 'not-a-key', (object)['channel' => 'news', 'title' => 'Hi', 'body' => '<p>x</p>']);
check("unknown key POST -> 401", $code === 401);

// 2. validation
[$code, $j] = call('POST', '', '', $ADMIN, (object)['title' => 'Hi', 'body' => 'x']);
check("missing channel -> 400", $code === 400 && isset($j['validation']['channel']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news']);
check("missing title and body -> 400 naming both", $code === 400 && isset($j['validation']['title']) && isset($j['validation']['body']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news', 'title' => 'Hi', 'body' => 'x', 'version' => '1.0.0']);
check("version on news -> 400", $code === 400 && isset($j['validation']['version']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news', 'title' => 'Hi', 'body' => 'x', 'breaking' => true]);
check("breaking on news -> 400", $code === 400 && isset($j['validation']['breaking']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'title' => 'Hi', 'body' => 'x']);
check("api without version -> 400", $code === 400 && isset($j['validation']['version']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'title' => 'Hi', 'body' => 'x', 'version' => 'five']);
check("malformed version -> 400", $code === 400 && isset($j['validation']['version']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news', 'title' => "Line\nbreak", 'body' => 'x']);
check("newline in title -> 400", $code === 400 && isset($j['validation']['title']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news', 'title' => 'Hi', 'body' => 'x', 'slug' => 'Bad Slug!']);
check("bad slug -> 400", $code === 400 && isset($j['validation']['slug']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news', 'title' => 'Hi', 'body' => 'x', 'published_at' => '2026-10-01']);
check("string published_at -> 400", $code === 400 && isset($j['validation']['published_at']));

// 3. create a published release with a back-dated published_at
$t540 = 1791932931; // 2026-10-13 16:08 PDT
[$code, $j, $hdr] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'version' => 'v5.4.0', 'title' => 'Verified records are editable by everyone',
    'summary' => 'cb_verified is a badge, not a lock.', 'body' => "<ul>\n\t<li>Changed</li>\n</ul>", 'status' => 'published', 'published_at' => $t540]);
check("api release -> 201", $code === 201);
check("leading v dropped from version, kept in slug", $j['version'] === '5.4.0' && $j['slug'] === 'v5.4.0');
check("url and Location point at /changelog/v5.4.0", $j['url'] === 'https://staging.catalog.beer/changelog/v5.4.0' && $hdr === 'Location: https://staging.catalog.beer/changelog/v5.4.0');
check("back-dated published_at kept; tab in body accepted", $j['published_at'] === $t540 && $j['status'] === 'published' && str_contains($j['body'], "\t"));
check("breaking defaults false", $j['breaking'] === false);
$ID540 = $j['id'];

[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'version' => '5.3.2', 'title' => 'Long descriptions stay searchable', 'body' => 'x', 'status' => 'published', 'published_at' => $t540 - 600000, 'breaking' => false]);
$ID532 = $j['id'];
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'version' => '4.0.0', 'title' => 'API keys are never echoed back', 'body' => 'x', 'status' => 'published', 'published_at' => $t540 - 3000000, 'breaking' => true]);
check("breaking release -> 201 with breaking true", $code === 201 && $j['breaking'] === true);

// 4. conflicts
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'version' => '5.4.0', 'slug' => 'other', 'title' => 'Dup', 'body' => 'x']);
check("duplicate version -> 409 naming version", $code === 409 && isset($j['validation']['version']) && !isset($j['validation']['slug']));
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'version' => '9.9.9', 'slug' => 'v5.4.0', 'title' => 'Dup', 'body' => 'x']);
check("duplicate slug -> 409 naming slug", $code === 409 && isset($j['validation']['slug']));

// 5. a news draft: slug from title, publishedAt null, invisible to non-admins
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'news', 'title' => "Brewers' Status & Years", 'body' => '<p>Soon.</p>']);
check("news draft -> 201, slug from title, no published_at", $code === 201 && $j['slug'] === 'brewers-status-years' && $j['status'] === 'draft' && $j['published_at'] === null);
$DRAFT = $j['id'];
[$code, $j] = call('POST', '', '', $ADMIN, (object)['channel' => 'api', 'version' => '9.9.9', 'slug' => 'brewers-status-years', 'title' => 'Same slug, other channel', 'body' => 'x']);
check("same slug in another channel -> 201", $code === 201);
$ID999 = $j['id'];
[$code, $j] = call('GET', '', $DRAFT, $PLAIN);
check("draft by id, non-admin -> 404", $code === 404);
[$code, $j] = call('GET', 'channel', '', $PLAIN, null, ['channel' => 'news', 'slug' => 'brewers-status-years']);
check("draft by slug, non-admin -> 404", $code === 404);
[$code, $j] = call('GET', '', $DRAFT, $ADMIN);
check("draft by id, admin -> 200", $code === 200 && $j['title'] === "Brewers' Status & Years");
[$code, $j] = call('GET', '', '', $PLAIN, null, ['status' => 'draft']);
check("non-admin listing drafts -> 403", $code === 403);
[$code, $j] = call('GET', 'channel', '', $MASTER, null, ['channel' => 'news', 'slug' => 'brewers-status-years']);
check("draft by slug, master key of an admin account -> 404", $code === 404);
[$code, $j] = call('GET', '', $DRAFT, $MASTER);
check("draft by id, master key -> 404", $code === 404);
[$code, $j] = call('GET', '', '', $MASTER, null, ['status' => 'all']);
check("master key listing drafts -> 403", $code === 403);
[$code, $j] = call('POST', '', '', $MASTER, (object)['channel' => 'news', 'title' => 'Hi', 'body' => 'x']);
check("master key cannot write -> 401", $code === 401);

// 6. list: published only, newest first, channel filter, pagination
[$code, $j] = call('GET', '', '', $PLAIN);
check("list -> 3 published posts, newest first", $code === 200 && count($j['data']) === 3 && $j['data'][0]['version'] === '5.4.0' && $j['data'][2]['version'] === '4.0.0');
[$code, $j] = call('GET', '', '', $PLAIN, null, ['channel' => 'news']);
check("news channel has no published posts yet", $code === 200 && count($j['data']) === 0 && $j['has_more'] === false);
[$code, $j] = call('GET', '', '', $PLAIN, null, ['channel' => 'beer']);
check("unknown channel -> 400", $code === 400);
[$code, $j] = call('GET', '', '', $PLAIN, null, ['channel' => 'api', 'count' => 2]);
check("count=2 -> has_more with next_cursor", count($j['data']) === 2 && $j['has_more'] === true && isset($j['next_cursor']));
[$code, $j] = call('GET', '', '', $PLAIN, null, ['channel' => 'api', 'count' => 2, 'cursor' => $j['next_cursor']]);
check("second page -> the last release", count($j['data']) === 1 && $j['data'][0]['version'] === '4.0.0' && $j['has_more'] === false);
[$code, $j] = call('GET', '', '', $ADMIN, null, ['status' => 'all']);
check("admin status=all -> 5 posts", $code === 200 && count($j['data']) === 5);

// 7. by slug
[$code, $j] = call('GET', 'channel', '', $PLAIN, null, ['channel' => 'api', 'slug' => 'v5.4.0']);
check("GET /post/api/v5.4.0 -> 200", $code === 200 && $j['id'] === $ID540);

// 8. PATCH
[$code, $j] = call('PATCH', '', $ID540, $PLAIN, (object)['title' => 'x']);
check("non-admin PATCH -> 401", $code === 401);
[$code, $j] = call('PATCH', '', $ID540, $ADMIN, (object)['summary' => null, 'breaking' => true]);
check("PATCH clears summary, keeps title/body/published_at", $code === 200 && $j['summary'] === null && $j['breaking'] === true && $j['title'] === 'Verified records are editable by everyone' && $j['published_at'] === $t540);
[$code, $j] = call('PATCH', '', $ID540, $ADMIN, (object)['published_at' => null]);
check("published post cannot lose published_at -> 400", $code === 400 && isset($j['validation']['published_at']));
[$code, $j] = call('PATCH', '', $ID540, $ADMIN, (object)['channel' => 'news']);
check("channel is fixed -> 400", $code === 400 && isset($j['validation']['channel']));
[$code, $j] = call('PATCH', '', $ID532, $ADMIN, (object)['version' => '5.4.0']);
check("PATCH onto a taken version -> 409", $code === 409 && isset($j['validation']['version']));
$before = time();
[$code, $j] = call('PATCH', '', $DRAFT, $ADMIN, (object)['status' => 'published']);
check("publishing a draft stamps published_at now", $code === 200 && $j['status'] === 'published' && $j['published_at'] >= $before);
[$code, $j] = call('GET', '', '', $PLAIN, null, ['channel' => 'news']);
check("published news post now lists", count($j['data']) === 1 && $j['data'][0]['url'] === 'https://staging.catalog.beer/news/brewers-status-years');
[$code, $j] = call('PATCH', '', 'dddddddd-0000-4000-8000-000000000000', $ADMIN, (object)['title' => 'x']);
check("PATCH unknown id -> 404", $code === 404);

// 9. DELETE
[$code, $j] = call('DELETE', '', $ID999, $PLAIN);
check("non-admin DELETE -> 401", $code === 401);
[$code, $j] = call('DELETE', '', $ID999, $ADMIN);
check("admin DELETE -> 204", $code === 204);
[$code, $j] = call('GET', '', $ID999, $ADMIN);
check("deleted post -> 404", $code === 404);

// 10. method and path guards
[$code, $j, $hdr] = call('PUT', '', $ID540, $ADMIN);
check("PUT -> 405 with Allow", $code === 405 && str_starts_with($hdr, 'Allow:'));
[$code, $j] = call('GET', 'channel', '', $PLAIN, null, ['channel' => 'beer', 'slug' => 'x']);
check("unknown channel in path -> 404", $code === 404);

// 11. deleting the author keeps the posts
$admin->query("DELETE FROM users WHERE id='aaaaaaaa-0000-4000-8000-000000000001'");
$left = $admin->query("SELECT COUNT(*) AS n, SUM(authorUserID IS NULL) AS orphaned FROM post")->fetch_assoc();
check("author delete keeps every post, author nulled", intval($left['n']) === 4 && intval($left['orphaned']) === 4);

$admin->query("DROP DATABASE " . DB_NAME);
echo $fails === 0 ? "\nall checks passed\n" : "\n$fails check(s) FAILED\n";
exit($fails === 0 ? 0 : 1);
