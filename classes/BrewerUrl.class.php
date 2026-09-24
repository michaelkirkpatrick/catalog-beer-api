<?php
/*--
BrewerUrl — the one place a brewer's URL is reduced to its host and its page,
and the one test of whether a URL collides with a brewer already held.

The rule: ONE BREWER PER HOMEPAGE, NOT ONE PER DOMAIN. A domain's root URL
belongs to at most one brewer, no two brewers may hold the same page, and any
number may hold distinct sub-pages of one domain. That is what lets an
acquired brand that carries on under its own name live on a page of its
owner's site (Whetstone Beer Co. at https://northchair.com/whetstone-beer/)
beside the owner's own record at the root. brewer.domainName is therefore
indexed but not unique; brewer.url stays UNIQUE as the byte-identical
backstop, so every normalised comparison has to happen here, before the
INSERT.

  host($url)     the domainName rule: host, lowercased, www. stripped
  pageKey($url)  host + normalised path (+ query), what "the same page" means
  isRoot($url)   the page is the homepage
  conflict()     the brewer this URL collides with, or null

Sharing a domain shares staff, by design: brewery-staff rights come from an
email-domain match against domainName (Privileges::isBreweryStaff and the
gates in Brewer/Beer/Location/USAddresses), so an @owner.com address is
staff on every record that stores owner.com — the acquirer managing its
acquisition. Nothing here touches permissions.

Offline regression test: php tests/brewer-url.php
--*/

class BrewerUrl {

    /*--
    The domainName rule. A scheme is prefixed when missing so a raw, not yet
    validated URL ("northchair.com") still yields its host — Brewer::add()
    needs the host of the submitted URL for its permissions decision before
    validateURL() has run. Returns null when no host can be read.
    --*/
    public static function host($url){
        $url = trim($url ?? '');
        if($url === ''){
            return null;
        }
        if(!preg_match('/^https?:\/\//i', $url)){
            $url = 'http://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if(empty($host) || !preg_match('/[a-zA-Z0-9.-]+/', $host, $m)){
            return null;
        }
        $host = strtolower($m[0]);
        if(substr($host, 0, 4) === 'www.'){
            $host = substr($host, 4);
        }
        return $host === '' ? null : $host;
    }

    /*--
    The path part of the page key: '' for the homepage. Trailing slash
    dropped, /index.html|htm|php collapsed to the root (static and
    Squarespace sites emit it), compared case-insensitively — a false
    refusal costs the user a contact link, a false pass creates a duplicate
    record, so lean strict. The query string is kept: a WordPress ?p= or
    ?page_id= page is a real, distinct page. The fragment never reaches the
    server and is dropped. The port is ignored.
    --*/
    public static function path($url){
        $url = trim($url ?? '');
        if($url === ''){
            return '';
        }
        if(!preg_match('/^https?:\/\//i', $url)){
            $url = 'http://' . $url;
        }
        $parts = parse_url($url);
        if($parts === false){
            return '';
        }
        $path = strtolower(rtrim($parts['path'] ?? '', '/'));
        if(preg_match('#^(/index\.(html?|php))?$#', $path)){
            $path = '';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . strtolower($parts['query']) : '';
        if($path === '' && $query !== ''){
            $path = '/';
        }
        return $path . $query;
    }

    public static function pageKey($url){
        $host = self::host($url);
        if(is_null($host)){
            return null;
        }
        return $host . self::path($url);
    }

    public static function isRoot($url){
        return !is_null(self::host($url)) && self::path($url) === '';
    }

    /*--
    The brewer $url collides with, or null. Every row on the host is
    classified in PHP: with a shared domain the old num_rows == 1 test would
    have waved a third root record through once two rows shared a host.

    Brewer rule ($rootClaimsPages = false, POST/PUT/PATCH /brewer): a root
    conflicts only with another root, a page only with the same page.

    Lead rule ($rootClaimsPages = true, POST /brewer-lead): additionally, a
    brewer holding the root of the host owns every page on it — a lead for
    alpha.example/about is Alpha, review it rather than queue it. A lead for
    the root while only sub-page brewers hold the host is a genuine lead
    (the owner of an acquired brand). The root holder is returned in
    preference to a page match so the 409 names the right brewer.

    Returns the row (id, name, url) plus 'match' => 'root' | 'page'. Sets
    nothing on error: the caller checks $db->error.
    --*/
    public static function conflict(Database $db, $url, $excludeBrewerID = null, $rootClaimsPages = false){
        $host = self::host($url);
        if(is_null($host)){
            return null;
        }
        $key = self::pageKey($url);
        $root = self::isRoot($url);

        $sql = "SELECT id, name, url FROM brewer WHERE domainName=?";
        $params = array($host);
        if(!empty($excludeBrewerID)){
            $sql .= " AND id<>?";
            $params[] = $excludeBrewerID;
        }
        $result = $db->query($sql, $params);
        if($db->error || !$result){
            return null;
        }

        $rootHolder = null;
        $pageMatch = null;
        while($row = $result->fetch_assoc()){
            $rowRoot = self::isRoot($row['url']);
            if($rowRoot && ($root || $rootClaimsPages)){
                $row['match'] = 'root';
                $rootHolder = $row;
            }elseif(!$rowRoot && self::pageKey($row['url']) === $key){
                $row['match'] = 'page';
                $pageMatch = $row;
            }
        }
        return $rootHolder ?? $pageMatch;
    }
}
?>
