<?php
/* UrlCheck.class.php
   Classifies a brewer URL's health for the check-urls cron.

   Detection is tiered:
   1. DNS — NXDOMAIN is the only unambiguous "gone" signal
   2. HTTP fetch — status-code classes per the URL-verification design doc
   3. Off-domain redirect detection — registrable-domain comparison with
      same-entity filtering (www/hyphen/TLD variants, brand-token survival)
   4. Parked-page content heuristics — parking-service fingerprints, then
      tiny bodies (skipped when the brewery name appears in the page, since
      JS-rendered sites ship near-empty HTML shells)
   5. Optional enrichment — RDAP registration date, Claude classification

   Statuses returned by check():
   ok | moved | parked | blocked | url_wrong | server_error | no_answer | gone
*/

class UrlCheck {

    // WAFs increasingly block requests with bot/datacenter fingerprints, so a
    // bare curl UA misclassifies live sites as dead (thorn.beer returns 403 to
    // bots, 200 to browsers). Always present as a browser.
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    const CONNECT_TIMEOUT = 10;
    const TIMEOUT = 30;
    const MAX_REDIRECTS = 10;
    const MAX_BODY_BYTES = 307200;      // 300 KB is plenty for content heuristics
    const TINY_BODY_BYTES = 600;        // parked landers are usually near-empty

    // The write-path probe only needs a status code, and a user is waiting on
    // it — so cap the body hard and give up sooner than the cron does.
    const PROBE_BODY_BYTES = 8192;
    const PROBE_TIMEOUT = 10;

    // Any of these means the server answered — the site exists. Never a failure.
    // 405 matters for the write-path probe, which tries HEAD before GET.
    const BLOCKED_CODES = array(401, 403, 405, 406, 418, 429, 451);

    // Transient. 521/526 are Cloudflare (origin down / bad origin cert) —
    // Cloudflare answered, so the domain is real.
    const SERVER_ERROR_CODES = array(500, 502, 503, 504, 521, 526);

    // The only codes that say something about the URL rather than the server:
    // this host is fine, that path isn't. The write gate rejects on these.
    const URL_WRONG_CODES = array(404, 410);

    // Case-insensitive body fingerprints of parking/placeholder pages.
    const PARKING_FINGERPRINTS = array(
        'sedoparking.com',
        'afternic.com',
        'hugedomains.com',
        '//dan.com',
        'parkingcrew',
        'bodis.com',
        'domainmarket.com',
        'dnparking',
        'godaddy.com/domainsearch',
        'location.href="/lander"',
        'this domain may be for sale',
        'domain is for sale',
        'domain for sale',
        'buy this domain',
        'under construction',
        'account suspended',
        'welcome to nginx',
        'apache2 ubuntu default page',
        'apache2 debian default page',
        'iis windows server',
        'plesk default page',
        'cpanel default page'
    );

    // Curated subset of multi-part public suffixes seen in the catalog. Not
    // the full Public Suffix List — good enough for registrable-domain
    // comparison across this dataset's TLDs.
    const MULTI_PART_TLDS = array(
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz',
        'com.br', 'net.br', 'com.mx', 'com.ar', 'com.co', 'com.pe',
        'co.jp', 'ne.jp', 'or.jp', 'co.kr',
        'com.cn', 'com.tw', 'com.hk', 'com.sg', 'com.my', 'com.ph', 'com.vn',
        'co.in', 'co.za', 'co.il', 'com.tr', 'com.ua', 'com.pl'
    );

    // Words too generic to identify a brewery when matching brand tokens
    // against domains and page text.
    const GENERIC_NAME_WORDS = array(
        'brewing', 'brewery', 'breweries', 'brewers', 'brewer', 'brew',
        'brewhouse', 'brewpub', 'beer', 'beers', 'ales', 'ale', 'cider',
        'cidery', 'company', 'co', 'the', 'and', 'of', 'inc', 'llc', 'ltd',
        'taproom', 'tap', 'house', 'works', 'craft'
    );

    /* ----- Main classifier ----- */

    // Returns an associative array:
    //   status       ok|moved|parked|blocked|url_wrong|server_error|no_answer|gone
    //   detail       human-readable reason
    //   http_code    final HTTP status (0 if no response)
    //   final_url    URL after following redirects (null if none)
    //   name_found   true/false/null — brewery name present in page text
    //                (null = no response body, or name too generic to judge)
    //   title        page <title> (2xx responses only)
    //   text_excerpt stripped page text, first ~4000 chars (2xx responses only)
    public function check(string $url, string $brewerName): array {
        $result = array(
            'status' => '',
            'detail' => '',
            'http_code' => 0,
            'final_url' => null,
            'name_found' => null,
            'title' => '',
            'text_excerpt' => ''
        );

        $host = parse_url($url, PHP_URL_HOST);
        if(empty($host)){
            $result['status'] = 'no_answer';
            $result['detail'] = 'Unparseable URL';
            return $result;
        }
        $origDomain = $this->registrableDomain($host);

        // Tier 1: DNS
        if(!$this->dnsResolves($host)){
            $result['status'] = 'gone';
            $result['detail'] = 'DNS did not resolve (NXDOMAIN or no A/AAAA/CNAME records)';
            return $result;
        }

        // Tier 2: HTTP fetch
        $fetch = $this->fetch($url);
        $result['http_code'] = $fetch['http_code'];
        if($fetch['errno'] !== 0 || $fetch['http_code'] === 0){
            // Timeouts, refused connections, and TLS failures all land here.
            // A TLS handshake failure means a server is listening and
            // misconfigured — a broken site, not a dead brewery.
            $result['status'] = 'no_answer';
            $result['detail'] = 'No HTTP response: ' . ($fetch['error'] ?: 'unknown cURL error');
            return $result;
        }

        $httpCode = $fetch['http_code'];
        $finalUrl = $fetch['final_url'];
        if($finalUrl !== $url){
            $result['final_url'] = $finalUrl;
        }

        if(in_array($httpCode, self::BLOCKED_CODES)){
            $result['status'] = 'blocked';
            $result['detail'] = "HTTP $httpCode — site exists but declines automated requests";
            return $result;
        }

        if(in_array($httpCode, self::SERVER_ERROR_CODES)){
            $result['status'] = 'server_error';
            $result['detail'] = "HTTP $httpCode — likely transient";
            return $result;
        }

        if(in_array($httpCode, array(404, 410))){
            // The path is dead; the domain may be healthy. Re-test the apex
            // before concluding anything (jpilarwines.com/jamul-brewing-co/
            // may die while jpilarwines.com thrives).
            $result['status'] = 'url_wrong';
            $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
            $apexUrl = $scheme . '://' . $origDomain . '/';
            $path = parse_url($url, PHP_URL_PATH);
            if(!empty($path) && $path !== '/'){
                $apex = $this->fetch($apexUrl);
                if($apex['errno'] === 0 && $apex['http_code'] >= 200 && $apex['http_code'] < 400){
                    $result['detail'] = "HTTP $httpCode on stored path, but apex $origDomain is alive (HTTP " . $apex['http_code'] . ') — the path moved';
                }else{
                    $result['detail'] = "HTTP $httpCode on stored path; apex $origDomain also failing (HTTP " . $apex['http_code'] . ')';
                }
            }else{
                $result['detail'] = "HTTP $httpCode at the domain root";
            }
            return $result;
        }

        if($httpCode >= 200 && $httpCode < 400){
            // Includes 304 and any redirect chain that ended 2xx.
            $body = $fetch['body'];
            $result['title'] = $this->extractTitle($body);
            $result['text_excerpt'] = $this->extractText($body);
            $result['name_found'] = $this->nameInText($brewerName, $result['title'] . ' ' . $result['text_excerpt']);

            // Tier 3: off-domain redirect — the strongest spam-under-brewery-
            // name detector. A lapsed domain bought by an aggregator returns
            // 200 while sending users somewhere unrelated.
            $finalHost = parse_url($finalUrl, PHP_URL_HOST);
            $finalDomain = !empty($finalHost) ? $this->registrableDomain($finalHost) : $origDomain;
            if($finalDomain !== $origDomain && !$this->sameEntity($origDomain, $finalDomain, $brewerName)){
                $result['status'] = 'moved';
                $result['detail'] = "Redirects off-domain: $origDomain → $finalDomain";
                return $result;
            }

            // Tier 4: parked-page content heuristics
            $parkedReason = $this->parkedReason($body, $result['name_found']);
            if($parkedReason !== null){
                $result['status'] = 'parked';
                $result['detail'] = "HTTP $httpCode but page looks parked ($parkedReason)";
                return $result;
            }

            $result['status'] = 'ok';
            if($result['name_found'] === false){
                // Not flagged — JS-rendered sites legitimately fail this test.
                // The cron surfaces these as ambiguous for the optional
                // Claude tier instead.
                $result['detail'] = 'Alive, but brewery name not found in page text';
            }else{
                $result['detail'] = "HTTP $httpCode";
            }
            return $result;
        }

        // Any other HTTP response (unexpected 3xx/4xx): the server answered,
        // so the site exists — treat like blocked rather than a failure.
        $result['status'] = 'blocked';
        $result['detail'] = "Unexpected HTTP $httpCode";
        return $result;
    }

    /* ----- Tier 1: DNS ----- */

    private function dnsResolves(string $host): bool {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        if(!empty($records)){
            return true;
        }
        // Some apex domains only publish records on www
        if(strpos($host, 'www.') !== 0){
            $records = @dns_get_record('www.' . $host, DNS_A | DNS_AAAA | DNS_CNAME);
            if(!empty($records)){
                return true;
            }
        }
        return false;
    }

    /* ----- Tier 2: HTTP ----- */

    // The single outbound-HTTP implementation for URL health in this codebase:
    // the check-urls cron and the write-path validator in Brewer/Location both
    // come through here, so they present identically to the sites they hit.
    public function fetch(string $url, string $method = 'GET', int $maxBytes = self::MAX_BODY_BYTES, int $timeout = self::TIMEOUT): array {
        $body = '';
        $capped = false;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9'
            ],
            CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$body, &$capped, $maxBytes) {
                $body .= $chunk;
                if(strlen($body) > $maxBytes){
                    $capped = true;
                    return -1; // abort the transfer — we have enough
                }
                return strlen($chunk);
            }
        ];

        if($method === 'HEAD'){
            $options[CURLOPT_NOBODY] = true;
        }

        $curl = curl_init();
        curl_setopt_array($curl, $options);

        curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $finalUrl = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);

        // Our deliberate body-cap abort surfaces as CURLE_WRITE_ERROR (23)
        if($capped && $errno === CURLE_WRITE_ERROR){
            $errno = 0;
            $error = '';
        }

        return array(
            'body' => $body,
            'http_code' => intval($httpCode),
            'final_url' => $finalUrl ?: $url,
            'errno' => $errno,
            'error' => $error
        );
    }

    /* ----- Write-path reachability (Brewer/Location validateURL) ----- */

    // Any HTTP status code at all means something is listening on that
    // hostname and formed a reply — the site exists. A refusal (403/429), an
    // outage (500/503) and a broken CDN origin (521/526) are all answers, and
    // none of them are evidence the submitted URL is wrong. So the write gate
    // rejects only two things: a transport failure (DNS, TLS, refused,
    // timeout), and 404/410, the codes that specifically mean "not this path".
    // Everything else is accepted and left to the check-urls cron, which can
    // re-test later and judge on more than one sample.
    public function answered(array $probe): bool {
        if($probe['errno'] || $probe['http_code'] <= 0){
            return false;
        }
        return !in_array($probe['http_code'], self::URL_WRONG_CODES);
    }

    // Stricter than answered(): is the host actually serving this scheme? A
    // deliberate refusal still counts (the TLS handshake and request completed
    // — we just weren't welcome), but a 5xx does not. Used only for the
    // http→https upgrade, where the wrong answer means storing a broken URL.
    public function serving(array $probe): bool {
        if($probe['errno']){
            return false;
        }
        if($probe['http_code'] >= 200 && $probe['http_code'] < 400){
            return true;
        }
        return in_array($probe['http_code'], self::BLOCKED_CODES);
    }

    // HEAD first because it's cheap, GET on failure because plenty of CDNs and
    // WAFs refuse HEAD outright. Used to gate writes, so it must not reject a
    // correct URL just because the far end dislikes our IP or is having a bad
    // afternoon. Retries on anything short of a page we could actually use.
    public function reachable(string $url): array {
        $probe = $this->fetch($url, 'HEAD', self::PROBE_BODY_BYTES, self::PROBE_TIMEOUT);
        if(!$this->serving($probe)){
            $probe = $this->fetch($url, 'GET', self::PROBE_BODY_BYTES, self::PROBE_TIMEOUT);
        }
        return $probe;
    }

    // The provably-safe https promotion: the stored URL is http://, and the
    // site's own redirect landed on https:// at the same host (www aside) —
    // the operator has declared https canonical, we're just recording it.
    // Same host means domainName, and with it staff permissions, can't shift.
    // Anything broader (new path, new host) stays report-only. Returns the
    // URL to promote to, or null.
    public function httpsUpgrade(string $storedUrl, string $finalUrl): ?string {
        if(stripos($storedUrl, 'http://') !== 0 || stripos($finalUrl, 'https://') !== 0){
            return null;
        }
        if(strlen($finalUrl) > 255){
            return null;
        }
        $storedHost = parse_url($storedUrl, PHP_URL_HOST);
        $finalHost = parse_url($finalUrl, PHP_URL_HOST);
        if(!is_string($storedHost) || !is_string($finalHost) || $storedHost === '' || $finalHost === ''){
            return null;
        }
        $storedHost = preg_replace('/^www\./', '', strtolower($storedHost));
        $finalHost = preg_replace('/^www\./', '', strtolower($finalHost));
        if($storedHost !== $finalHost){
            return null;
        }
        return $finalUrl;
    }

    /* ----- Tier 3: registrable-domain comparison ----- */

    public function registrableDomain(string $host): string {
        $host = strtolower(rtrim($host, '.'));
        // Bare IPs have no registrable domain
        if(filter_var($host, FILTER_VALIDATE_IP)){
            return $host;
        }
        $labels = explode('.', $host);
        $count = count($labels);
        if($count <= 2){
            return $host;
        }
        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        if(in_array($lastTwo, self::MULTI_PART_TLDS)){
            return $labels[$count - 3] . '.' . $lastTwo;
        }
        return $lastTwo;
    }

    // Do these two registrable domains belong to the same entity?
    // midnight-brewery.com ≡ midnightbrewery.com (alnum-stripped compare);
    // temperancebeer.com ≡ temperance.beer (label containment); and a
    // redirect target that carries the brewery's brand tokens is a rebrand,
    // not a hijack.
    public function sameEntity(string $origDomain, string $finalDomain, string $brewerName): bool {
        $a = $this->domainLabel($origDomain);
        $b = $this->domainLabel($finalDomain);
        if($a !== '' && $a === $b){
            return true;
        }
        if(strlen($a) >= 5 && strlen($b) >= 5 && (strpos($a, $b) !== false || strpos($b, $a) !== false)){
            return true;
        }
        foreach($this->brandTokens($brewerName) as $token){
            if(strpos($b, $token) !== false){
                return true;
            }
        }
        return false;
    }

    // The registrable domain minus its public suffix, alnum only:
    // "midnight-brewery.com" → "midnightbrewery"
    private function domainLabel(string $registrableDomain): string {
        $label = explode('.', $registrableDomain)[0];
        return preg_replace('/[^a-z0-9]/', '', strtolower($label));
    }

    // Distinctive lowercase tokens from a brewery name — generic beer-industry
    // words and short words removed. Empty array = name too generic to judge.
    private function brandTokens(string $name): array {
        preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches);
        $tokens = array();
        foreach($matches[0] as $token){
            $token = strtolower($token);
            if(strlen($token) >= 4 && !in_array($token, self::GENERIC_NAME_WORDS)){
                $tokens[] = $token;
            }
        }
        return $tokens;
    }

    /* ----- Tier 4: parked-page content heuristics ----- */

    private function parkedReason(string $body, ?bool $nameFound): ?string {
        foreach(self::PARKING_FINGERPRINTS as $fingerprint){
            if(stripos($body, $fingerprint) !== false){
                return "fingerprint: $fingerprint";
            }
        }
        // A tiny body usually means a parked lander, but JS-rendered sites
        // ship near-empty HTML shells too — a shell that names the brewery
        // (title/meta) is a real site, so only unnamed tiny bodies count.
        if($nameFound !== true && strlen(trim($body)) < self::TINY_BODY_BYTES){
            return 'body under ' . self::TINY_BODY_BYTES . ' bytes';
        }
        return null;
    }

    // true = a distinctive name token appears; false = none do; null = the
    // name has no distinctive tokens (e.g. "The Brew Co") or no text to search
    private function nameInText(string $brewerName, string $text): ?bool {
        if(trim($text) === ''){
            return null;
        }
        $tokens = $this->brandTokens($brewerName);
        if(empty($tokens)){
            return null;
        }
        foreach($tokens as $token){
            if(stripos($text, $token) !== false){
                return true;
            }
        }
        return false;
    }

    private function extractTitle(string $body): string {
        if(preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $match)){
            return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
        }
        return '';
    }

    // Visible page text: scripts/styles removed, tags stripped, whitespace
    // collapsed, capped for LLM context
    private function extractText(string $body): string {
        $text = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $body);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text);
        return trim(substr($text, 0, 4000));
    }

    /* ----- Tier 5a: RDAP enrichment ----- */

    // Registration date of a domain via RDAP (free JSON over HTTPS, no auth).
    // A registration date after the brewer record was created means the
    // domain lapsed and was re-registered by someone else — the strongest
    // hijack signal available. Returns 'YYYY-MM-DD' or null.
    public function rdapRegistrationDate(string $domain): ?string {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://rdap.org/domain/' . rawurlencode($domain),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: application/rdap+json']
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if($httpCode !== 200 || empty($response)){
            return null;
        }
        $decoded = json_decode($response, true);
        if(!isset($decoded['events']) || !is_array($decoded['events'])){
            return null;
        }
        foreach($decoded['events'] as $event){
            if(($event['eventAction'] ?? '') === 'registration' && !empty($event['eventDate'])){
                return substr($event['eventDate'], 0, 10);
            }
        }
        return null;
    }

    /* ----- Tier 5b: Claude classification ----- */

    // Asks Claude whether a page that passed (or ambiguously passed) the
    // deterministic tiers is really the brewery's site. Advisory only — the
    // cron never writes this verdict to the database.
    // Returns ['verdict' => brewery_site|parked|unrelated_business|spam|unclear,
    //          'reason' => string] or null on any failure.
    public function classifyWithClaude(string $brewerName, string $storedUrl, string $finalUrl, string $title, string $textExcerpt): ?array {
        if(!defined('ANTHROPIC_API_KEY') || empty(ANTHROPIC_API_KEY)){
            return null;
        }

        $systemPrompt = 'You classify web pages for Catalog.beer, a beer database, as part of a link-health monitor. '
            . 'Breweries sometimes lose their domains: squatters, parking services, and unrelated businesses take over '
            . 'lapsed domains and serve pages that return HTTP 200 under the brewery\'s stored URL. '
            . 'Given a brewery\'s name, its stored URL, the final URL after redirects, and the page\'s title and visible text, '
            . 'decide what the page is. Verdicts: '
            . '"brewery_site" (the brewery\'s own site, or clearly about this brewery — e.g. its parent company), '
            . '"parked" (domain parking, for-sale lander, registrar or server placeholder), '
            . '"unrelated_business" (a real but different business), '
            . '"spam" (counterfeit goods, gambling, link farms, scam content), '
            . '"unclear" (not enough signal to judge). '
            . 'Keep the reason to one sentence.';

        $userMessage = "Brewery name: $brewerName\n"
            . "Stored URL: $storedUrl\n"
            . "Final URL after redirects: $finalUrl\n"
            . "Page title: " . ($title !== '' ? $title : '(none)') . "\n\n"
            . "Visible page text (truncated):\n" . ($textExcerpt !== '' ? $textExcerpt : '(empty)');

        $requestBody = json_encode([
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'verdict' => [
                                'type' => 'string',
                                'enum' => ['brewery_site', 'parked', 'unrelated_business', 'spam', 'unclear']
                            ],
                            'reason' => ['type' => 'string']
                        ],
                        'required' => ['verdict', 'reason'],
                        'additionalProperties' => false
                    ]
                ]
            ],
            'messages' => [
                ['role' => 'user', 'content' => $userMessage]
            ]
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => $requestBody
        ]);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if($curlError){
            $errorLog = new LogError();
            $errorLog->errorNumber = '295';
            $errorLog->errorMsg = 'Claude API cURL error';
            $errorLog->badData = $curlError;
            $errorLog->filename = 'API / UrlCheck.class.php';
            $errorLog->write();
            return null;
        }
        if($httpCode !== 200){
            $errorLog = new LogError();
            $errorLog->errorNumber = '296';
            $errorLog->errorMsg = 'Claude API non-200 response';
            $errorLog->badData = "HTTP $httpCode: " . substr($response, 0, 500);
            $errorLog->filename = 'API / UrlCheck.class.php';
            $errorLog->write();
            return null;
        }

        $decoded = json_decode($response, true);
        if(($decoded['stop_reason'] ?? '') === 'refusal'){
            return null;
        }
        // Find the text block (don't assume it's content[0])
        foreach($decoded['content'] ?? array() as $block){
            if(($block['type'] ?? '') === 'text'){
                $verdict = json_decode($block['text'], true);
                if(isset($verdict['verdict']) && isset($verdict['reason'])){
                    return $verdict;
                }
            }
        }
        return null;
    }
}
?>
