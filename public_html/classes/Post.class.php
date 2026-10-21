<?php
/*--
Posts: the frontend's /news announcements and the API changelog at /changelog.

  GET    /post?channel=&count=&cursor=   published posts, newest first; any key
  GET    /post?status=draft|all          admin only: drafts for the editor
  GET    /post/{id}                      one post; a draft is 404 unless admin
                                         AND ?status=all (or draft) is sent
  GET    /post/{channel}/{slug}          the same, by the URL the site shows
  POST   /post                           admin only
  PATCH  /post/{id}                      admin only; partial update
  DELETE /post/{id}                      admin only; 204

Two channels. `news` is prose for everyone; `api` is one row per
catalog-beer-api release and the only channel that carries a `version` or a
`breaking` flag. The table is described in
catalog-beer-mysql/migrations/2026-10-17-post.sql (authorUserID dropped
2026-10-21: any admin edits any post, so the column answered nothing).

`body` is Markdown written by an admin; the frontend renders it with
Parsedown, raw HTML inside it allowed. That is safe for the same reason
Alert::$msg is on the frontend: only an admin key can write it, so whoever
writes a post could already write raw HTML into the codebase. The API stores
and returns the Markdown as written. Everything else here is plain text and
is escaped on output.

Drafts are opt-in by request, never by key alone: an admin sees them only
when the request says ?status=all or ?status=draft. The website reads every
public page with the master key, whose account is an admin, and never sends
status=, so a draft cannot reach a public page however its slug is guessed.

`published_at` is the date a reader sees, not the date the row was written,
so a release can be back-dated to the day it was pushed. Publishing without
one stamps the current time; a published row can never lose it.
--*/

class Post {

    const CHANNELS = array('news', 'api');
    const STATUSES = array('draft', 'published');
    const LIST_DEFAULT = 20;
    const LIST_MAX = 100;
    const TITLE_MAX = 200;
    const SUMMARY_MAX = 500;
    const SLUG_MAX = 120;
    const VERSION_MAX = 20;
    const BODY_MAX = 200000;

    // Validation
    public $error = false;
    public $errorMsg = null;

    // API Response
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    // Set by checkAdmin()
    private $isAdmin = false;

    const COLUMNS = "id, channel, slug, title, summary, body, version, breaking+0 AS breaking, status, publishedAt, createdAt, lastModified";

    // ----- Authorisation -----
    // Reads are open to any key, so being a non-admin is not an error here --
    // it only decides whether drafts can be asked for. Writes call
    // requireAdmin(). An admin is any key whose account carries the admin
    // flag, master keys included: until 2026-10-21 the master key was
    // excluded to keep drafts off the public pages, which locked the
    // master-key account out of its own editor. showDrafts() carries that
    // protection now.
    private function checkAdmin($apiKey){
        $apiKeys = new apiKeys();
        if($apiKeys->validate($apiKey, true)){
            $users = new Users();
            if($users->validate($apiKeys->userID, true) && $users->admin){
                $this->isAdmin = true;
            }
        }
    }

    // Drafts are shown only to an admin who asked for them with ?status=all
    // or ?status=draft. A read without status= is a public read whatever the
    // key, so the website's master-key pages can never render one.
    private function showDrafts(){
        return $this->isAdmin && in_array($_GET['status'] ?? 'published', array('draft', 'all'), true);
    }

    private function requireAdmin($apiKey, $context){
        if($this->isAdmin){
            return true;
        }
        $this->error = true;
        $this->errorMsg = 'Unauthorized: You must be an admin to write posts.';
        $this->responseCode = 401;

        $errorLog = new LogError();
        $errorLog->errorNumber = 350;
        $errorLog->errorMsg = "Unauthorized: Not admin ($context)";
        $errorLog->badData = "apiKey: $apiKey";
        $errorLog->filename = 'Post.class.php';
        $errorLog->write();
        return false;
    }

    private function dbError($db, $context){
        $this->error = true;
        $this->errorMsg = $db->errorMsg;
        $this->responseCode = $db->responseCode;

        $errorLog = new LogError();
        $errorLog->errorNumber = 351;
        $errorLog->errorMsg = "Database error ($context)";
        $errorLog->badData = $db->errorMsg;
        $errorLog->filename = 'Post.class.php';
        $errorLog->write();
    }

    private function validationError($messages, $context, $code = 400){
        $this->error = true;
        $this->errorMsg = implode(' ', array_values($messages));
        $this->responseCode = $code;
        $this->json['validation'] = $messages;

        $errorLog = new LogError();
        $errorLog->errorNumber = 352;
        $errorLog->errorMsg = "Validation error ($context)";
        $errorLog->badData = json_encode($messages);
        $errorLog->filename = 'Post.class.php';
        $errorLog->write();
    }

    private function notFound($badData, $context){
        $this->error = true;
        $this->errorMsg = 'Post not found.';
        $this->responseCode = 404;

        $errorLog = new LogError();
        $errorLog->errorNumber = 353;
        $errorLog->errorMsg = "Post not found ($context)";
        $errorLog->badData = $badData;
        $errorLog->filename = 'Post.class.php';
        $errorLog->write();
    }

    // ----- Row -> JSON -----
    // `url` is the page on the site, so a feed reader or an agent can link a
    // post without knowing the frontend's routes.
    private function postObject($row){
        $site = ENVIRONMENT == 'staging' ? 'https://staging.catalog.beer' : 'https://catalog.beer';
        $path = $row['channel'] === 'api' ? '/changelog/' : '/news/';
        return array(
            'id' => $row['id'],
            'object' => 'post',
            'channel' => $row['channel'],
            'slug' => $row['slug'],
            'url' => $site . $path . $row['slug'],
            'title' => $row['title'],
            'summary' => $row['summary'],
            'body' => $row['body'],
            'version' => $row['version'],
            'breaking' => intval($row['breaking']) === 1,
            'status' => $row['status'],
            'published_at' => is_null($row['publishedAt']) ? null : intval($row['publishedAt']),
            'created_at' => intval($row['createdAt']),
            'last_modified' => intval($row['lastModified'])
        );
    }

    // ----- Field checks -----
    // Each returns the cleaned value, or null with a message added. $present
    // distinguishes "absent" from "sent as null" for PATCH.
    private function text($data, $field, $max, $required, &$messages, $multiLine = false){
        $value = isset($data->$field) && !is_null($data->$field) ? TextInput::trim(strval($data->$field)) : '';
        if($value === ''){
            if($required){
                $messages[$field] = "$field is required.";
            }
            return null;
        }
        // A body is HTML and may hold an indented <pre> block, so a tab is a
        // character people type there; TextInput refuses tabs everywhere else.
        $msg = TextInput::check($multiLine ? str_replace("\t", ' ', $value) : $value, $multiLine);
        if($msg !== ''){
            $messages[$field] = $msg;
            return null;
        }
        if(mb_strlen($value) > $max){
            $messages[$field] = "$field must be " . number_format($max) . " characters or fewer (" . number_format(mb_strlen($value)) . " sent).";
            return null;
        }
        return $value;
    }

    // Lowercase letters, digits, hyphens and dots, starting and ending with a
    // letter or digit. Dots are allowed so a release slug reads v5.4.0.
    private static function validSlug($slug){
        return strlen($slug) <= self::SLUG_MAX && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $slug) === 1;
    }

    public static function slugify($title){
        $slug = strtolower($title);
        $slug = preg_replace('/[\'\x{2019}]/u', '', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(substr(trim($slug, '-'), 0, 80), '-');
        return $slug;
    }

    // 5.4.0, 2.1, 2.0-beta.1. A leading v is accepted and dropped, so the
    // stored value is the version, and the slug is where the v lives.
    private static function normalizeVersion($version){
        $version = preg_replace('/^v/i', '', $version);
        if(strlen($version) <= self::VERSION_MAX && preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[0-9A-Za-z.]+)?$/', $version) === 1){
            return $version;
        }
        return null;
    }

    // ----- GET /post/{id}, GET /post/{channel}/{slug} -----
    // $includeDrafts is for the writes: PATCH, DELETE and the read-back after
    // a save already passed requireAdmin() and must find a draft whatever the
    // query string says. A GET request never sets it.
    public function get($postID, $channel = null, $slug = null, $includeDrafts = false){
        $db = new Database();
        if(is_null($channel)){
            $result = $db->query("SELECT " . self::COLUMNS . " FROM post WHERE id=?", [$postID]);
        }else{
            $result = $db->query("SELECT " . self::COLUMNS . " FROM post WHERE channel=? AND slug=?", [$channel, $slug]);
        }
        if($db->error){
            $this->dbError($db, 'GET /post/{id}');
            $db->close();
            return;
        }
        $row = $result->num_rows === 1 ? $result->fetch_assoc() : null;
        $db->close();

        // A draft is invisible unless an admin asked for drafts, so nobody
        // learns anything from guessing an id or a slug.
        if(is_null($row) || ($row['status'] === 'draft' && !$includeDrafts && !$this->showDrafts())){
            $this->notFound(is_null($channel) ? $postID : "$channel/$slug", 'GET /post');
            return;
        }
        $this->json = $this->postObject($row);
    }

    // ----- GET /post -----
    // Newest first. createdAt and id break a publishedAt tie, so two releases
    // pushed in the same second still list in the order they were written.
    // LIMIT count+1 decides has_more without a second COUNT query.
    public function listPosts($count, $cursor){
        $count = intval($count);
        if($count < 1){$count = self::LIST_DEFAULT;}
        if($count > self::LIST_MAX){$count = self::LIST_MAX;}
        $offset = intval(base64_decode($cursor ?? ''));
        if($offset < 0){$offset = 0;}

        $messages = array();
        $where = array();
        $params = array();

        $channel = isset($_GET['channel']) ? strval($_GET['channel']) : '';
        if($channel !== ''){
            if(!in_array($channel, self::CHANNELS, true)){
                $messages['channel'] = 'channel must be one of: ' . implode(', ', self::CHANNELS) . '.';
            }else{
                $where[] = 'channel=?';
                $params[] = $channel;
            }
        }

        $status = isset($_GET['status']) ? strval($_GET['status']) : 'published';
        if(!in_array($status, array('draft', 'published', 'all'), true)){
            $messages['status'] = 'status must be one of: draft, published, all.';
        }elseif($status !== 'published' && !$this->isAdmin){
            $this->validationError(array('status' => 'Only an admin key can list drafts.'), 'GET /post - drafts', 403);
            return;
        }elseif($status !== 'all'){
            $where[] = 'status=?';
            $params[] = $status;
        }

        if(!empty($messages)){
            $this->validationError($messages, 'GET /post');
            return;
        }

        $sql = "SELECT " . self::COLUMNS . " FROM post";
        if(!empty($where)){
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        // Drafts have no publishedAt; sort them by when they were last touched
        $sql .= ' ORDER BY COALESCE(publishedAt, lastModified) DESC, createdAt DESC, id DESC LIMIT ? OFFSET ?';
        $params[] = $count + 1;
        $params[] = $offset;

        $db = new Database();
        $result = $db->query($sql, $params);
        if($db->error){
            $this->dbError($db, 'GET /post');
            $db->close();
            return;
        }
        $data = array();
        while($row = $result->fetch_assoc()){
            $data[] = $this->postObject($row);
        }
        $db->close();

        $hasMore = count($data) > $count;
        if($hasMore){
            array_pop($data);
        }
        $this->json['object'] = 'list';
        $this->json['url'] = '/post';
        $this->json['has_more'] = $hasMore;
        if($hasMore){
            $this->json['next_cursor'] = base64_encode($offset + $count);
        }
        $this->json['data'] = $data;
    }

    // ----- POST /post, PATCH /post/{id} -----
    // One method for both, like the entity classes' add(). On PATCH an absent
    // field keeps its stored value; summary may be cleared with null.
    public function save($method, $postID, $data){
        $patch = $method === 'PATCH';
        $existing = null;
        if($patch){
            $this->get($postID, null, null, true);
            if($this->error){
                return;
            }
            $existing = $this->json;
            $this->json = array();
        }
        $has = fn($field) => !$patch || property_exists($data, $field);
        $messages = array();

        // channel: required on POST, fixed afterwards. Moving a post between
        // channels would change its URL and its version rules at once.
        if($patch){
            $channel = $existing['channel'];
            if(property_exists($data, 'channel') && $data->channel !== $channel){
                $messages['channel'] = 'channel cannot be changed once a post exists.';
            }
        }else{
            $channel = isset($data->channel) ? strval($data->channel) : '';
            if(!in_array($channel, self::CHANNELS, true)){
                $messages['channel'] = 'channel is required and must be one of: ' . implode(', ', self::CHANNELS) . '.';
            }
        }

        $title = $has('title') ? $this->text($data, 'title', self::TITLE_MAX, true, $messages) : $existing['title'];
        $summary = $has('summary') ? $this->text($data, 'summary', self::SUMMARY_MAX, false, $messages) : $existing['summary'];
        $body = $has('body') ? $this->text($data, 'body', self::BODY_MAX, true, $messages, true) : $existing['body'];

        // version and breaking belong to the api channel only
        $version = $patch ? $existing['version'] : null;
        if($has('version') && isset($data->version) && !is_null($data->version) && $data->version !== ''){
            if($channel !== 'api'){
                $messages['version'] = 'version is only for api posts.';
            }else{
                $version = self::normalizeVersion(TextInput::trim(strval($data->version)));
                if(is_null($version)){
                    $messages['version'] = 'version must look like 5.4.0, 2.1 or 2.0-beta.1.';
                }
            }
        }elseif($has('version') && $patch && property_exists($data, 'version')){
            $version = null;
        }
        if($channel === 'api' && is_null($version) && !isset($messages['version'])){
            $messages['version'] = 'version is required for api posts.';
        }

        $breaking = $patch ? $existing['breaking'] : false;
        if($has('breaking') && isset($data->breaking)){
            if(!is_bool($data->breaking)){
                $messages['breaking'] = 'breaking must be true or false.';
            }elseif($data->breaking && $channel !== 'api'){
                $messages['breaking'] = 'breaking is only for api posts.';
            }else{
                $breaking = $data->breaking;
            }
        }

        // slug: defaults to v{version} for a release and to the title for news
        $slug = $patch ? $existing['slug'] : null;
        if($has('slug') && isset($data->slug) && $data->slug !== ''){
            $slug = strtolower(TextInput::trim(strval($data->slug)));
            if(!self::validSlug($slug)){
                $messages['slug'] = 'slug may hold only lowercase letters, digits, hyphens and dots, and must start and end with a letter or digit (' . self::SLUG_MAX . ' characters at most).';
            }
        }elseif(!$patch){
            $slug = $channel === 'api' && !is_null($version) ? 'v' . $version : self::slugify($title ?? '');
            if($slug === '' && !isset($messages['title'])){
                $messages['slug'] = 'slug is required when the title has no letters or digits.';
            }
        }

        $status = $patch ? $existing['status'] : 'draft';
        if($has('status') && isset($data->status)){
            if(!in_array($data->status, self::STATUSES, true)){
                $messages['status'] = 'status must be one of: ' . implode(', ', self::STATUSES) . '.';
            }else{
                $status = $data->status;
            }
        }

        $publishedAt = $patch ? $existing['published_at'] : null;
        if($has('published_at') && property_exists($data, 'published_at')){
            if(is_null($data->published_at)){
                $publishedAt = null;
            }elseif(!is_int($data->published_at) || $data->published_at < 0){
                $messages['published_at'] = 'published_at must be a Unix timestamp (whole seconds).';
            }else{
                $publishedAt = $data->published_at;
            }
        }
        if($status === 'published' && is_null($publishedAt)){
            if($has('published_at') && property_exists($data, 'published_at') && is_null($data->published_at)){
                $messages['published_at'] = 'A published post needs a published_at; set status to draft to clear it.';
            }else{
                $publishedAt = time();
            }
        }

        if(!empty($messages)){
            $this->validationError($messages, "$method /post");
            return;
        }

        // Uniqueness, checked first so the answer names the field. The unique
        // keys are the backstop for a race no single editor can produce.
        $db = new Database();
        $conflicts = array();
        $result = $db->query("SELECT id FROM post WHERE channel=? AND slug=? AND id<>?", [$channel, $slug, $postID ?? '']);
        if(!$db->error && $result->num_rows > 0){
            $conflicts['slug'] = "Another $channel post already uses the slug \"$slug\".";
        }
        if(!$db->error && !is_null($version)){
            $result = $db->query("SELECT id FROM post WHERE channel='api' AND version=? AND id<>?", [$version, $postID ?? '']);
            if(!$db->error && $result->num_rows > 0){
                $conflicts['version'] = "Version $version already has a changelog post.";
            }
        }
        if($db->error){
            $this->dbError($db, "$method /post - uniqueness");
            $db->close();
            return;
        }
        if(!empty($conflicts)){
            $db->close();
            $this->validationError($conflicts, "$method /post - conflict", 409);
            return;
        }

        $now = time();
        if($patch){
            $db->query("UPDATE post SET slug=?, title=?, summary=?, body=?, version=?, breaking=?, status=?, publishedAt=?, lastModified=? WHERE id=?",
                [$slug, $title, $summary, $body, $version, $breaking ? 1 : 0, $status, $publishedAt, $now, $postID]);
        }else{
            $uuid = new uuid();
            $postID = $uuid->generate('post');
            $db->query("INSERT INTO post (id, channel, slug, title, summary, body, version, breaking, status, publishedAt, createdAt, lastModified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$postID, $channel, $slug, $title, $summary, $body, $version, $breaking ? 1 : 0, $status, $publishedAt, $now, $now]);
        }
        if($db->error){
            $this->dbError($db, "$method /post");
            $db->close();
            return;
        }
        $db->close();

        $this->get($postID, null, null, true);
        if(!$this->error && !$patch){
            $this->responseCode = 201;
            $this->responseHeader = 'Location: ' . $this->json['url'];
        }
    }

    // ----- DELETE /post/{id} -----
    public function delete($postID){
        $this->get($postID, null, null, true);
        if($this->error){
            return;
        }
        $this->json = array();
        $db = new Database();
        $db->query("DELETE FROM post WHERE id=?", [$postID]);
        if($db->error){
            $this->dbError($db, 'DELETE /post/{id}');
            $db->close();
            return;
        }
        $db->close();
        $this->responseCode = 204;
    }

    // ----- Router -----
    public function api($method, $function, $id, $apiKey, $count, $cursor, $data){
        /*---
        GET    https://api.catalog.beer/post?channel=api&count=&cursor=
        GET    https://api.catalog.beer/post/{post_id}
        GET    https://api.catalog.beer/post/{channel}/{slug}   (function=channel, slug in $_GET)
        POST   https://api.catalog.beer/post
        PATCH  https://api.catalog.beer/post/{post_id}
        DELETE https://api.catalog.beer/post/{post_id}
        ---*/
        $this->checkAdmin($apiKey);

        switch($method){
            case 'GET':
                if($function === 'channel'){
                    $channel = $_GET['channel'] ?? '';
                    $slug = $_GET['slug'] ?? '';
                    if(in_array($channel, self::CHANNELS, true) && self::validSlug($slug)){
                        $this->get(null, $channel, $slug);
                    }else{
                        $this->notFound("$channel/$slug", 'GET /post/{channel}/{slug}');
                    }
                }elseif(empty($function) && !empty($id)){
                    $this->get($id);
                }elseif(empty($function) && empty($id)){
                    $this->listPosts($count, $cursor);
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'POST':
                if(empty($function) && empty($id)){
                    if($this->requireAdmin($apiKey, 'POST /post')){
                        $this->save('POST', null, $data);
                    }
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'PATCH':
                if(empty($function) && !empty($id)){
                    if($this->requireAdmin($apiKey, 'PATCH /post/{id}')){
                        $this->save('PATCH', $id, $data);
                    }
                }else{
                    $this->invalidPath($function);
                }
                break;
            case 'DELETE':
                if(empty($function) && !empty($id)){
                    if($this->requireAdmin($apiKey, 'DELETE /post/{id}')){
                        $this->delete($id);
                    }
                }else{
                    $this->invalidPath($function);
                }
                break;
            default:
                $this->error = true;
                $this->errorMsg = 'Invalid HTTP method for this endpoint.';
                $this->responseCode = 405;
                $this->responseHeader = 'Allow: GET, POST, PATCH, DELETE';

                $errorLog = new LogError();
                $errorLog->errorNumber = 354;
                $errorLog->errorMsg = 'Invalid Method (/post)';
                $errorLog->badData = $method;
                $errorLog->filename = 'Post.class.php';
                $errorLog->write();
        }

        if($this->error){
            $this->json['error'] = true;
            $this->json['error_msg'] = $this->errorMsg;
        }
    }

    private function invalidPath($function){
        $this->error = true;
        $this->errorMsg = 'Invalid path. The URI you requested does not exist.';
        $this->responseCode = 404;

        $errorLog = new LogError();
        $errorLog->errorNumber = 355;
        $errorLog->errorMsg = 'Invalid function (/post)';
        $errorLog->badData = $function;
        $errorLog->filename = 'Post.class.php';
        $errorLog->write();
    }
}
?>
