<?php
class Style {

    // API Response
    public $error = false;
    public $errorMsg = null;
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    /*---
    GET https://api.catalog.beer/style              — all canonical styles, alphabetical (+ version)
    GET https://api.catalog.beer/style/{slug}       — one style with full detail
    GET https://api.catalog.beer/style/search?q=    — full-text search over names, aliases, descriptions
    GET https://api.catalog.beer/style/parent       — the family groupings (curated sort_order)
    GET https://api.catalog.beer/style/class        — the super-classes (curated sort_order)

    Read-only reference data. Authenticated like every other endpoint (the
    caller is already validated/rate-limited in index.php). The frontend's
    guided-style typeahead fetches the list once per session.
    ---*/
    public function api($method, $function, $id){
        if($method !== 'GET'){
            $this->responseCode = 405;
            $this->responseHeader = 'Allow: GET';
            $this->json['error'] = true;
            $this->json['error_msg'] = 'Method Not Allowed. Use GET for this endpoint.';
            return;
        }

        if(!empty($function)){
            if($function === 'parent'){
                $this->listParents();
            }elseif($function === 'class'){
                $this->listClasses();
            }elseif($function === 'search'){
                // GET https://api.catalog.beer/style/search?q=
                $searchQuery = isset($_GET['q']) ? $_GET['q'] : '';
                $searchCount = isset($_GET['count']) ? $_GET['count'] : 25;
                $searchCursor = isset($_GET['cursor']) ? $_GET['cursor'] : base64_encode('0');
                $this->search($searchQuery, $searchCursor, $searchCount);
            }else{
                $this->responseCode = 404;
                $this->json['error'] = true;
                $this->json['error_msg'] = 'Invalid path. The URI you requested does not exist.';
            }
        }elseif(!empty($id)){
            $this->getStyle($id);
        }else{
            $this->listStyles();
        }
    }

    // GET /style — full canonical list, one page, with aliases and version stamp
    private function listStyles(){
        $db = new Database();

        // Styles in alphabetical order (predictable for consumers; pickers re-sort as needed)
        $styles = array();
        $order = array();
        $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.class, s.is_catch_all, s.srm_min, s.srm_max FROM style s LEFT JOIN style_parent p ON s.parent = p.slug ORDER BY s.canonical_name");
        if($db->error){
            $this->dbError($db->errorMsg, $db->responseCode);
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){
            $styles[$row['id']] = array(
                'id' => $row['id'],
                'object' => 'style',
                'name' => $row['canonical_name'],
                'beverage_type' => $row['beverage_type'],
                'parent' => $row['parent'],
                'class' => $row['class'],
                'catch_all' => (bool) $row['is_catch_all'],
                'aliases' => array(),
                // Color only — the one spec the index page's swatch device needs.
                // Full specs stay on GET /style/{slug}.
                'srm' => $this->range($row['srm_min'], $row['srm_max'], true),
            );
            $order[] = $row['id'];
        }

        // Attach aliases (everything that resolves to each style, minus the canonical name itself)
        $result = $db->query("SELECT style_id, alias FROM style_alias");
        if($db->error){
            $this->dbError($db->errorMsg, $db->responseCode);
            $db->close();
            return;
        }
        while($row = $result->fetch_assoc()){
            $sid = $row['style_id'];
            if(isset($styles[$sid]) && strcasecmp($row['alias'], $styles[$sid]['name']) !== 0){
                $styles[$sid]['aliases'][] = $row['alias'];
            }
        }

        // Version stamp
        $version = null;
        $result = $db->query("SELECT version FROM style_meta WHERE id=1");
        if(!$db->error && $result !== null && ($row = $result->fetch_assoc())){
            $version = $row['version'];
        }
        $db->close();

        // Preserve sort order
        $data = array();
        foreach($order as $sid){
            $data[] = $styles[$sid];
        }

        $this->json['object'] = 'list';
        $this->json['url'] = '/style';
        $this->json['version'] = $version;
        $this->json['has_more'] = false;
        $this->json['data'] = $data;
    }

    // GET /style/{slug} — one style with full detail, including the editorial
    // content (description, AAFM, history, notes, examples, sources) authored
    // in the style library and seeded into style_content
    private function getStyle($id){
        $db = new Database();
        $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.name AS parent_name, p.class, s.source, s.is_catch_all, s.abv_min, s.abv_max, s.ibu_min, s.ibu_max, s.srm_min, s.srm_max, s.og_min, s.og_max, s.fg_min, s.fg_max, c.description, c.appearance, c.aroma, c.flavor, c.mouthfeel, c.history, c.notes, c.commercial_examples, c.sources FROM style s LEFT JOIN style_parent p ON s.parent = p.slug LEFT JOIN style_content c ON c.style_id = s.id WHERE s.id=?", [$id]);
        if($db->error){
            $this->dbError($db->errorMsg, $db->responseCode);
            $db->close();
            return;
        }
        if($result === null || $result->num_rows !== 1){
            $this->responseCode = 404;
            $this->json['error'] = true;
            $this->json['error_msg'] = 'Sorry, we don\'t have a style with that id.';
            $db->close();
            return;
        }
        $row = $result->fetch_assoc();

        // Aliases for this style (excluding the canonical name)
        $aliases = array();
        $aResult = $db->query("SELECT alias FROM style_alias WHERE style_id=?", [$id]);
        if(!$db->error && $aResult !== null){
            while($a = $aResult->fetch_assoc()){
                if(strcasecmp($a['alias'], $row['canonical_name']) !== 0){
                    $aliases[] = $a['alias'];
                }
            }
        }
        $db->close();

        $this->json = array(
            'id' => $row['id'],
            'object' => 'style',
            'name' => $row['canonical_name'],
            'beverage_type' => $row['beverage_type'],
            'parent' => $row['parent'],
            'class' => $row['class'],
            'parent_name' => $row['parent_name'],
            'source' => $row['source'],
            'catch_all' => (bool) $row['is_catch_all'],
            'aliases' => $aliases,
            'specs' => array(
                'abv' => $this->range($row['abv_min'], $row['abv_max'], true),
                'ibu' => $this->range($row['ibu_min'], $row['ibu_max'], false),
                'srm' => $this->range($row['srm_min'], $row['srm_max'], true),
                'og'  => $this->range($row['og_min'], $row['og_max'], true),
                'fg'  => $this->range($row['fg_min'], $row['fg_max'], true),
            ),
            // Editorial content from the style library (null until seeded, and
            // AAFM is null by design for catch-all styles)
            'description' => $row['description'],
            'appearance' => $row['appearance'],
            'aroma' => $row['aroma'],
            'flavor' => $row['flavor'],
            'mouthfeel' => $row['mouthfeel'],
            'history' => $row['history'],
            'notes' => $row['notes'],
            'commercial_examples' => $this->jsonColumn($row['commercial_examples']),
            'sources' => $this->jsonColumn($row['sources']),
        );
    }

    // GET /style/parent — the family groupings (with class rollup + aliases)
    private function listParents(){
        $db = new Database();
        $parents = array();
        $order = array();
        $result = $db->query("SELECT slug, name, beverage_type, class, description, sort_order FROM style_parent ORDER BY sort_order");
        if($db->error){ $this->dbError($db->errorMsg, $db->responseCode); $db->close(); return; }
        while($row = $result->fetch_assoc()){
            $parents[$row['slug']] = array(
                'slug' => $row['slug'],
                'object' => 'style_parent',
                'name' => $row['name'],
                'beverage_type' => $row['beverage_type'],
                'class' => $row['class'],
                'description' => $row['description'],
                'sort_order' => intval($row['sort_order']),
                'aliases' => array(),
            );
            $order[] = $row['slug'];
        }
        // Attach family aliases
        $result = $db->query("SELECT alias, parent FROM parent_alias");
        if($db->error){ $this->dbError($db->errorMsg, $db->responseCode); $db->close(); return; }
        while($row = $result->fetch_assoc()){
            if(isset($parents[$row['parent']])){ $parents[$row['parent']]['aliases'][] = $row['alias']; }
        }
        $db->close();

        $data = array();
        foreach($order as $slug){ $data[] = $parents[$slug]; }
        $this->json['object'] = 'list';
        $this->json['url'] = '/style/parent';
        $this->json['has_more'] = false;
        $this->json['data'] = $data;
    }

    // GET /style/class — the super-classes (Ale/Lager) with aliases
    private function listClasses(){
        $db = new Database();
        $classes = array();
        $order = array();
        $result = $db->query("SELECT slug, name, beverage_type, sort_order FROM style_class ORDER BY sort_order");
        if($db->error){ $this->dbError($db->errorMsg, $db->responseCode); $db->close(); return; }
        while($row = $result->fetch_assoc()){
            $classes[$row['slug']] = array(
                'slug' => $row['slug'],
                'object' => 'style_class',
                'name' => $row['name'],
                'beverage_type' => $row['beverage_type'],
                'sort_order' => intval($row['sort_order']),
                'aliases' => array(),
            );
            $order[] = $row['slug'];
        }
        $result = $db->query("SELECT alias, class FROM class_alias");
        if($db->error){ $this->dbError($db->errorMsg, $db->responseCode); $db->close(); return; }
        while($row = $result->fetch_assoc()){
            if(isset($classes[$row['class']])){ $classes[$row['class']]['aliases'][] = $row['alias']; }
        }
        $db->close();

        $data = array();
        foreach($order as $slug){ $data[] = $classes[$slug]; }
        $this->json['object'] = 'list';
        $this->json['url'] = '/style/class';
        $this->json['has_more'] = false;
        $this->json['data'] = $data;
    }

    // GET /style/search?q= — full-text search across canonical names, aliases,
    // and editorial descriptions. Aliases carry most real queries: "NEIPA" and
    // "Juicy IPA" only reach hazy-ipa through style_alias. Results are compact
    // style objects in the same shape as GET /style list rows.
    private function search($query, $cursor, $count){
        // Validate query
        $query = trim($query ?? '');
        if($query === ''){
            // Missing Query
            $this->responseCode = 400;
            $this->json['error'] = true;
            $this->json['error_msg'] = "Missing search query. Include a 'q' parameter with your search terms.";

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 269;
            $errorLog->errorMsg = 'Missing style search query';
            $errorLog->badData = '';
            $errorLog->filename = 'API / Style.class.php';
            $errorLog->write();
            return;
        }

        if(strlen($query) > 255){
            // Query Too Long
            $this->responseCode = 400;
            $this->json['error'] = true;
            $this->json['error_msg'] = 'Search query is too long. Please limit your query to 255 characters.';

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 270;
            $errorLog->errorMsg = 'Style search query too long';
            $errorLog->badData = strlen($query) . ' characters';
            $errorLog->filename = 'API / Style.class.php';
            $errorLog->write();
            return;
        }

        // Validate count
        $count = intval($count);
        if($count < 1 || $count > 100){
            $this->responseCode = 400;
            $this->json['error'] = true;
            $this->json['error_msg'] = 'The count value must be between 1 and 100.';

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 271;
            $errorLog->errorMsg = 'Invalid count for style search';
            $errorLog->badData = $count;
            $errorLog->filename = 'API / Style.class.php';
            $errorLog->write();
            return;
        }

        // Validate cursor
        $offset = intval(base64_decode($cursor));
        if($offset < 0){
            $offset = 0;
        }

        // Request count+1 to determine if there are more results
        $fetchCount = $count + 1;

        // A BOOLEAN MODE expression requiring EVERY term, used to separate
        // styles that match the whole query from those matching only part of
        // it. "juicy ipa" must put hazy-ipa (both terms) above american-ipa
        // (one term), and no amount of relevance arithmetic reliably does that.
        //
        // Everything that is not a letter or digit becomes a separator. This is
        // an allowlist on purpose: a blacklist of MySQL's operators
        // (+ - < > ~ * parens quotes @) misses others — "%" alone was enough to
        // produce "syntax error, unexpected $end" from the FULLTEXT parser, a
        // 500 on a query a user could plausibly type. \p{L} keeps non-ASCII
        // letters, so "kölsch" and "münchner" still search as single terms.
        //
        // An all-punctuation query yields an empty expression, which is left
        // empty rather than falling back to the raw string — falling back would
        // feed the very operators just stripped straight into BOOLEAN MODE.
        // AGAINST('' IN BOOLEAN MODE) matches nothing and raises no error, so
        // the natural-language tiers below still apply.
        //
        // Terms below innodb_ft_min_token_size are dropped by MySQL rather than
        // failing the AND, so short words degrade instead of returning nothing.
        $boolTerms = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query)), -1, PREG_SPLIT_NO_EMPTY);
        $boolQuery = '';
        foreach($boolTerms as $t){
            $boolQuery .= '+' . $t . ' ';
        }
        $boolQuery = trim($boolQuery);

        // The same sanitised terms feed the NATURAL LANGUAGE matches, which are
        // not as forgiving as their name suggests: AGAINST('*' IN NATURAL
        // LANGUAGE MODE) is a parser error, not an empty result. A bare "*"
        // therefore 500s — a pre-existing fault in this endpoint since it
        // shipped, reachable by anyone typing a lone asterisk into a search
        // box, and logged as C272 with no indication it was user input rather
        // than a broken query.
        //
        // Only the exact-match comparisons keep the raw query, since those are
        // string equality against canonical names and aliases, where
        // punctuation is meaningful and no FULLTEXT parser is involved.
        $nlQuery = implode(' ', $boolTerms);

        // Ranking is tiered, not a blended score:
        //
        //   0  the query IS the style — exact canonical name or exact alias
        //   1  EVERY query term appears in the style's identity terms
        //   2  SOME query term appears in its identity terms
        //   3  a hit only in the editorial description
        //
        // Tiers exist because MATCH() relevance cannot carry this weight. Its
        // scores come from IDF and document length within one index, so they
        // are incomparable across columns and, within a column, are decided by
        // document length — a property unrelated to what a searcher meant. Two
        // attempts to rank tier-mates by relevance both failed: concatenated
        // aliases ranked styles by how many synonyms they had, and deduplicated
        // tokens ranked them by how many distinct tokens they had. Neither
        // means anything, so q=ipa surfaced experimental-ipa and
        // new-zealand-ipa — used by zero beers — above american-ipa, used by
        // 6,530.
        //
        // Hence beer_count as the primary order WITHIN a tier: how many
        // catalogued beers actually use the style. It ranks below tier on
        // purpose. Tiers already guarantee tier-mates match the query equally
        // well, so popularity only separates genuine equivalents; promoted
        // above tier it would bury a precisely-matched rare style under a
        // popular vague one, which is why "juicy ipa" needs the all-terms tier
        // to keep hazy-ipa (13 beers) above american-ipa (6,530).
        //
        // Catch-all styles sort below specific ones within a tier, because
        // beer_count structurally favours them: they are the buckets beers land
        // in when nothing more precise fits, so they accumulate counts no
        // specific style can match. specialty-beer holds 1,209 beers and its
        // aliases mention stout, which put it second for q=stout — above four
        // actual stouts. A catch-all is a fallback, not an answer. This only
        // applies within a tier, so searching for a catch-all by name still
        // returns it first on the exact-match tier.
        //
        // search_name is canonical_name plus every alias in one document, so
        // "IPA" reaches american-ipa even though its name spells out "India
        // Pale Ale" — the case the original ranking could never handle, because
        // the token was absent from the column carrying the heaviest weight.
        $db = new Database();
        $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.class, s.is_catch_all, s.srm_min, s.srm_max, CASE WHEN LOWER(s.canonical_name) = LOWER(?) THEN 0 WHEN EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) THEN 0 WHEN MATCH(s.search_name) AGAINST(? IN BOOLEAN MODE) > 0 THEN 1 WHEN MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) > 0 THEN 2 ELSE 3 END AS tier, MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel, COALESCE(MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE), 0) AS body_rel FROM style s LEFT JOIN style_parent p ON s.parent = p.slug LEFT JOIN style_content c ON c.style_id = s.id WHERE MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) OR MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE) OR LOWER(s.canonical_name) = LOWER(?) OR EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) ORDER BY tier, s.is_catch_all, s.beer_count DESC, name_rel DESC, body_rel DESC, CHAR_LENGTH(s.canonical_name), s.canonical_name LIMIT ?, ?", [$query, $query, $boolQuery, $nlQuery, $nlQuery, $nlQuery, $nlQuery, $nlQuery, $query, $query, $offset, $fetchCount]);
        if($db->error){
            // Query Error
            $this->responseCode = 500;
            $this->json['error'] = true;
            $this->json['error_msg'] = 'Sorry, we encountered an error while processing your search.';

            // Log Error
            $errorLog = new LogError();
            $errorLog->errorNumber = 272;
            $errorLog->errorMsg = 'Style FULLTEXT query error';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Style.class.php';
            $errorLog->write();
            $db->close();
            return;
        }

        $rowCount = 0;
        $styles = array();
        $order = array();
        while($row = $result->fetch_assoc()){
            $rowCount++;
            if($rowCount > $count){
                // Extra row — indicates more results exist
                break;
            }
            $styles[$row['id']] = array(
                'id' => $row['id'],
                'object' => 'style',
                'name' => $row['canonical_name'],
                'beverage_type' => $row['beverage_type'],
                'parent' => $row['parent'],
                'class' => $row['class'],
                'catch_all' => (bool) $row['is_catch_all'],
                'aliases' => array(),
                'srm' => $this->range($row['srm_min'], $row['srm_max'], true),
            );
            $order[] = $row['id'];
        }

        // Attach aliases (everything that resolves to each style, minus the canonical name itself)
        if(!empty($order)){
            $placeholders = implode(',', array_fill(0, count($order), '?'));
            $aResult = $db->query("SELECT style_id, alias FROM style_alias WHERE style_id IN ($placeholders)", $order);
            if(!$db->error && $aResult !== null){
                while($a = $aResult->fetch_assoc()){
                    $sid = $a['style_id'];
                    if(isset($styles[$sid]) && strcasecmp($a['alias'], $styles[$sid]['name']) !== 0){
                        $styles[$sid]['aliases'][] = $a['alias'];
                    }
                }
            }
        }

        // Matching families, returned alongside the styles rather than mixed
        // into them — keeping `data` a uniform array of style objects.
        //
        // Broad queries have no single correct style answer. "ipa" is not a
        // style; it is 12 of them. Ranking one arbitrarily above the rest is
        // the wrong answer however good the scoring is, so the family gets
        // surfaced as its own result and the caller can offer the group.
        //
        // No FULLTEXT index here on purpose: style_parent holds 26 rows, so a
        // scan costs nothing and an index would be one more thing to rebuild.
        // sort_order has to be in the SELECT list: DISTINCT plus an ORDER BY on
        // a column that isn't selected is rejected outright.
        //
        // Matching is exact — slug, name, or alias — with no substring clause.
        // A LIKE '%q%' pass was tried and removed: q=ale returned 11 of the 26
        // families, including Pale Lager, because "ale" sits inside "P-ale".
        // Substring matching with no word boundary is simply wrong here, and
        // exact matching covers every real case: ipa -> ipa, stout -> stout,
        // "pale ale" -> pale-ale, "india pale ale" -> ipa. Bare "ale" matching
        // nothing is correct, because "ale" names no single family.
        //
        // Only assembled for the first page. Families are not paginated, so
        // repeating them on page 2 onward just makes a paginating client
        // re-render the same block.
        $families = array();
        $fResult = ($offset === 0)
            ? $db->query("SELECT DISTINCT p.slug, p.name, p.beverage_type, p.class, p.description, p.sort_order FROM style_parent p LEFT JOIN parent_alias pa ON pa.parent = p.slug WHERE LOWER(p.slug) = LOWER(?) OR LOWER(p.name) = LOWER(?) OR LOWER(pa.alias) = LOWER(?) ORDER BY p.sort_order", [$query, $query, $query])
            : null;
        if(!$db->error && $fResult !== null){
            while($f = $fResult->fetch_assoc()){
                // Same shape as GET /style/parent rows minus `aliases`, which
                // would cost a second query to assemble for a field callers do
                // not need here. Fetch the full object from /style/parent.
                $families[] = array(
                    'slug' => $f['slug'],
                    'object' => 'style_parent',
                    'name' => $f['name'],
                    'beverage_type' => $f['beverage_type'],
                    'class' => $f['class'],
                    'description' => $f['description'],
                    'sort_order' => intval($f['sort_order']),
                );
            }
        }
        $db->close();

        // Preserve relevance order
        $data = array();
        foreach($order as $sid){
            $data[] = $styles[$sid];
        }

        // Build response
        $hasMore = ($rowCount > $count);
        $this->json['object'] = 'list';
        $this->json['url'] = '/style/search';
        $this->json['query'] = $query;
        $this->json['has_more'] = $hasMore;
        if($hasMore){
            $this->json['next_cursor'] = base64_encode($offset + $count);
        }
        // Families are never paginated — there are 26 in total and a query
        // matches at most a handful. has_more and next_cursor describe `data`.
        // Present only on the first page; an empty array on later pages means
        // "not repeated here", not "no families matched".
        $this->json['families'] = $families;
        $this->json['data'] = $data;
    }

    // Decode a MySQL JSON column into a PHP structure, or null when absent
    private function jsonColumn($value){
        if($value === null || $value === ''){
            return null;
        }
        $decoded = json_decode($value, true);
        return ($decoded === null) ? null : $decoded;
    }

    // Build a {min,max} spec range, or null if both bounds are absent
    private function range($min, $max, $float){
        if($min === null && $max === null){
            return null;
        }
        if($float){
            return array('min' => ($min === null ? null : floatval($min)), 'max' => ($max === null ? null : floatval($max)));
        }
        return array('min' => ($min === null ? null : intval($min)), 'max' => ($max === null ? null : intval($max)));
    }

    /*
    Generate Algolia search objects for EVERY style in one pass.

    Styles are the fourth record type in the `catalog` index. They exist so an
    informational query ("gose") can return the page that explains the style,
    not just forty beers filed under it. The aliases ride along as a searchable
    attribute, which makes "NEIPA" reach the Juicy or Hazy IPA style record
    directly — the alias vocabulary doing double duty as a synonym mechanism.

    objectID is deterministic ('style-' + slug) rather than routed through the
    algolia table: style slugs are stable, public, and curated, so there is
    nothing to gain from the random-ID indirection the user-writable types
    need — and it spares a schema migration. Styles are a slow-moving
    vocabulary with no API write path, so there is no real-time sync either;
    batch-upload.php is the only writer.

    Built as one method returning all styles (two queries total) instead of a
    per-style generator, because the only caller is the batch script and ~250
    styles never warrant 250 query pairs.

    Returns an array of search-object arrays, or an empty array on failure
    (logged) — the Algolia sync is best-effort by design.
    */
    public function generateStyleSearchObjects(){
        $db = new Database();
        $objects = array();

        // One row per style, with family/class display names and the editorial
        // description resolved through their joins.
        $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.name AS parent_name, p.class AS class_slug, sc.name AS class_name, s.is_catch_all, s.abv_min, s.abv_max, s.ibu_min, s.ibu_max, s.srm_min, s.srm_max, s.beer_count, c.description FROM style s LEFT JOIN style_parent p ON s.parent = p.slug LEFT JOIN style_class sc ON p.class = sc.slug LEFT JOIN style_content c ON c.style_id = s.id ORDER BY s.canonical_name");
        if($db->error){
            $errorLog = new LogError();
            $errorLog->errorNumber = 285;
            $errorLog->errorMsg = 'Failed to generate style search objects.';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Style.class.php';
            $errorLog->write();
            $db->close();
            return array();
        }

        $byID = array();
        while($row = $result->fetch_assoc()){
            $array = array();
            $array['objectID'] = 'style-' . $row['id'];
            $array['styleID'] = $row['id'];
            $array['name'] = $row['canonical_name'];
            $array['beverage_type'] = $row['beverage_type'];

            // Same facet fields the beers carry, so one style_family
            // refinement spans both record types.
            if(!empty($row['parent'])){
                $array['style_family_slug'] = $row['parent'];
                if(!empty($row['parent_name'])){$array['style_family'] = $row['parent_name'];}
            }
            if(!empty($row['class_slug'])){
                $array['style_class_slug'] = $row['class_slug'];
                if(!empty($row['class_name'])){$array['style_class'] = $row['class_name'];}
            }

            $array['catch_all'] = (bool) $row['is_catch_all'];
            $array['aliases'] = array();

            // Specs — flat min/max keys (not the API's nested range objects)
            // so Algolia numeric filters can reach them directly.
            if($row['abv_min'] !== null){$array['abv_min'] = floatval($row['abv_min']);}
            if($row['abv_max'] !== null){$array['abv_max'] = floatval($row['abv_max']);}
            if($row['ibu_min'] !== null){$array['ibu_min'] = intval($row['ibu_min']);}
            if($row['ibu_max'] !== null){$array['ibu_max'] = intval($row['ibu_max']);}
            if($row['srm_min'] !== null){$array['srm_min'] = intval($row['srm_min']);}
            if($row['srm_max'] !== null){$array['srm_max'] = intval($row['srm_max']);}

            $array['beer_count'] = intval($row['beer_count']);
            if(!empty($row['description'])){$array['description'] = $row['description'];}

            // SiteSearch Fields
            $array['type'] = 'style';
            // Family reads as parent context, paralleling how beers use the
            // brewer; fall back to the class ("Ale") for family-less styles.
            if(!empty($row['parent_name'])){
                $array['subtitle'] = $row['parent_name'];
            }elseif(!empty($row['class_name'])){
                $array['subtitle'] = $row['class_name'];
            }
            $array['page_url'] = '/style/' . $row['id'];

            $byID[$row['id']] = $array;
        }

        // Attach aliases (everything that resolves to each style, minus the
        // canonical name itself — same rule as listStyles())
        $result = $db->query("SELECT style_id, alias FROM style_alias");
        if($db->error){
            // Degrade to no aliases rather than failing the upload
            $errorLog = new LogError();
            $errorLog->errorNumber = 290;
            $errorLog->errorMsg = 'Failed to attach aliases to style search objects.';
            $errorLog->badData = $db->errorMsg;
            $errorLog->filename = 'API / Style.class.php';
            $errorLog->write();
        }else{
            while($row = $result->fetch_assoc()){
                $sid = $row['style_id'];
                if(isset($byID[$sid]) && strcasecmp($row['alias'], $byID[$sid]['name']) !== 0){
                    $byID[$sid]['aliases'][] = $row['alias'];
                }
            }
        }
        $db->close();

        foreach($byID as $array){
            $objects[] = $array;
        }
        return $objects;
    }

    // Shared DB error response + log
    private function dbError($msg, $code){
        $this->error = true;
        $this->responseCode = ($code && $code >= 400) ? $code : 500;
        $this->json['error'] = true;
        $this->json['error_msg'] = 'Sorry, we encountered an error retrieving style data.';

        $errorLog = new LogError();
        $errorLog->errorNumber = 263;
        $errorLog->errorMsg = 'Style reference query error';
        $errorLog->badData = $msg;
        $errorLog->filename = 'API / Style.class.php';
        $errorLog->write();
    }
}
?>
