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

    /* Returns array('bool' => ..., 'nl' => ...) for use in AGAINST().

       Everything that is not a letter or digit becomes a separator. This is an
       allowlist on purpose: a blacklist of MySQL's operators misses others —
       "%" alone produces "syntax error, unexpected $end" from the FULLTEXT
       parser, and even a bare "*" is a parser error in NATURAL LANGUAGE MODE
       (not an empty result). \p{L} keeps non-ASCII letters, so "kölsch" still
       searches as a single term. PCRE-only (no mbstring; production lacks it).

       'bool' requires EVERY non-stopword term as a prefix ("+triple* +mash*"),
       which is what lets "triple mash" match "Triple-Mashed" (prefix) without
       matching "Smash" (wildcards are prefix-only). An all-punctuation or
       all-stopword query yields an empty string, which is left empty rather
       than falling back to the raw input — AGAINST('' IN BOOLEAN MODE) matches
       nothing and raises no error, so the natural-language tier still applies.

       'nl' is the sanitised terms rejoined with spaces, stopwords included. */
    public static function terms($query){
        $terms = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $query)), -1, PREG_SPLIT_NO_EMPTY);

        $boolQuery = '';
        foreach($terms as $t){
            if(in_array(strtolower($t), self::$stopwords, true)){
                continue;
            }
            $boolQuery .= '+' . $t . '* ';
        }

        return array('bool' => trim($boolQuery), 'nl' => implode(' ', $terms));
    }
}
