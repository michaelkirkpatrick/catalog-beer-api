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
    /*--
    ftTerms — sanitise a user query into the two FULLTEXT expressions the
    ranking needs: a BOOLEAN MODE all-terms expression and a NATURAL LANGUAGE
    term list. Shared by search() and suggest() so the two can't drift.

    A BOOLEAN MODE expression requiring EVERY term separates styles that match
    the whole query from those matching only part of it. "juicy ipa" must put
    hazy-ipa (both terms) above american-ipa (one term), and no amount of
    relevance arithmetic reliably does that.

    Everything that is not a letter or digit becomes a separator. This is an
    allowlist on purpose: a blacklist of MySQL's operators
    (+ - < > ~ * parens quotes @) misses others — "%" alone was enough to
    produce "syntax error, unexpected $end" from the FULLTEXT parser, a 500 on
    a query a user could plausibly type. \p{L} keeps non-ASCII letters, so
    "kölsch" and "münchner" still search as single terms.

    An all-punctuation query yields an empty expression, which is left empty
    rather than falling back to the raw string — falling back would feed the
    very operators just stripped straight into BOOLEAN MODE.
    AGAINST('' IN BOOLEAN MODE) matches nothing and raises no error, so the
    natural-language tiers still apply.

    Terms below innodb_ft_min_token_size are dropped by MySQL rather than
    failing the AND, so short words degrade instead of returning nothing.

    The same sanitised terms feed the NATURAL LANGUAGE matches, which are not
    as forgiving as their name suggests: AGAINST('*' IN NATURAL LANGUAGE MODE)
    is a parser error, not an empty result. A bare "*" therefore 500s — a
    pre-existing fault in this endpoint since it shipped, reachable by anyone
    typing a lone asterisk into a search box, and logged as C272 with no
    indication it was user input rather than a broken query.

    Only the exact-match comparisons keep the raw query, since those are string
    equality against canonical names and aliases, where punctuation is
    meaningful and no FULLTEXT parser is involved.

    The term array comes back alongside the two expressions because the ranking
    (nameCovers) and the family/class lookup both work term-by-term, and
    re-splitting the query in three places is how the two would drift.
    --*/
    private function ftTerms($query){
        $boolTerms = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query)), -1, PREG_SPLIT_NO_EMPTY);
        $boolQuery = '';
        foreach($boolTerms as $t){
            $boolQuery .= '+' . $t . ' ';
        }

        return array(trim($boolQuery), implode(' ', $boolTerms), $boolTerms);
    }

    /*--
    nameCovers — 0 when every query term appears as a whole word in the style's
    OWN canonical name, 1 otherwise. A tiebreak, never a filter.

    The all-terms tier is decided against search_name, which is the canonical
    name plus every alias concatenated. That makes it blind to WHERE the terms
    matched, and a style with one long alias can absorb a query it has no claim
    to. "Flanders Red Ale" put oud-bruin above flanders-red-ale because
    oud-bruin carries the alias "Belgian-Style Flanders Oud Bruin or Oud Red
    Ales" — every term present, none of them in its actual name — and then won
    the beer_count tiebreak 178 to 0.

    Word boundaries matter here: a plain LIKE '%ale%' matches "P-ale-Ale" and
    every other style with "ale" inside a longer word, which is the same
    substring fault that got a LIKE pass removed from the family lookup below.
    \b is ICU-aware in MySQL 8+, so it holds for "kölsch" as well as "ale".

    Terms are already sanitised to letters and digits by ftTerms(), so they need
    no regex escaping — there is no metacharacter left to escape.

    An empty term list yields the constant 1: every row equally uncovered, which
    leaves the ordering to the tiebreaks on either side of it.
    --*/
    private function nameCovers($terms){
        if(empty($terms)){
            return array('1', array());
        }

        $conditions = array();
        $params = array();
        foreach($terms as $t){
            $conditions[] = 's.canonical_name REGEXP ?';
            $params[] = '\\b' . $t . '\\b';
        }

        return array('CASE WHEN ' . implode(' AND ', $conditions) . ' THEN 0 ELSE 1 END', $params);
    }

    /*--
    headMatch — 0 when the label's LAST term appears in the style's identity
    terms, 1 otherwise. A tiebreak, never a filter, and only for a head term
    distinctive enough to mean something.

    English beer names are head-final: the modifiers come first and the style
    sits at the end. "Triple IPA" is an IPA, "Double Dry Hop Citra IPA" is an
    IPA, "Wood-Aged Doppelbock" is a doppelbock. A candidate matching only the
    leading modifiers is answering a question about the adjectives. Relevance
    can't express this — MATCH() scores by term rarity and says nothing about
    WHERE in the label a term sat — so "Triple IPA" put belgian-tripel second on
    the strength of "triple" (a rare token, and a false friend), and "Double Dry
    Hop Citra IPA" led with fresh-hop-beer and dry-mead while omitting
    american-ipa entirely. Both labels name their style outright, in the same
    position every English beer name does.

    The same head-final observation already drives the trailing-term backoff in
    suggest(); this applies it to ordering rather than to reformulation, so it
    also holds for labels the backoff never fires on.

    Distinctiveness is measured, not assumed. A head term matching a fifth of
    the taxonomy discriminates nothing and actively harms: "Coconut Ale" would
    promote all 77 styles carrying the word "ale" over the coconut and
    fruit-beer styles that are the actual answer — the same failure this change
    fixes for "Sour Ale". So the tiebreak is dropped unless the head term
    appears in fewer than a fifth of styles. "ipa" (15 of 196), "porter" (7) and
    "doppelbock" (1) qualify; "ale" (77) and "beer" (42) don't.

    A one-term label is skipped outright: its head term is the whole query, so
    every row that matched at all matched it, and the column would be constant.
    Skipping also saves the frequency query on the commonest shape of query.

    search_name rather than canonical_name, because the head term is often an
    abbreviation the canonical name spells out — "IPA" reaches american-ipa
    ("American-Style India Pale Ale") only through its aliases. That is the
    opposite of the choice nameCovers() makes, and deliberately: nameCovers asks
    whether a style OWNS the whole query, where alias absorption is the failure
    mode, while this asks whether one specific word is part of the style's
    identity at all.
    --*/
    private function headMatch($db, $terms){
        if(count($terms) < 2){
            return array('0', array());
        }

        $pattern = '\\b' . end($terms) . '\\b';
        $result = $db->query("SELECT COUNT(*) AS matches, (SELECT COUNT(*) FROM style) AS styles FROM style WHERE search_name REGEXP ?", array($pattern));
        if($db->error || $result === null){
            return array('0', array());
        }

        $row = $result->fetch_assoc();
        if(empty($row) || intval($row['styles']) === 0 || intval($row['matches']) * 5 >= intval($row['styles'])){
            return array('0', array());
        }

        return array('CASE WHEN s.search_name REGEXP ? THEN 0 ELSE 1 END', array($pattern));
    }

    /*--
    rankOrderBy — the shared ordering for search() and suggest().

    Ranking is tiered, not a blended score:

      0  the query IS the style — exact canonical name or exact alias
      1  EVERY query term appears in the style's identity terms
      2  SOME query term appears in its identity terms
      3  a hit only in the editorial description

    Tiers exist because MATCH() relevance cannot carry this weight. Its scores
    come from IDF and document length within one index, so they are incomparable
    across columns and, within a column, are decided by document length — a
    property unrelated to what a searcher meant. Two attempts to rank tier-mates
    by relevance both failed: concatenated aliases ranked styles by how many
    synonyms they had, and deduplicated tokens ranked them by how many distinct
    tokens they had. Neither means anything, so q=ipa surfaced experimental-ipa
    and new-zealand-ipa — used by zero beers — above american-ipa, used by 6,530.

    WITHIN a tier the order is relevance, then name coverage, then popularity.
    beer_count used to come first, justified by "tiers already guarantee
    tier-mates match the query equally well." That holds for tiers 0 and 1. It
    is false for tier 2, which is defined as SOME term matching and therefore
    lumps "matched 2 of 3 terms" in with "matched 1 common word" — and then
    sorted them by popularity. "Crisp American Lager" returned eight consecutive
    ales: every style matching only "american", ordered by beer count, with
    american-lager (the only row matching both real terms, at nearly double the
    relevance) pushed to 9th and off the end of an 8-row suggestion list.
    name_rel first is what separates them, and it costs tiers 0 and 1 nothing,
    since tier-mates there genuinely do tie on relevance.

    beer_count still breaks the remaining ties, and still ranks below tier on
    purpose: promoted above tier it would bury a precisely-matched rare style
    under a popular vague one, which is why "juicy ipa" needs the all-terms tier
    to keep hazy-ipa (13 beers) above american-ipa (6,530).

    Catch-all styles sort below specific ones within a tier, because beer_count
    structurally favours them: they are the buckets beers land in when nothing
    more precise fits, so they accumulate counts no specific style can match.
    specialty-beer holds 1,209 beers and its aliases mention stout, which put it
    second for q=stout — above four actual stouts. A catch-all is a fallback,
    not an answer. This only applies within a tier, so searching for a catch-all
    by name still returns it first on the exact-match tier.

    search_name is canonical_name plus every alias in one document, so "IPA"
    reaches american-ipa even though its name spells out "India Pale Ale" — the
    case the original ranking could never handle, because the token was absent
    from the column carrying the heaviest weight.

    class_conflict is read twice, at two different strengths, and only suggest()
    supplies it — see classConflict(). search() passes the constant 0, leaving
    the ordering otherwise identical between the two.

    head_match sits above relevance because relevance cannot see which term
    matched, and the last term is the one that says what the beer IS — see
    headMatch().
    --*/
    private function rankOrderBy(){
        return 'ORDER BY tier, class_conflict = 2, s.is_catch_all, head_match, name_rel DESC, name_covers, class_conflict, s.beer_count DESC, body_rel DESC, CHAR_LENGTH(s.canonical_name), s.canonical_name';
    }

    /*--
    classConflict — how a style stands relative to the super-class the label
    itself names: 0 agrees, 1 is silent, 2 contradicts.

    "Crisp American Lager" led with eight consecutive ales. Each of them matched
    on "american" and none of them could possibly be right, because the label
    says Lager and the taxonomy already knows that Ale and Lager are disjoint.
    Nothing in a FULLTEXT score can express "this candidate contradicts the
    query"; it is a fact about the vocabulary, not about term statistics, so it
    has to be stated separately.

    Three ranks rather than two, because the middle case is real and large.
    Ciders, meads, seltzers and the wheat/sour families sit outside the
    Ale/Lager split entirely and carry no class. A blank class is "not
    applicable", not "the other one", so those styles must not be demoted with
    the contradictions — but a two-way flag left them tied with genuine class
    matches, and beer_count then floated coffee-beer above american-lager for
    "Crisp Golden Lager". Agreeing with the label should beat saying nothing,
    which should beat contradicting it.

    The three ranks are read at two DIFFERENT strengths, which is why
    rankOrderBy() names this column twice. Contradiction is a hard demotion and
    sorts directly under tier. Agreement over silence is a preference and sorts
    below relevance, because "says nothing about class" is not evidence of a
    worse match and must not outweigh evidence of a better one.

    Ranking silence above relevance is what buried the sours in "Sour Ale with
    Pineapple and Coconut". "Ale" made the class hint, and the sour family
    carries no class, so american-fruited-sour-ale and american-sour-ale — the
    only candidates matching the word the label leads with, at 20 and 40 times
    the relevance — sorted under eight ales whose entire claim was the class
    word itself. Every suggestion for a beer whose label says Sour was an IPA or
    a pale ale. The class hint was doing double duty: naming the class AND
    counting as a match for it. Demoted to a tiebreak it still separates equals,
    which is all "Crisp Golden Lager" ever needed — name_rel had by then been
    lifted above beer_count, and that is what actually keeps coffee-beer down.

    Both readings sort under tier, so a style the caller named outright still
    wins. If a label somehow matches a style exactly AND names the other class,
    the exact match is the better answer and the contradiction is a curiosity.

    Only applied when the terms name exactly ONE class. A label naming both is
    telling us nothing, and a label naming none has nothing to contradict.
    --*/
    private function classConflict($classHint){
        if($classHint === null){
            return array('0', array());
        }

        return array("CASE WHEN p.class = ? THEN 0 WHEN p.class IS NULL OR p.class = '' THEN 1 ELSE 2 END", array($classHint));
    }

    /*--
    suggest — candidate styles for a label that didn't resolve.

    Same ranking as search() (exact name/alias, then all-terms, then any-term,
    with catch-alls sorted below specific styles within a tier), but returning
    compact rows for embedding in someone else's 400 rather than a paginated
    response body. Beer::resolveStyle() calls it so a rejected label comes back
    with the handful of style_id values that would fix it — the API's advice to
    "choose from the list" is fine in the Guided Style Field, which renders a
    list, and useless to an API client, which has none.

    Rows carry only what's needed to build the retry. Specs, aliases and SRM are
    a GET /style/{id} away and would bloat an error body.

    Never sets error state and never throws: this runs while the caller is
    already returning a 400, and a failed suggestion lookup must not turn that
    into a 500. Anything unexpected yields an empty array, and the caller omits
    the key.

    Every row states HOW it matched, because the alternative is a list that
    cannot be read. The rows were previously identical in shape whether the top
    one was an exact alias hit or a style that merely shared the word "ale" with
    the label, which leaves a client no strategy but to trust [0] — and an
    agent-driven client reported doing exactly that and misfiling eight beers.
    An honest "partial" is what tells a caller to fall back to a family, a
    catch-all, or its own GET /style/search. The wrongness was survivable; the
    confidence was not.

    Aliases ride along for the same reason. They are how a caller recognises
    that hazy-ipa is the thing it meant by "NEIPA" without spending a second
    request to find out, and a second request is not free here: unmatched labels
    are a third of some clients' writes, and every call is metered and billed.
    Roughly 340 tokens of aliases against a billed round trip is not a close
    trade. Specs and SRM stay on GET /style/{id}, since nothing about a retry
    depends on colour.
    --*/
    public function suggest($label, $limit = 8){
        $empty = array('styles' => array());

        $label = trim($label ?? '');
        if($label === '' || strlen($label) > 255){
            return $empty;
        }

        // 8 rather than a tighter 5 because relevance clusters flat: every
        // pilsner matching "Cali Pilsner" scores identically, so the order
        // within the cluster falls to beer_count and the best answer
        // (contemporary-american-pilsner) sits 6th behind more populous
        // siblings. A few hundred more bytes in an error body is cheaper than
        // the right style falling off the end.
        $limit = intval($limit);
        if($limit < 1 || $limit > 25){
            $limit = 8;
        }

        $db = new Database();

        // Only the term array is needed here; styleCandidates() re-derives the
        // FULLTEXT expressions for whichever label it is given, which is not
        // always this one once the backoff below runs.
        $terms = $this->ftTerms($label)[2];

        // Resolved before the styles because the class it may name feeds the
        // style ranking (classConflict). Whether these are EMITTED is decided
        // further down, once the styles have shown how well they matched.
        list($families, $classes) = $this->broaderCandidates($db, $terms);
        $classHint = (count($classes) === 1) ? $classes[0]['class'] : null;

        list($rows, $bestTier) = $this->styleCandidates($db, $label, $limit, $classHint);
        if($rows === null){
            $db->close();
            return $empty;
        }

        /*--
        Trailing-term backoff. English style names are head-final — the style
        sits at the end of the label and the marketing sits at the front — so
        when the whole label only ever matches SOME of its terms, the last two
        are the part worth asking about. "Crisp American Lager" matches nothing
        as a whole and resolves exactly as "American Lager"; "Belgian-Style
        Dubbel" reaches belgian-dubbel once "Belgian" is dropped.

        This is the one thing suggest() structurally could not do before, and
        the reason GET /style/search kept beating it on the same data and the
        same ranking: a caller reformulates its query, and suggest() only ever
        saw the label already sent. Doing it server-side is what makes the 400
        answerable without a second call.

        Guarded three ways, because a heuristic that fires too eagerly is worse
        than none. It runs only when the full label failed to reach tier 1, only
        when there are at least three terms (so the truncation is a real
        reduction and not the same query again), and its result is kept only if
        it reaches tier 0 or 1. A backoff that lands in the partial tier is just
        a different pile of noise and is discarded.

        It stops at two terms deliberately. Backing off to one would turn
        "Shandy Ale" into "Ale", which matches half the catalogue at tier 1 and
        would present that as a confident answer — more wrong, and more
        confidently, than what it replaced. Labels whose only usable term is a
        family or class word are the families/classes block's job below.
        --*/
        $matchedOn = null;
        if($bestTier > 1 && count($terms) >= 3){
            $shortLabel = implode(' ', array_slice($terms, -2));
            list($backoffRows, $backoffTier) = $this->styleCandidates($db, $shortLabel, $limit, $classHint);
            if($backoffRows !== null && $backoffTier <= 1){
                $rows = $backoffRows;
                $matchedOn = $shortLabel;
                // $bestTier stays the ORIGINAL label's tier on purpose: it
                // gates the families/classes block, and the fact that a
                // truncated label matched does not mean the submitted one did.
            }
        }

        // Tier 3 matched on description text alone — vienna-lager for "Cali
        // Pilsner", non-alcoholic-beer for "Hazy Juice Bomb". Worth offering
        // when nothing better exists, noise the moment something does. Dropping
        // them can't hide a better candidate: the ORDER BY already placed every
        // lower tier above them.
        $rowTier = 3;
        foreach($rows as $row){
            $rowTier = min($rowTier, intval($row['tier']));
        }

        $styles = array();
        $ids = array();
        foreach($rows as $row){
            if($rowTier < 3 && intval($row['tier']) === 3){
                continue;
            }
            $styles[] = array(
                'style_id' => $row['id'],
                'name' => $row['canonical_name'],
                'parent' => $row['parent'],
                'class' => $row['class'],
                'catch_all' => (bool) $row['is_catch_all'],
                'match' => $this->matchQuality(intval($row['tier'])),
                'aliases' => array(),
            );
            $ids[] = $row['id'];
        }

        $styles = $this->attachAliases($db, $styles, $ids);

        /*--
        Families and classes, offered only when no style matched the submitted
        label outright.

        A beer may be filed at any of the three tiers, so for a label like
        "Crisp American Lager" the honest answer is not a style at all — it is
        the Lager class, or the pale-lager family. The API has accepted those
        writes all along and the 400 never mentioned them, which left a caller
        choosing among eight styles when the correct move was to stop guessing
        and file one tier up.

        An earlier draft of this method dropped families on the grounds that
        resolveStyle() matches a family alias, slug or name (steps 2c and 4)
        before it ever gives up, so any family that could match already had.
        That reasoning holds for the label AS A WHOLE and only for that: it says
        nothing about a family or class term sitting INSIDE a longer label,
        which is the case that reaches here. "Crisp American Lager" never
        resolved as a whole and contains "lager"; that is the gap.

        Gated on the original label's tier so a precise style match isn't buried
        under broader alternatives nobody asked for, and so the extra bytes are
        spent only on the requests that are actually stuck. They were looked up
        further above regardless, since the ranking needs the class either way.
        --*/
        if($bestTier <= 1){
            $families = array();
            $classes = array();
        }

        $db->close();

        $suggestions = array('styles' => $styles);
        if($matchedOn !== null){
            // Named rather than implied: the rows describe a query the caller
            // never sent, and a tier-0 row against a label they didn't write
            // would otherwise read as an exact match on the one they did.
            $suggestions['matched_on'] = $matchedOn;
        }
        if(!empty($families)){
            $suggestions['families'] = $families;
        }
        if(!empty($classes)){
            $suggestions['classes'] = $classes;
        }

        return $suggestions;
    }

    // The ranked style query, run once for the label and again for the backoff.
    // Returns array($rows, $bestTier), or array(null, 3) on any database error —
    // suggest() must degrade to no suggestions, never to a 500.
    private function styleCandidates($db, $label, $limit, $classHint = null){
        list($boolQuery, $nlQuery, $terms) = $this->ftTerms($label);
        list($coversExpr, $coversParams) = $this->nameCovers($terms);
        list($conflictExpr, $conflictParams) = $this->classConflict($classHint);
        list($headExpr, $headParams) = $this->headMatch($db, $terms);

        $sql = "SELECT s.id, s.canonical_name, s.parent, p.class, s.is_catch_all, "
             . "CASE WHEN LOWER(s.canonical_name) = LOWER(?) THEN 0 "
             . "WHEN EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) THEN 0 "
             . "WHEN MATCH(s.search_name) AGAINST(? IN BOOLEAN MODE) > 0 THEN 1 "
             . "WHEN MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) > 0 THEN 2 "
             . "ELSE 3 END AS tier, "
             . $conflictExpr . " AS class_conflict, "
             . $headExpr . " AS head_match, "
             . $coversExpr . " AS name_covers, "
             . "MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel, "
             . "COALESCE(MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE), 0) AS body_rel "
             . "FROM style s LEFT JOIN style_parent p ON s.parent = p.slug LEFT JOIN style_content c ON c.style_id = s.id "
             . "WHERE MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) "
             . "OR MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE) "
             . "OR LOWER(s.canonical_name) = LOWER(?) "
             . "OR EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) "
             . $this->rankOrderBy() . " LIMIT ?";

        $params = array_merge(
            array($label, $label, $boolQuery, $nlQuery),
            $conflictParams,
            $headParams,
            $coversParams,
            array($nlQuery, $nlQuery, $nlQuery, $nlQuery, $label, $label, $limit)
        );

        $result = $db->query($sql, $params);
        if($db->error || $result === null){
            return array(null, 3);
        }

        $rows = array();
        $bestTier = 3;
        while($row = $result->fetch_assoc()){
            $rows[] = $row;
            $bestTier = min($bestTier, intval($row['tier']));
        }

        return array($rows, $bestTier);
    }

    // How a suggestion matched, in the caller's terms rather than the ranking's.
    // 'partial' is the honest label for the any-term tier and the whole point of
    // the field: it is the value that says don't trust position 0.
    private function matchQuality($tier){
        if($tier === 0){ return 'exact'; }
        if($tier === 1){ return 'all_terms'; }
        if($tier === 2){ return 'partial'; }
        return 'description';
    }

    // Aliases for the suggested styles, in one query. Failure is silent and
    // leaves the empty arrays already in place — an alias list is a convenience,
    // and losing it must not cost the caller the suggestions themselves.
    private function attachAliases($db, $styles, $ids){
        if(empty($ids)){
            return $styles;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = $db->query("SELECT style_id, alias FROM style_alias WHERE style_id IN ($placeholders)", $ids);
        if($db->error || $result === null){
            return $styles;
        }

        $byID = array();
        while($row = $result->fetch_assoc()){
            $byID[$row['style_id']][] = $row['alias'];
        }

        foreach($styles as $i => $style){
            foreach(($byID[$style['style_id']] ?? array()) as $alias){
                if(strcasecmp($alias, $style['name']) !== 0){
                    $styles[$i]['aliases'][] = $alias;
                }
            }
        }

        return $styles;
    }

    /*--
    broaderCandidates — families and classes named by the terms inside a label
    that didn't resolve.

    Matching is exact against slug, name and alias, term by term, plus adjacent
    pairs so two-word families ("pale ale", "brown ale") are reachable. Pairs
    are tried in both spellings a caller might read off our own endpoints, since
    GET /style/parent publishes "Pale Ale" while the slug is "pale-ale".

    No substring matching, for the reason recorded in search(): a LIKE '%q%'
    pass returned 11 of the 26 families for q=ale, Pale Lager among them,
    because "ale" sits inside "P-ale". Word-level exactness is what keeps this
    block trustworthy enough to sit in an error body.

    Longest match first: a label naming both a family and a class ("Hazy Pale
    Ale" -> the pale-ale family and the ale class) should lead with the more
    specific of the two, and families precede classes for the same reason.
    --*/
    private function broaderCandidates($db, $terms){
        if(empty($terms)){
            return array(array(), array());
        }

        // Unigrams and adjacent bigrams, bigrams first so a two-word family
        // beats the one-word class it contains.
        $needles = array();
        for($i = 0; $i < count($terms) - 1; $i++){
            $needles[] = strtolower($terms[$i] . ' ' . $terms[$i + 1]);
            $needles[] = strtolower($terms[$i] . '-' . $terms[$i + 1]);
        }
        foreach($terms as $t){
            $needles[] = strtolower($t);
        }
        $needles = array_values(array_unique($needles));

        $placeholders = implode(',', array_fill(0, count($needles), '?'));

        $families = array();
        $result = $db->query("SELECT DISTINCT p.slug, p.name, p.class, p.beverage_type, p.sort_order FROM style_parent p LEFT JOIN parent_alias pa ON pa.parent = p.slug WHERE LOWER(p.slug) IN ($placeholders) OR LOWER(p.name) IN ($placeholders) OR LOWER(pa.alias) IN ($placeholders) ORDER BY p.sort_order", array_merge($needles, $needles, $needles));
        if(!$db->error && $result !== null){
            while($row = $result->fetch_assoc()){
                $families[] = array(
                    'parent' => $row['slug'],
                    'name' => $row['name'],
                    'class' => $row['class'],
                    'beverage_type' => $row['beverage_type'],
                );
            }
        }

        $classes = array();
        $result = $db->query("SELECT DISTINCT c.slug, c.name, c.beverage_type, c.sort_order FROM style_class c LEFT JOIN class_alias ca ON ca.class = c.slug WHERE LOWER(c.slug) IN ($placeholders) OR LOWER(c.name) IN ($placeholders) OR LOWER(ca.alias) IN ($placeholders) ORDER BY c.sort_order", array_merge($needles, $needles, $needles));
        if(!$db->error && $result !== null){
            while($row = $result->fetch_assoc()){
                $classes[] = array(
                    'class' => $row['slug'],
                    'name' => $row['name'],
                    'beverage_type' => $row['beverage_type'],
                );
            }
        }

        return array($families, $classes);
    }

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

        list($boolQuery, $nlQuery, $terms) = $this->ftTerms($query);
        list($coversExpr, $coversParams) = $this->nameCovers($terms);

        // Tiers and the within-tier ordering are documented on rankOrderBy(),
        // which suggest() shares, so a caller that reads a 400's suggestions and
        // then reruns the label here gets the same answer to the same question.
        //
        // The one deliberate difference is class_conflict, held at 0 here.
        // Demoting the other super-class suits a caller trying to file ONE beer
        // under a label that names its class; it does not suit someone browsing
        // the vocabulary, where q=lager legitimately surfaces ales named "lager"
        // and hiding them would be the endpoint answering a question nobody
        // asked. Suggestion needs an opinion; search needs completeness.
        $db = new Database();
        list($headExpr, $headParams) = $this->headMatch($db, $terms);
        $sql = "SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.class, s.is_catch_all, s.srm_min, s.srm_max, "
             . "CASE WHEN LOWER(s.canonical_name) = LOWER(?) THEN 0 "
             . "WHEN EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) THEN 0 "
             . "WHEN MATCH(s.search_name) AGAINST(? IN BOOLEAN MODE) > 0 THEN 1 "
             . "WHEN MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) > 0 THEN 2 "
             . "ELSE 3 END AS tier, "
             . "0 AS class_conflict, "
             . $headExpr . " AS head_match, "
             . $coversExpr . " AS name_covers, "
             . "MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) AS name_rel, "
             . "COALESCE(MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE), 0) AS body_rel "
             . "FROM style s LEFT JOIN style_parent p ON s.parent = p.slug LEFT JOIN style_content c ON c.style_id = s.id "
             . "WHERE MATCH(s.search_name) AGAINST(? IN NATURAL LANGUAGE MODE) "
             . "OR MATCH(c.description) AGAINST(? IN NATURAL LANGUAGE MODE) "
             . "OR LOWER(s.canonical_name) = LOWER(?) "
             . "OR EXISTS (SELECT 1 FROM style_alias x WHERE x.style_id = s.id AND LOWER(x.alias) = LOWER(?)) "
             . $this->rankOrderBy() . " LIMIT ?, ?";
        $params = array_merge(
            array($query, $query, $boolQuery, $nlQuery),
            $headParams,
            $coversParams,
            array($nlQuery, $nlQuery, $nlQuery, $nlQuery, $query, $query, $offset, $fetchCount)
        );
        $result = $db->query($sql, $params);
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
            // Cross-type tie-break — a style beats the many beers named after
            // it ("Kölsch"). See customRanking in algolia/settings.php.
            $array['type_rank'] = 30;
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
