<?php
/**
 * SignupGuard — the abuse checks on account creation.
 *
 * Added 2026-10-29 after one person opened nine accounts in two days, seven of
 * them in fourteen minutes, from temp-mail addresses (goldvester.com,
 * hiringview.com, jbsze.com, vtmpj.net, playfine.com), verified each one from
 * the throwaway inbox and burned each key's 1,000 free requests dumping
 * /brewer/{id}/beer. The website's reCAPTCHA passed every one: a human was
 * typing. What stops it is refusing addresses nobody can be held to and capping
 * how many accounts one network can open.
 *
 * The two pure checks live here so tests/signup-guard.php can cover them
 * without a database:
 *   - isDisposableEmail(): the address's domain, or any parent of it, is on the
 *     vendored disposable-email-domains list (resources/disposable-email-domains.txt).
 *   - network(): the unit the per-network cap counts — an IPv4 address, or an
 *     IPv6 /64, since a home connection hands out a whole /64 and the farmer
 *     above used two interface IDs in one.
 * The counts against `users` (createdAt, signupNet) are in Users::createAccount().
 *
 * Users::validateEmail() runs the disposable check for both POST /users and a
 * PATCH that changes the email, so an account can't be opened with a real
 * address and then moved to a throwaway one.
 */
class SignupGuard {

    /** Accounts one network may open per day. A household or an office making a second or third account is fine; seven in fourteen minutes is not. */
    public const MAX_PER_NETWORK_PER_DAY = 3;

    /** Website sign-ups per hour before the form is paused for everyone — the breaker for a flood from many networks. Historically the site sees about one sign-up a day. */
    public const MAX_SITEWIDE_PER_HOUR = 10;

    /**
     * The blocklist: one lowercase domain per line, from
     * https://github.com/disposable-email-domains/disposable-email-domains
     * (disposable_email_blocklist.conf, CC0). Refresh it by downloading the file
     * again; never hand-edit it, so a diff against upstream stays meaningful.
     * Fetched 2026-10-29 (9,963 domains).
     */
    public const DOMAINS_FILE = __DIR__ . '/resources/disposable-email-domains.txt';

    private static ?array $domains = null;

    /** True when the address's domain, or any parent domain of it, is on the list. */
    public static function isDisposableEmail(string $email): bool {
        $domain = self::domainOf($email);
        if($domain === null){
            return false;
        }
        $set = self::domains();
        // mail.goldvester.com belongs to goldvester.com; stop short of the bare TLD.
        $labels = explode('.', $domain);
        $count = count($labels);
        for($i = 0; $i < $count - 1; $i++){
            if(isset($set[implode('.', array_slice($labels, $i))])){
                return true;
            }
        }
        return false;
    }

    /** The lowercase ASCII domain of an address, or null when there is none. */
    public static function domainOf(string $email): ?string {
        $at = strrpos($email, '@');
        if($at === false){
            return null;
        }
        $domain = strtolower(trim(substr($email, $at + 1), " \t\r\n."));
        if($domain === ''){
            return null;
        }
        if(function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7F]/', $domain)){
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if(is_string($ascii) && $ascii !== ''){
                $domain = $ascii;
            }
        }
        return $domain;
    }

    /**
     * The network an address belongs to, as the string stored in users.signupNet:
     * the IPv4 address itself, or the IPv6 /64 prefix ("2a02:2455:a0c6:a000::").
     * An IPv4-mapped IPv6 address collapses to its IPv4. Null for anything that
     * is not an IP address, which also switches the per-network cap off for
     * callers that do not send one (Newman, an admin script).
     */
    public static function network(?string $ip): ?string {
        $ip = trim((string)$ip);
        if($ip === ''){
            return null;
        }
        $bin = @inet_pton($ip);
        if($bin === false){
            return null;
        }
        if(strlen($bin) === 4){
            return inet_ntop($bin);
        }
        if(substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff"){
            return inet_ntop(substr($bin, 12));
        }
        return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
    }

    /** The blocklist as a set, loaded once per request. A missing file fails open and logs, so a bad deploy can't lock sign-ups. */
    private static function domains(): array {
        if(self::$domains !== null){
            return self::$domains;
        }
        self::$domains = [];
        $lines = is_readable(self::DOMAINS_FILE) ? file(self::DOMAINS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if($lines === false){
            if(class_exists('LogError')){
                $errorLog = new LogError();
                $errorLog->errorNumber = 362;
                $errorLog->errorMsg = 'Disposable-email domain list missing or unreadable; sign-ups are not being screened';
                $errorLog->badData = self::DOMAINS_FILE;
                $errorLog->filename = 'API / SignupGuard.class.php';
                $errorLog->write();
            }
            return self::$domains;
        }
        foreach($lines as $line){
            $line = strtolower(trim($line));
            if($line === '' || $line[0] === '#'){
                continue;
            }
            self::$domains[$line] = true;
        }
        return self::$domains;
    }
}
