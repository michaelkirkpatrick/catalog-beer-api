#!/usr/bin/env python3
"""
Build public_html/classes/TelephoneMetadata.class.php from libphonenumber's
PhoneNumberMetadata.xml, for the countries Address::COUNTRIES lists.

    python3 -I tools/build-telephone-metadata.py /path/to/PhoneNumberMetadata.xml v9.0.41

The XML is the one file in Google's libphonenumber repo that holds every
country's numbering rules (resources/PhoneNumberMetadata.xml, Apache 2.0):

    https://raw.githubusercontent.com/google/libphonenumber/<tag>/resources/PhoneNumberMetadata.xml

We take, per territory, only what Telephone.class.php needs to format a
number and sanity-check its length: the calling code, the national prefix,
the general national-number pattern, the set of possible national-number
lengths, and the ordered list of number formats (pattern, leading digits,
national format, international format, national-prefix rule). Everything
else in the file -- per-type patterns, carrier codes, as-you-type data,
the other 200 territories -- is left out. The matcher is ours; this is the
data it reads. Re-run this when a numbering plan changes (new mobile ranges
are the usual reason) and commit the regenerated class.

Pass the file through -I: it is downloaded data, and -I keeps the
interpreter from importing anything beside it.
"""
import re
import sys
import datetime
import xml.etree.ElementTree as ET

WANT = ['AR', 'AT', 'AU', 'BE', 'BG', 'BR', 'CA', 'CH', 'CL', 'CO', 'CZ', 'DE', 'DK', 'EE', 'ES',
        'FI', 'FR', 'GB', 'HR', 'HU', 'IE', 'IN', 'IT', 'JP', 'LT', 'LU', 'LV', 'MX', 'MY', 'NL',
        'NO', 'NZ', 'PL', 'PR', 'PT', 'SE', 'SG', 'SI', 'SK', 'US']


def squash(text):
    """libphonenumber strips every whitespace character from a pattern."""
    return re.sub(r'\s+', '', text or '')


def lengths(spec):
    """'7,9,10' / '[7-9]' / '[6-8],10,12' -> set of ints."""
    out = set()
    for part in spec.split(','):
        m = re.fullmatch(r'\[(\d+)-(\d+)\]', part)
        if m:
            out.update(range(int(m.group(1)), int(m.group(2)) + 1))
        else:
            out.add(int(part))
    return out


def php(s):
    if s is None:
        return 'null'
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    xml_path, version = sys.argv[1], sys.argv[2]
    root = ET.parse(xml_path).getroot()
    territories = {t.get('id'): t for t in root.iter('territory')}

    # The territory that carries the formats for a shared calling code
    # (US for +1; CA and PR have none of their own).
    main_for_code = {}
    for t in territories.values():
        if t.get('mainCountryForCode') == 'true':
            main_for_code[t.get('countryCode')] = t.get('id')

    regions = []
    for cc in WANT:
        t = territories[cc]
        formats = []
        avail = t.find('availableFormats')
        if avail is not None:
            for nf in avail.findall('numberFormat'):
                lead = nf.findall('leadingDigits')
                formats.append({
                    'p': squash(nf.get('pattern')),
                    # Several leadingDigits elements are as-you-type refinements;
                    # the last one is the test for a complete number.
                    'ld': squash(lead[-1].text) if lead else None,
                    # Layouts keep their spaces: "($1) $2-$3" is the US form.
                    'f': (nf.findtext('format') or '').strip(),
                    'i': (nf.findtext('intlFormat') or '').strip() or None,
                    'npfr': nf.get('nationalPrefixFormattingRule'),
                })
        lens = set()
        for pl in t.iter('possibleLengths'):
            lens.update(lengths(pl.get('national')))
        regions.append({
            'id': cc,
            'cc': t.get('countryCode'),
            'np': t.get('nationalPrefix'),
            'npfr': t.get('nationalPrefixFormattingRule'),
            'pattern': squash(t.findtext('generalDesc/nationalNumberPattern')),
            'lengths': sorted(lens),
            'formats': formats,
            'formats_from': None if formats else main_for_code.get(t.get('countryCode')),
        })

    today = datetime.date.today().isoformat()
    out = []
    out.append('<?php')
    out.append('/*--')
    out.append('GENERATED FILE -- do not edit. Rebuild with tools/build-telephone-metadata.py.')
    out.append('')
    out.append('Numbering-plan data for the countries a location may be in, read by')
    out.append('Telephone.class.php to format an E.164 number the way its country writes')
    out.append('it and to check that its length is one the country issues. The matcher')
    out.append('is ours; the data is taken from Google\'s libphonenumber project:')
    out.append('')
    out.append('    PhoneNumberMetadata.xml, libphonenumber ' + version + ', extracted ' + today)
    out.append('    Copyright (C) 2009 The Libphonenumber Authors')
    out.append('    Licensed under the Apache License, Version 2.0')
    out.append('    http://www.apache.org/licenses/LICENSE-2.0')
    out.append('')
    out.append('Per region: cc (calling code), np (national trunk prefix, null when the')
    out.append('country has none), npfr (how the prefix is written back when formatting')
    out.append('nationally, $NP = prefix and $FG = first group), pattern (the general')
    out.append('shape of a national number), lengths (every national-number length the')
    out.append('country issues, any type), and formats in the order they are tried:')
    out.append('p = the pattern a whole national number must match, ld = what it must')
    out.append('start with, f = the national layout, i = the international layout (null')
    out.append('means same as f; "NA" means never use this format internationally),')
    out.append('npfr = a per-format override of the region\'s. A region with no formats')
    out.append('of its own names the region whose formats it shares in formats_from.')
    out.append('--*/')
    out.append('class TelephoneMetadata {')
    out.append('')
    out.append("    const VERSION = " + php(version) + ';')
    out.append('')
    out.append('    const REGIONS = array(')
    for r in regions:
        out.append("        '%s' => array(" % r['id'])
        out.append("            'cc' => %s," % php(r['cc']))
        out.append("            'np' => %s," % php(r['np']))
        out.append("            'npfr' => %s," % php(r['npfr']))
        out.append("            'pattern' => %s," % php(r['pattern']))
        out.append("            'lengths' => array(%s)," % ', '.join(str(n) for n in r['lengths']))
        out.append("            'formats_from' => %s," % php(r['formats_from']))
        out.append("            'formats' => array(")
        for f in r['formats']:
            out.append("                array('p' => %s, 'ld' => %s, 'f' => %s, 'i' => %s, 'npfr' => %s),"
                       % (php(f['p']), php(f['ld']), php(f['f']), php(f['i']), php(f['npfr'])))
        out.append("            ),")
        out.append("        ),")
    out.append('    );')
    out.append('}')
    out.append('?>')
    sys.stdout.write('\n'.join(out) + '\n')


if __name__ == '__main__':
    main()
