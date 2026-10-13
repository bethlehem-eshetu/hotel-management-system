<?php
// public/api/fallback-amenities.php

// Include the bootstrap file from the parent directory
require_once __DIR__ . '/../../src/bootstrap.php';

// Set headers to prevent HTML output
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Disable HTML error display
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

// Function to send JSON response
function jsonResponse($status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    
    $response = [
        'status' => $status,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Get hotel ID from query parameter
    $hotelId = $_GET['hotel_id'] ?? null;
    
    // Debug log
    error_log("Fallback amenities called. Hotel ID: " . ($hotelId ?: 'not provided'));
    
    // Validate hotel ID
    if (!$hotelId) {
        jsonResponse('error', 'Missing hotel_id parameter', [
            'example_url' => '/HOTEL-SYSTEM/public/api/fallback-amenities.php?hotel_id=1'
        ], 400);
    }
    
    if (!is_numeric($hotelId)) {
        jsonResponse('error', 'Invalid hotel ID. Must be numeric. Received: ' . htmlspecialchars($hotelId), null, 400);
    }
    
    $hotelId = (int)$hotelId;
    
    $pdo = db();
    
    // Check if hotel exists
    $stmt = $pdo->prepare("SELECT id, name FROM hotels WHERE id = ?");
    $stmt->execute([$hotelId]);
    $hotel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$hotel) {
        // List some available hotels for debugging
        $availableHotels = $pdo->query("SELECT id, name FROM hotels LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse('error', 'Hotel not found', [
            'requested_hotel_id' => $hotelId,
            'available_hotels' => $availableHotels
        ], 404);
    }
    
    // Check if amenities table exists
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('amenities', $tables)) {
        jsonResponse('ok', 'Amenities table does not exist', [
            'hotel_id' => $hotelId,
            'hotel_name' => $hotel['name'],
            'count' => 0,
            'amenities' => [],
            'note' => 'amenities table not found in database'
        ]);
    }
    
    // Check if hotel_amenities table exists
    if (!in_array('hotel_amenities', $tables)) {
        jsonResponse('ok', 'No amenities assigned', [
            'hotel_id' => $hotelId,
            'hotel_name' => $hotel['name'],
            'count' => 0,
            'amenities' => [],
            'note' => 'hotel_amenities table not found'
        ]);
    }
    
    // Get amenities for this hotel
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.description, a.created_at
        FROM amenities a
        INNER JOIN hotel_amenities ha ON ha.amenity_id = a.id
        WHERE ha.hotel_id = ?
        ORDER BY a.name ASC
    ");
    
    $stmt->execute([$hotelId]);
    $amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success with amenities
    jsonResponse('ok', 'Amenities retrieved successfully via fallback', [
        'hotel_id' => $hotelId,
        'hotel_name' => $hotel['name'],
        'count' => count($amenities),
        'amenities' => $amenities,
        'source' => 'fallback-amenities.php'
    ]);
    
} catch (Exception $e) {
    // Even if there's an error, return empty array to prevent frontend crash
    jsonResponse('ok', 'Fallback response (error suppressed)', [
        'hotel_id' => $hotelId ?? 0,
        'count' => 0,
        'amenities' => [],
        'error_note' => 'Using safe fallback',
        'debug_info' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}