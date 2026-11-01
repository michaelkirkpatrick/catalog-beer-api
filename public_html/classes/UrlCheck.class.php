<?php
/* UrlCheck.class.php
   The write gate on brewer.url: is anything listening at the submitted
   address, and which of its redirects is safe to adopt? Called from
   Brewer::validateURL().

   Until 2026-11-01 this class also classified URL health for the nightly
   check-urls cron (DNS, parked-page heuristics, RDAP, a Claude verdict); that
   job moved to the review service's URL audit and the classifier went with
   the cron. See git history before that date.
*/

class UrlCheck {

    // WAFs increasingly block requests with bot/datacenter fingerprints, so a
    // bare curl UA misclassifies live sites as dead (thorn.beer returns 403 to
    // bots, 200 to browsers). Always present as a browser.
    const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    const CONNECT_TIMEOUT = 10;
    const MAX_REDIRECTS = 10;

    // The probe only needs a status code, and a user is waiting on it — so
    // cap the body hard and give up quickly.
    const PROBE_BODY_BYTES = 8192;
    const PROBE_TIMEOUT = 10;

    // Any of these means the server answered — the site exists. Never a failure.
    // 405 matters because the probe tries HEAD before GET.
    const BLOCKED_CODES = array(401, 403, 405, 406, 418, 429, 451);

    // The only codes that say something about the URL rather than the server:
    // this host is fine, that path isn't. The write gate rejects on these.
    const URL_WRONG_CODES = array(404, 410);

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

    /* ----- HTTP ----- */

    public function fetch(string $url, string $method = 'GET', int $maxBytes = self::PROBE_BODY_BYTES, int $timeout = self::PROBE_TIMEOUT): array {
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

    /* ----- Write-path reachability (Brewer::validateURL) ----- */

    // Any HTTP status code at all means something is listening on that
    // hostname and formed a reply — the site exists. A refusal (403/429), an
    // outage (500/503) and a broken CDN origin (521/526) are all answers, and
    // none of them are evidence the submitted URL is wrong. So the write gate
    // rejects only two things: a transport failure (DNS, TLS, refused,
    // timeout), and 404/410, the codes that specifically mean "not this path".
    // Everything else is accepted; whether the site is still the brewery's is
    // the review service's URL audit to judge, on more than one sample.
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

    // What to store from a write-path probe: the submitted URL, with only
    // provable canonicalisation adopted from where its redirects landed. An
    // age gate, cookie wall or login bounce answers 200 from its own path,
    // so the redirect target is a function of our cookie jar, not the
    // brewery's address — and once stored it can't be fixed from outside,
    // because re-validating a PATCH just follows the same redirect
    // (unitedbreweries.com/age-gate/1, octopibrewing.com/verify?destination=
    // node/1, zywiec.com.pl/bramka/). Adopted, component by component:
    //  - nothing, if the redirect left the registrable domain — a move
    //    off-domain is a human's call, not a silent write (and
    //    it keeps domainName, so staff permissions, anchored to what was
    //    submitted rather than to wherever a lapsed domain now points)
    //  - host: www <-> apex normalisation only, never another subdomain
    //  - scheme: http -> https upgrade only, and only when the host was
    //    adopted — a redirect to https on some other subdomain doesn't
    //    prove the submitted host serves TLS
    //  - path: trailing-slash normalisation only, and never a query string
    public function adoptFinalUrl(string $submittedUrl, string $finalUrl): string {
        if($finalUrl === '' || $finalUrl === $submittedUrl){
            return $submittedUrl;
        }

        $sub = parse_url($submittedUrl);
        $fin = parse_url($finalUrl);
        if(empty($sub['host']) || empty($fin['host'])){
            return $submittedUrl;
        }

        $subHost = strtolower($sub['host']);
        $finHost = strtolower($fin['host']);
        if($this->registrableDomain($subHost) !== $this->registrableDomain($finHost)){
            return $submittedUrl;
        }

        $scheme = strtolower($sub['scheme'] ?? 'http');
        $host = $subHost;
        if(preg_replace('/^www\./', '', $finHost) === preg_replace('/^www\./', '', $subHost)){
            $host = $finHost;
            if($scheme === 'http' && strtolower($fin['scheme'] ?? '') === 'https'){
                $scheme = 'https';
            }
        }

        $subPath = $sub['path'] ?? '';
        $pathAndQuery = $subPath . (isset($sub['query']) ? '?' . $sub['query'] : '');
        $finPath = $fin['path'] ?? '';
        if($host === $finHost && !isset($sub['query']) && !isset($fin['query'])
            && rtrim($finPath, '/') === rtrim($subPath, '/')){
            $pathAndQuery = $finPath;
        }

        $port = isset($sub['port']) ? ':' . $sub['port'] : '';
        $fragment = isset($sub['fragment']) ? '#' . $sub['fragment'] : '';

        return $scheme . '://' . $host . $port . $pathAndQuery . $fragment;
    }

    /* ----- Registrable domain ----- */

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
}
?>
