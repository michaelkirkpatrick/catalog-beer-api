<?php
/*--
Offline regression test for TextInput — the shape validation applied to every
user-submitted free-text field.

Run it with:  php tests/text-input.php

No database and no network: every case is a literal, including the six real
production values that motivated the rules. Follows the pattern proven by
tests/address-parse.php. Add a case here for any new character class before
changing TextInput.class.php.
--*/

require_once __DIR__ . '/../classes/TextInput.class.php';

$pass = 0;
$fail = 0;

/*--
$expect is the message fragment check() must return, or '' for "accepted".
Matching on a fragment rather than the whole string keeps the wording free to
change without breaking the test.
--*/
function assertCheck($label, $value, $multiline, $expect){
    global $pass, $fail;

    $trimmed = TextInput::trim($value);
    $actual  = TextInput::check($trimmed, $multiline);

    $ok = ($expect === '') ? ($actual === '') : (strpos($actual, $expect) !== false);

    if($ok){
        $pass++;
        printf("  ok    %s\n", $label);
    }else{
        $fail++;
        printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n",
            $label,
            $expect === '' ? '(accepted)' : "...$expect...",
            $actual === '' ? '(accepted)' : $actual);
    }
}

function assertTrim($label, $value, $expected){
    global $pass, $fail;

    $actual = TextInput::trim($value);

    if($actual === $expected){
        $pass++;
        printf("  ok    %s\n", $label);
    }else{
        $fail++;
        printf("  FAIL  %s\n        expected: %s\n        actual:   %s\n",
            $label, bin2hex($expected), bin2hex($actual));
    }
}

echo "\nOrdinary text is untouched\n";
assertCheck('plain ASCII name', 'Pliny the Elder', false, '');
assertCheck('accented name', "Bi\u{e8}re de Garde", false, '');
assertCheck('ampersand and quotes', 'Bob\'s "Best" Bitter & Ale', false, '');
assertCheck('em dash and curly quotes', "Sierra Nevada \u{2014} O\u{2019}Brien\u{2019}s", false, '');
assertCheck('CJK name', "\u{9ed2}\u{30d3}\u{30fc}\u{30eb}", false, '');
assertCheck('emoji name', "Hazy IPA \u{1F37A}", false, '');

echo "\nNewlines: allowed in descriptions, rejected in single-line fields\n";
assertCheck('description with newlines', "First line.\nSecond line.", true, '');
assertCheck('description with CRLF', "First line.\r\nSecond line.", true, '');
assertCheck('name with a newline', "Two\nLines", false, 'single line');
assertCheck('name with a carriage return', "Two\rLines", false, 'single line');

echo "\nTabs are rejected everywhere\n";
// All nine tab-carrying production descriptions look like this: scraped web
// copy, tab padding, then an ABV/IBU line duplicating dedicated columns.
assertCheck('real: Sin Bin description',
    "This Pale Ale won't send you to the penalty box. \t  6.2% ABV|19 IBU's", true, 'tab');
assertCheck('real: Deadeye Stout description',
    "...you expect from a stout.\t\t\t       5.5% ABV | 28 IBU's", true, 'tab');
assertCheck('tab in a name', "Hazy\tIPA", false, 'tab');

echo "\nControl characters are rejected\n";
assertCheck('NUL',           "Ale\x00Bad",  false, 'control characters');
assertCheck('ESC',           "Ale\x1bBad",  false, 'control characters');
assertCheck('DEL',           "Ale\x7fBad",  false, 'control characters');
assertCheck('vertical tab',  "Ale\x0bBad",  false, 'control characters');
assertCheck('form feed',     "Ale\x0cBad",  false, 'control characters');
assertCheck('NUL in a description', "Ale\x00Bad", true, 'control characters');

echo "\nUnicode line separators\n";
// Real production row: beer 9cb32f7b, "Four Witches" with a TRAILING U+2028.
// trim() strips it, so this is cleaned rather than rejected.
assertCheck('real: Four Witches (trailing U+2028)', "Four Witches\u{2028}", false, '');
assertTrim('real: Four Witches trims to clean text', "Four Witches\u{2028}", 'Four Witches');
assertCheck('interior U+2028', "Four\u{2028}Witches", false, 'line separator');
assertCheck('interior U+2029', "Four\u{2029}Witches", false, 'line separator');

echo "\nBidi overrides\n";
// Real production row: beer 740a0ec9, a pasted social-media hashtag carrying
// U+200E LRM and U+202C POP DIRECTIONAL FORMATTING, both interior.
assertCheck('real: #NOHOLDSIE Holdsie Uno',
    "#\u{200E}NOHOLDSIE\u{202C} Holdsie Uno", false, 'text-direction');
assertCheck('RLO',  "Ale\u{202E}Bad", false, 'text-direction');
assertCheck('LRI',  "Ale\u{2066}Bad", false, 'text-direction');
assertCheck('PDI',  "Ale\u{2069}Bad", false, 'text-direction');

echo "\nZero-width: trimmed at the edges, never rejected\n";
// Real production rows, all edge cases that trim() should quietly clean.
assertTrim('real: leading ZWSP on Hurley Park',
    "\u{200B}Hurley Park Blood Orange Wheat", 'Hurley Park Blood Orange Wheat');
assertTrim('real: leading ZWSP on Wood Boat',
    "\u{200B}Wood Boat Pale Ale", 'Wood Boat Pale Ale');
assertTrim('real: trailing ZWSP on Straight Jacket IPA',
    "Straight Jacket IPA\u{200B}", 'Straight Jacket IPA');
assertTrim('real: trailing LRM on Element Brewing Company',
    "Element Brewing Company\u{200E}", 'Element Brewing Company');

// ZWNJ and ZWJ are required by Persian, Arabic and Indic scripts and by emoji
// sequences. Rejecting them would break legitimate international names.
assertCheck('ZWNJ survives (Persian)',   "\u{647}\u{200C}\u{627}", false, '');
assertCheck('ZWJ survives (emoji seq)',  "\u{1F468}\u{200D}\u{1F373}", false, '');

echo "\nUnicode whitespace at the edges\n";
assertTrim('non-breaking space',    "\u{00A0}Pale Ale\u{00A0}", 'Pale Ale');
assertTrim('ideographic space',     "\u{3000}Pale Ale\u{3000}", 'Pale Ale');
assertTrim('ordinary spaces',       '  Pale Ale  ', 'Pale Ale');
assertTrim('interior space kept',   'Pale  Ale', 'Pale  Ale');

echo "\nEdge cases\n";
assertCheck('empty string', '', false, '');
assertCheck('null', null, false, '');
assertTrim('null trims to empty', null, '');
assertCheck('whitespace only becomes empty and passes', "   \n  ", true, '');
// Malformed UTF-8 should already be a 400 from index.php; say so rather than
// letting preg_match return false and silently accept.
assertCheck('invalid UTF-8', "Caf\xe9 Bad", false, 'UTF-8');
assertTrim('invalid UTF-8 falls back to ASCII trim', "  Caf\xe9  ", "Caf\xe9");

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
