<?php
// Initialize
include_once $_SERVER["DOCUMENT_ROOT"] . '/classes/initialize.php';

// Required Classes
$algolia = new Algolia();
$db = new Database();

echo '<pre>';

// Count
$beer_count = 0;
$brewer_count = 0;
$location_count = 0;

// Beer
$db->query("SELECT id FROM beer");
while($array = $db->resultArray()){
  $algolia_id = $algolia->add('beer', $array['id']);
  if(!$algolia->error){
	$beer_count++;
  }else{
	echo $algolia->errorMsg;
  }
}
echo "$beer_count Beers added...\n";

// Brewer
$db->query("SELECT id FROM brewer");
while($array = $db->resultArray()){
  $algolia_id = $algolia->add('brewer', $array['id']);
  if(!$algolia->error){
	$brewer_count++;
  }else{
	echo $algolia->errorMsg;
  }
}
echo "$brewer_count Brewers added...\n";

// Location
$db->query("SELECT id FROM location");
while($array = $db->resultArray()){
  $algolia_id = $algolia->add('location', $array['id']);
  if(!$algolia->error){
	$location_count++;
  }else{
	echo $algolia->errorMsg;
  }
}
echo "$location_count Locations added...\n";

echo '</pre>';
?>