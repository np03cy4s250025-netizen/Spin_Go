<?php
// backend/api/cities.php
require_once '../config/db.php';
require_once '../models/City.php';

header('Content-Type: application/json');

$query = $_GET['q'] ?? '';
$city_obj = new City($conn);

if (empty($query)) {
    // Return popular cities by default if no query
    echo json_encode($city_obj->getPopular());
} else {
    // Return search results
    echo json_encode($city_obj->search($query));
}
