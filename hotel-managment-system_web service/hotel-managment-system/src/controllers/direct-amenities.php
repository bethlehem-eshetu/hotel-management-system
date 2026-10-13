<?php
// HOTEL-SYSTEM/public/api/direct-amenities.php

// Simple direct response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Get hotel ID
$hotelId = isset($_GET['hotel_id']) ? (int)$_GET['hotel_id'] : 0;

// Always return some sample amenities
$amenities = [
    ['id' => 1, 'name' => 'Free WiFi', 'description' => 'High-speed internet access'],
    ['id' => 2, 'name' => 'Parking', 'description' => 'Free parking available'],
    ['id' => 3, 'name' => '24/7 Reception', 'description' => '24-hour front desk']
];

echo json_encode([
    'status' => 'ok',
    'message' => 'Amenities retrieved from direct endpoint',
    'hotel_id' => $hotelId,
    'count' => count($amenities),
    'amenities' => $amenities,
    'source' => 'direct-amenities.php'
]);
exit;