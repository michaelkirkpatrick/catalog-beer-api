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

        // Ranking is tiered, not a blended score:
        //
        //   0  the query IS the style — exact canonical name or exact alias
        //   1  a hit somewhere in the style's identity terms (search_name)
        //   2  a hit only in the editorial description
        //
        // Relevance orders results *within* a tier and never across one. That
        // restriction is the whole point: MATCH() relevance is derived from IDF
        // within a single index, so scores from different columns are not on a
        // common scale and cannot be meaningfully weighted against each other.
        // The previous implementation multiplied three such scores by 3/2/1 and
        // compared them, which is why q=ipa ranked american-belgo-ale above
        // american-ipa, and q=ale returned aged-beer first.
        //
        // search_name is canonical_name plus every alias in one document, so
        // "IPA" reaches american-ipa even though its name spells out "India
        // Pale Ale" — the case the old ranking could never handle, because the
        // token was absent from the column carrying the heaviest weight.
        $db = new Database();
        $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.class, s.is_catch_all, s.srm_min, s.srm_max, CASE WHEN LOWER(s.canonical_name) = LOWER(?) THEN 0 WHEN EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) THEN 0 WHEN MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) > 0 THEN 1 ELSE 2 END AS tier, MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel, COALESCE(MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE), 0) AS body_rel FROM style s LEFT JOIN style_parent p ON s.parent = p.slug LEFT JOIN style_content c ON c.style_id = s.id WHERE MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) OR MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE) OR LOWER(s.canonical_name) = LOWER(?) OR EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) ORDER BY tier, name_rel DESC, body_rel DESC, CHAR_LENGTH(s.canonical_name), s.canonical_name LIMIT ?, ?", [$query, $query, $query, $query, $query, $query, $query, $query, $query, $offset, $fetchCount]);
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
        // The substring clause is only added for queries of 3+ characters.
        // LIKE '%a%' would otherwise match nearly every family and drown the
        // style results it is meant to complement.
        $families = array();
        $fSql = "SELECT DISTINCT p.slug, p.name, p.beverage_type, p.class, p.description, p.sort_order FROM style_parent p LEFT JOIN parent_alias pa ON pa.parent = p.slug WHERE LOWER(p.slug) = LOWER(?) OR LOWER(p.name) = LOWER(?) OR LOWER(pa.alias) = LOWER(?)";
        $fParams = array($query, $query, $query);
        if(mb_strlen($query) >= 3){
            $fSql .= " OR LOWER(p.name) LIKE LOWER(CONCAT('%', ?, '%'))";
            $fParams[] = $query;
        }
        $fSql .= " ORDER BY p.sort_order";
        $fResult = $db->query($fSql, $fParams);
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
