<?php
/*--
Write an E.164 telephone number the way its country writes it, and check
that its length is one the country issues.

The catalog stores one shape, E.164 ("+31205286237"): unambiguous, dials
from a tel: link, and the same for every country. It is also unreadable,
and regrouping the digits by hand is wrong as often as not -- Amsterdam's
area code is two digits (20), most of the Netherlands' are three, and a
mobile's is one (6). Where a group ends is a fact about the numbering plan,
so this class reads the plan.

The plan data is TelephoneMetadata::REGIONS, generated from libphonenumber's
metadata for the countries Address::COUNTRIES lists (tools/
build-telephone-metadata.py has the how and the license). Per region it
holds an ordered list of formats, each with a leading-digits test and a
whole-number pattern; the first format a national number satisfies is the
one applied. That is the same selection libphonenumber performs. The code
here is ours; only the tables are borrowed.

Two layouts come out of it:

    formatInternational('+31205286237')  "+31 20 528 6237"
    formatNational('+31205286237')       "020 528 6237"
    formatInternational('+16192557213')  "+1 619-255-7213"
    formatNational('+16192557213')       "(619) 255-7213"

A number whose calling code is not in the table (a +961 number on a Belgian
location, say) formats as null; callers fall back to the E.164 string. A
number in a known country that matches none of its formats comes back as
the calling code and the bare national number, which is still correct, just
ungrouped.

lengthKnown() is the one validity check taken from the plan: every length a
country issues for any kind of number. It is deliberately loose -- Germany
issues everything from 4 to 15 digits -- because the point is to refuse a
number that is plainly truncated or doubled, not to second-guess a brewery's
own phone number against a table that goes stale.

Offline regression test: php tests/telephone.php
--*/
class Telephone {

    /* Calling code -> the region whose rules format it. Where several
       regions share a code (+1), the one that carries the formats wins. */
    private static $byCode = null;

    private static function codes(){
        if(self::$byCode === null){
            self::$byCode = array();
            foreach(TelephoneMetadata::REGIONS as $id => $region){
                $cc = $region['cc'];
                if(!isset(self::$byCode[$cc]) || empty($region['formats_from'])){
                    self::$byCode[$cc] = $id;
                }
            }
        }
        return self::$byCode;
    }

    /*--
    Split an E.164 string into its parts, or null when it is not E.164 or
    its calling code is not one the table holds:

        array('region' => 'NL', 'cc' => '31', 'nsn' => '205286237')

    Calling codes are one to three digits and no code is a prefix of
    another, so the longest match is the only match.
    --*/
    public static function parse($e164){
        if(!is_string($e164) || !preg_match('/^\+([1-9]\d{6,14})$/', $e164, $m)){
            return null;
        }
        $digits = $m[1];
        $codes = self::codes();
        foreach(array(3, 2, 1) as $len){
            $cc = substr($digits, 0, $len);
            if(isset($codes[$cc])){
                return array('region' => $codes[$cc], 'cc' => $cc, 'nsn' => substr($digits, $len));
            }
        }
        return null;
    }

    /* The region's own formats, or the ones it shares (CA and PR use US's). */
    private static function formats(array $region){
        if(!empty($region['formats'])){
            return $region['formats'];
        }
        if(!empty($region['formats_from']) && isset(TelephoneMetadata::REGIONS[$region['formats_from']])){
            return TelephoneMetadata::REGIONS[$region['formats_from']]['formats'];
        }
        return array();
    }

    /* The first format whose leading-digits test and whole-number pattern
       the national number satisfies; null when none does. A format marked
       "NA" internationally is skipped for that layout. */
    private static function choose(array $region, $nsn, $international){
        foreach(self::formats($region) as $format){
            if($international && $format['i'] === 'NA'){
                continue;
            }
            if($format['ld'] !== null && !preg_match('/^(?:' . $format['ld'] . ')/', $nsn)){
                continue;
            }
            if(!preg_match('/^(?:' . $format['p'] . ')$/', $nsn)){
                continue;
            }
            return $format;
        }
        return null;
    }

    /*--
    Apply a format's layout to the national number. The layout is "$1 $2 $3"
    style; nationally, the trunk prefix is written back into the first group
    where the plan says so ("$NP$FG" -> "0$1", giving "020 528 6237").

    Group references are rewritten as ${n} before preg_replace sees them:
    Argentina's "$215-$3-$4" means group 2 then a literal 15, which "$215"
    would not.
    --*/
    private static function apply(array $format, array $region, $nsn, $international){
        $layout = $international ? ($format['i'] ?? $format['f']) : $format['f'];
        if(!$international){
            $rule = $format['npfr'] ?? $region['npfr'];
            $prefix = $region['np'];
            if($rule !== null && $prefix !== null && $prefix !== ''){
                $rule = str_replace(array('$NP', '$FG'), array($prefix, '$1'), $rule);
                $at = strpos($layout, '$1');
                if($at !== false){
                    $layout = substr_replace($layout, $rule, $at, 2);
                }
            }
        }
        $layout = preg_replace('/\$(\d)/', '\${$1}', $layout);
        return preg_replace('/^(?:' . $format['p'] . ')$/', $layout, $nsn);
    }

    /* "+31 20 528 6237". null when the calling code is unknown. */
    public static function formatInternational($e164){
        $parts = self::parse($e164);
        if($parts === null){
            return null;
        }
        $region = TelephoneMetadata::REGIONS[$parts['region']];
        $format = self::choose($region, $parts['nsn'], true);
        $national = ($format === null) ? $parts['nsn'] : self::apply($format, $region, $parts['nsn'], true);
        return '+' . $parts['cc'] . ' ' . $national;
    }

    /* "020 528 6237", "(619) 255-7213". null when the calling code is
       unknown; the trunk prefix and bare digits when no format matches. */
    public static function formatNational($e164){
        $parts = self::parse($e164);
        if($parts === null){
            return null;
        }
        $region = TelephoneMetadata::REGIONS[$parts['region']];
        $format = self::choose($region, $parts['nsn'], false);
        if($format === null){
            return (string)$region['np'] . $parts['nsn'];
        }
        return self::apply($format, $region, $parts['nsn'], false);
    }

    /* Is the national number a length its country issues? null when the
       calling code is unknown (nothing to check against). */
    public static function lengthKnown($e164){
        $parts = self::parse($e164);
        if($parts === null){
            return null;
        }
        return in_array(strlen($parts['nsn']), TelephoneMetadata::REGIONS[$parts['region']]['lengths'], true);
    }

    /* The ISO short name of the number's own country, for an error message.
       Regions sharing a calling code resolve to the one that formats it. */
    public static function countryName($e164){
        $parts = self::parse($e164);
        return ($parts === null) ? null : CountryCode::shortName($parts['region']);
    }
}
?>
