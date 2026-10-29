<?php
/*--
Offline regression test for SignupGuard — the pure halves of the sign-up abuse
checks: the disposable-domain lookup and the network key the per-network cap
counts on.

Run it with:  php tests/signup-guard.php

No database and no network. The disposable cases are the real domains from the
2026-10-29 key-farming wave (nine accounts, seven in fourteen minutes, every key
burned to its free limit); the test reads the vendored list, so refreshing the
list is covered too — if upstream ever drops one of these, this says so.
--*/

require_once __DIR__ . '/../public_html/classes/SignupGuard.class.php';

$pass = 0;
$fail = 0;

function check($label, $actual, $expect){
    global $pass, $fail;
    if($actual === $expect){
        $pass++;
        echo "  ok    $label\n";
    }else{
        $fail++;
        echo "  FAIL  $label\n        expected " . var_export($expect, true) . "\n        actual   " . var_export($actual, true) . "\n";
    }
}

echo "List\n";
check('list file is readable', is_readable(SignupGuard::DOMAINS_FILE), true);
check('list is large', count(file(SignupGuard::DOMAINS_FILE)) > 5000, true);

echo "Disposable domains (the 2026-10-29 wave)\n";
foreach(['nehiho1210@goldvester.com', 'ciyafe9237@hiringview.com', 'eywzbyeflxyuvpwyqb@jbsze.com', 'zkqrdodjfdpojgnmdf@vtmpj.net', 'yifegi3998@playfine.com'] as $email){
    check("refuses $email", SignupGuard::isDisposableEmail($email), true);
}
check('case and whitespace do not matter', SignupGuard::isDisposableEmail("  Someone@GoldVester.COM \n"), true);
check('a subdomain of a listed domain is refused', SignupGuard::isDisposableEmail('x@mail.goldvester.com'), true);
check('a listed domain used as a label of a real one is not', SignupGuard::isDisposableEmail('x@goldvester.com.example.org'), false);

echo "Kept domains\n";
foreach(['michael@mekstudios.com', 'someone@gmail.com', 'brewer@deschutesbrewery.com', 'alias@passmail.net', 'x@duck.com', 'me@icloud.com'] as $email){
    check("keeps $email", SignupGuard::isDisposableEmail($email), false);
}
check('no @ is not disposable (format validation already failed it)', SignupGuard::isDisposableEmail('nonsense'), false);
check('empty domain is not disposable', SignupGuard::isDisposableEmail('x@'), false);
check('bare TLD is not matched', SignupGuard::isDisposableEmail('x@com'), false);

echo "domainOf\n";
check('lowercases', SignupGuard::domainOf('A@B.Example.COM'), 'b.example.com');
check('null without @', SignupGuard::domainOf('abc'), null);

echo "network()\n";
check('IPv4 is itself', SignupGuard::network('92.206.250.79'), '92.206.250.79');
check('IPv6 collapses to its /64', SignupGuard::network('2a02:2455:a0c6:a000:b900:5401:b927:40ea'), '2a02:2455:a0c6:a000::');
check('two hosts in one /64 share a network', SignupGuard::network('2a02:2455:a0c6:a000:4ca5:352:382:290d'), SignupGuard::network('2a02:2455:a0c6:a000:b900:5401:b927:40ea'));
check('different /64s differ', SignupGuard::network('2a02:2455:a0c6:a001::1') === SignupGuard::network('2a02:2455:a0c6:a000::1'), false);
check('IPv4-mapped IPv6 is the IPv4', SignupGuard::network('::ffff:192.0.2.128'), '192.0.2.128');
check('loopback v6', SignupGuard::network('::1'), '::');
check('empty is null', SignupGuard::network(''), null);
check('null is null', SignupGuard::network(null), null);
check('garbage is null', SignupGuard::network('not an ip'), null);
check('a hostname is null', SignupGuard::network('example.com'), null);
check('surrounding whitespace is trimmed', SignupGuard::network(' 10.0.0.1 '), '10.0.0.1');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
