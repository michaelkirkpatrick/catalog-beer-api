<?php
// Initialize
include_once $_SERVER["DOCUMENT_ROOT"] . '/classes/initialize.php';

echo '<pre>';
$algolia = new Algolia();
$response = $algolia->searchAlgolia("Ballast Point Brewing");
print_r($response);
echo '</pre>';
?>