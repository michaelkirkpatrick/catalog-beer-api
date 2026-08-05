<?php
/* SearchQuery.class.php
   Builds sanitised FULLTEXT query strings for the beer and brewer search
   endpoints. Shared so the stopword list and sanitisation rules live in one
   place.
*/

class SearchQuery {

    /* InnoDB's default FULLTEXT stopword list
       (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD). Stopwords never make it
       into the index, so a required term like "+the*" in BOOLEAN MODE can only
       be satisfied by some *other* indexed word starting with "the" — for most
       names it silently fails the AND and knocks the row out of the all-terms
       tier. They have to be stripped before the boolean query is built.
       NATURAL LANGUAGE MODE ignores stopwords on its own, so the nl string
       keeps them. */
    private static $stopwords = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'und', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www'];

    /* True when $query is well-formed UTF-8. Every other method here runs
       PCRE with the /u flag, and PCRE does not merely mishandle malformed
       UTF-8 — it refuses to run and preg_replace() returns NULL. That used to
       collapse both AGAINST() expressions to empty strings and hand the raw
       bad bytes to json_encode(), which failed in turn and produced a 200
       carrying index.php's generic "encoding error" body. A client that
       percent-encodes "Ü" as Latin-1 %DC instead of UTF-8 %C3%9C reaches this.
       Callers gate on it and return 400, because it is bad input, not a
       server fault. */
    public static function validUtf8($query){
        return preg_match('//u', $query ?? '') === 1;
    }

    /* Splits a query into sanitised FULLTEXT terms. Shared with Style, whose
       boolean expression differs (no wildcard, no stopword filter) but whose
       tokenisation must not.

       Everything that is not a letter or digit becomes a separator. This is an
       allowlist on purpose: a blacklist of MySQL's operators misses others —
       "%" alone produces "syntax error, unexpected $end" from the FULLTEXT
       parser, and even a bare "*" is a parser error in NATURAL LANGUAGE MODE
       (not an empty result). \p{L} keeps non-ASCII letters, so "kölsch" still
       searches as a single term. PCRE-only (no mbstring; production lacks it).

       Combining marks are DELETED first rather than left to that separator
       pass. "Ü" reaches us two ways — precomposed U+00DC (NFC) or "U" plus a
       combining diaeresis U+0308 (NFD) — and clients emit NFD silently, from
       macOS clipboards and normalising HTTP stacks. \p{M} is in neither \p{L}
       nor \p{N}, so the mark used to become a space and split one word into
       two useless fragments: "Überbrew" searched as "+U* +berbrew*" and
       matched nothing, while the identical-looking NFC query matched fine.

       Folding to the bare base letter is the fix that works, and keeping the
       mark is not an alternative to it: InnoDB's tokenizer splits on the
       combining mark whatever we do, so an intact "U◌̈berbrew" still scores
       zero. The stripped form matches because the FULLTEXT index collation is
       accent-insensitive (utf8mb4_0900_ai_ci) — "Uberbrew" and the indexed
       "Überbrew" compare equal. Precomposed input is untouched by this and
       behaves exactly as it did before.

       Assumes valid UTF-8; callers check validUtf8() first. */
    public static function tokens($query){
        $folded = preg_replace('/\p{M}+/u', '', $query ?? '') ?? '';
        return preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $folded) ?? ''), -1, PREG_SPLIT_NO_EMPTY);
    }

    /* Returns array('bool' => ..., 'nl' => ...) for use in AGAINST().

       'bool' requires EVERY non-stopword term as a prefix ("+triple* +mash*"),
       which is what lets "triple mash" match "Triple-Mashed" (prefix) without
       matching "Smash" (wildcards are prefix-only). An all-punctuation or
       all-stopword query yields an empty string, which is left empty rather
       than falling back to the raw input — AGAINST('' IN BOOLEAN MODE) matches
       nothing and raises no error, so the natural-language tier still applies.

       'nl' is the sanitised terms rejoined with spaces, stopwords included. */
    public static function terms($query){
        $terms = self::tokens($query);

        $boolQuery = '';
        foreach($terms as $t){
            if(in_array(strtolower($t), self::$stopwords, true)){
                continue;
            }
            $boolQuery .= '+' . $t . '* ';
        }

        return array('bool' => trim($boolQuery), 'nl' => implode(' ', $terms));
    }

    /* Words that are structure, not identity, in a brewery's name. A name
       match on one of these alone is no evidence of a name match at all —
       "GOAL Brewing" name-matches half the catalog via "Brewing". Used to
       decide the partial/description label, never for ranking (FULLTEXT IDF
       already ranks the rare term above these). Deliberately brewer-specific:
       in a BEER name, words like "ale" carry real identity. */
    private static $genericBrewerTerms = ['brewing', 'brewery', 'breweries', 'brewer', 'brewers', 'brew', 'brews', 'brewhouse', 'beer', 'company', 'co', 'inc', 'llc'];

    /* The query's distinctive terms as a NATURAL LANGUAGE string, for the
       name-evidence test in Brewer::search(). Falls back to all terms when
       the query is nothing but generic words ("Brewing Co") — then any name
       word is as distinctive as the query gets. */
    public static function brewerDistinctiveTerms($query){
        $terms = self::tokens($query);
        $distinctive = array();
        foreach($terms as $t){
            if(!in_array(strtolower($t), self::$genericBrewerTerms, true)){
                $distinctive[] = $t;
            }
        }
        if(empty($distinctive)){
            $distinctive = $terms;
        }
        return implode(' ', $distinctive);
    }

    /* Labels why a search row matched, from the tier CASE and a name-only
       relevance the search queries already compute. Shared by
       Brewer::search() and Beer::search() so the vocabulary cannot drift; the
       values are the ones POST /beer style suggestions established.

       Exists because a description mention is indistinguishable from a name
       match in the response (OPEN-ITEMS I6): searching "GOAL Brewing" before
       creating the brewery returned three plausible-looking rows that had
       merely mentioned "goal" in a description — and a first cut that
       labelled any name word "partial" still called those rows name matches,
       because "Brewing" is in every brewery's name. Hence the distinctive
       filter above.

       - exact:       the name equals the query
       - all_terms:   every query term prefix-matches a word in the name
       - partial:     a distinctive query term matches the name (brewer), or
                      any query term does (beer, where the caller passes the
                      full-terms relevance)
       - description: no name evidence — the hit is in the blended text
                      (brewer: description/short_description; beer: style or
                      description). For duplicate screening these rows are
                      mentions, not candidates. */
    public static function matchQuality($tier, $nameRelevance){
        if(intval($tier) === 0){ return 'exact'; }
        if(intval($tier) === 1){ return 'all_terms'; }
        return floatval($nameRelevance) > 0 ? 'partial' : 'description';
    }
}
