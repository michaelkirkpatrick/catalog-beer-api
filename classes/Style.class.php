<?php
class Style {

    // API Response
    public $error = false;
    public $errorMsg = null;
    public $responseHeader = '';
    public $responseCode = 200;
    public $json = array();

    /*---
    GET https://api.catalog.beer/style              — list all canonical styles (+ version)
    GET https://api.catalog.beer/style/{slug}       — one style with full detail
    GET https://api.catalog.beer/style/parent       — the parent groupings

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
            }elseif($function === 'approx'){
                $this->listApprox();
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

        // Styles in display order
        $styles = array();
        $order = array();
        $result = $db->query("SELECT id, canonical_name, beverage_type, parent, category, family, is_catch_all FROM style ORDER BY sort_order");
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
                'category' => $row['category'],
                'family' => $row['family'],
                'catch_all' => (bool) $row['is_catch_all'],
                'aliases' => array(),
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

    // GET /style/{slug} — one style with full detail
    private function getStyle($id){
        $db = new Database();
        $result = $db->query("SELECT s.id, s.canonical_name, s.beverage_type, s.parent, p.name AS parent_name, s.category, s.family, s.yeast_type, s.source, s.is_catch_all, s.abv_min, s.abv_max, s.ibu_min, s.ibu_max, s.srm_min, s.srm_max, s.og_min, s.og_max, s.fg_min, s.fg_max FROM style s LEFT JOIN style_parent p ON s.parent = p.slug WHERE s.id=?", [$id]);
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
            'parent_name' => $row['parent_name'],
            'category' => $row['category'],
            'family' => $row['family'],
            'yeast_type' => $row['yeast_type'],
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

    // GET /style/approx — manual-approx best-fit suggestions (alias -> style_id).
    // Powers the Guided Style Field's "Closest match" (Approx) tier. These live in
    // style_alias_approx, deliberately NOT in style_alias, so they never auto-resolve
    // a beer in the write-path or backfill.
    private function listApprox(){
        $db = new Database();
        $result = $db->query("SELECT a.alias, a.style_id, s.canonical_name, s.parent FROM style_alias_approx a JOIN style s ON a.style_id = s.id ORDER BY a.alias");
        if($db->error){ $this->dbError($db->errorMsg, $db->responseCode); $db->close(); return; }
        $data = array();
        while($row = $result->fetch_assoc()){
            $data[] = array(
                'alias' => $row['alias'],
                'style_id' => $row['style_id'],
                'name' => $row['canonical_name'],
                'parent' => $row['parent'],
            );
        }
        $db->close();
        $this->json['object'] = 'list';
        $this->json['url'] = '/style/approx';
        $this->json['has_more'] = false;
        $this->json['data'] = $data;
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
