<?php
/*
Generate the `catalog` index's synonym set from the style vocabulary and push
it to Algolia.

Why this exists: Algolia ANDs query tokens, and "ipa" is not a token anywhere
in "American-Style India Pale Ale" — typo tolerance can't bridge an
abbreviation, so without synonyms the single most common beer search on the
internet returns nothing. The style_alias and parent_alias tables are already
a curated vocabulary of how people actually spell styles ("NEIPA", "hazy",
"DIPA"); this script re-uses that curation instead of maintaining a second
alias list in the Algolia dashboard.

Like settings.php, THIS SCRIPT IS THE SOURCE OF TRUTH — the push replaces the
entire synonym set (replaceExistingSynonyms), so edit the tables (or this
script), then re-run. Never hand-edit synonyms in the dashboard.

    php synonyms.php [staging|production]

Safe to re-run. Run it whenever style_alias / parent_alias change — in
practice that's a style-library update, the same event that warrants a
batch re-upload of the style records.

Class aliases (style_class / class_alias) are deliberately NOT pushed:
"ale" and "lager" as synonym groups would mostly add noise to queries that
already match half the index.
*/

// CLI only
if(php_sapi_name() !== 'cli'){
    exit(1);
}

// Define Root
define('ROOT', dirname(__DIR__));

// Determine environment from CLI argument
$env = $argv[1] ?? 'production';
if(!in_array($env, ['staging', 'production'])){
    echo "Usage: php synonyms.php [staging|production]\n";
    exit(1);
}
define('ENVIRONMENT', $env);

// Load Passwords
require_once ROOT . '/common/passwords.php';

// Set Timezone
date_default_timezone_set('America/Los_Angeles');

// Autoload Classes
spl_autoload_register(function ($class_name) {
    require_once ROOT . '/classes/' . $class_name . '.class.php';
});

/*
Build synonym groups: each is the canonical display name plus every alias, as
a plain multi-way synonym — any member matches any other, so a query "NEIPA"
matches beer/style text saying "Juicy or Hazy India Pale Ale" and vice versa.

Algolia caps a synonym object at 20 expressions, so a long alias list is
chunked into multiple groups sharing the canonical name (the canonical name in
each chunk keeps every alias transitively connected to the text that actually
appears in records).
*/
function synonymGroups($idPrefix, $canonical, $aliases){
    $groups = array();

    // Dedupe, drop empties and the canonical name itself
    $clean = array();
    foreach($aliases as $alias){
        $alias = trim($alias);
        if($alias !== '' && strcasecmp($alias, $canonical) !== 0){
            $clean[strtolower($alias)] = $alias;
        }
    }
    if(empty($clean)){
        return $groups;
    }

    $chunks = array_chunk(array_values($clean), 19);
    foreach($chunks as $i => $chunk){
        $groups[] = array(
            'objectID' => $idPrefix . ($i > 0 ? '-' . ($i + 1) : ''),
            'type'     => 'synonym',
            'synonyms' => array_merge(array($canonical), $chunk)
        );
    }
    return $groups;
}

$db = new Database();
$synonyms = array();

// ----- Style aliases -----
// Canonical name per style, then its aliases.
$names = array();
$result = $db->query("SELECT id, canonical_name FROM style");
if($db->error){
    echo "FAILED reading style table: {$db->errorMsg}\n";
    exit(1);
}
while($row = $result->fetch_assoc()){
    $names[$row['id']] = $row['canonical_name'];
}

$aliasesByStyle = array();
$result = $db->query("SELECT style_id, alias FROM style_alias");
if($db->error){
    echo "FAILED reading style_alias table: {$db->errorMsg}\n";
    exit(1);
}
while($row = $result->fetch_assoc()){
    $aliasesByStyle[$row['style_id']][] = $row['alias'];
}

foreach($aliasesByStyle as $styleID => $aliases){
    if(isset($names[$styleID])){
        $synonyms = array_merge($synonyms, synonymGroups('syn-style-' . $styleID, $names[$styleID], $aliases));
    }
}

// ----- Family aliases -----
// "ipa" reaching the style_family value "India Pale Ale" is what makes a
// typed "hazy ipa" match records faceted under the family display name.
$parentNames = array();
$result = $db->query("SELECT slug, name FROM style_parent");
if($db->error){
    echo "FAILED reading style_parent table: {$db->errorMsg}\n";
    exit(1);
}
while($row = $result->fetch_assoc()){
    $parentNames[$row['slug']] = $row['name'];
}

$aliasesByParent = array();
$result = $db->query("SELECT parent, alias FROM parent_alias");
if($db->error){
    echo "FAILED reading parent_alias table: {$db->errorMsg}\n";
    exit(1);
}
while($row = $result->fetch_assoc()){
    $aliasesByParent[$row['parent']][] = $row['alias'];
}
$db->close();

foreach($aliasesByParent as $slug => $aliases){
    if(isset($parentNames[$slug])){
        $synonyms = array_merge($synonyms, synonymGroups('syn-family-' . $slug, $parentNames[$slug], $aliases));
    }
}

// Push to Algolia
$algolia = new Algolia();
echo "--- Pushing " . count($synonyms) . " synonym groups to `catalog` on {$env}...\n\n";

if($algolia->saveSynonyms('catalog', $synonyms)){
    echo "Synonyms replaced successfully.\n";
    echo "  style groups:  " . count($aliasesByStyle) . "\n";
    echo "  family groups: " . count($aliasesByParent) . "\n";
    exit(0);
}else{
    echo "FAILED — see the error_log table for details.\n";
    exit(1);
}
?>
