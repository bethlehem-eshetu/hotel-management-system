<?php
// src/config/services.php
require_once __DIR__ . '/config.php';

$baseConfig = getConfig();

return [
    'provided_services' => [
        'hotel_management' => [
            'name' => 'Group 2 Hotel Management System',
            'description' => 'Complete hotel booking and management platform',
            'version' => '1.0.0',
            'base_url' => (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
                          $_SERVER['HTTP_HOST'] . 
                          dirname($_SERVER['PHP_SELF']),
            'api_version' => 'v1',
            'contact' => 'group2@example.com',
            'documentation' => '/HOTEL-SYSTEM/public/pages/api-docs.html',
            'endpoints' => [
                'authentication' => [
                    'register' => ['POST', '/api/auth/register', 'Register new user'],
                    'login' => ['POST', '/api/auth/login', 'User authentication'],
                    'logout' => ['POST', '/api/auth/logout', 'End user session'],
                    'session' => ['GET', '/api/auth/session', 'Get current session']
                ],
                'hotels' => [
                    'list' => ['GET', '/api/hotels', 'List all hotels'],
                    'get' => ['GET', '/api/hotels/{id}', 'Get hotel details'],
                    'rooms' => ['GET', '/api/hotels/{id}/rooms', 'Get hotel rooms'],
                    'create' => ['POST', '/api/hotels', 'Create new hotel (admin)'],
                    'update' => ['PATCH', '/api/hotels/{id}', 'Update hotel (admin/manager)']
                ],
                'bookings' => [
                    'list' => ['GET', '/api/bookings', 'List user bookings'],
                    'get' => ['GET', '/api/bookings/{id}', 'Get booking details'],
                    'create' => ['POST', '/api/bookings', 'Create new booking'],
                    'cancel' => ['POST', '/api/bookings/{id}/cancel', 'Cancel booking'],
                    'update' => ['PATCH', '/api/bookings/{id}', 'Update booking']
                ],
                'rooms' => [
                    'availability' => ['GET', '/api/rooms/{id}/availability', 'Check room availability'],
                    'create' => ['POST', '/api/hotels/{hotelId}/rooms', 'Add room (manager)'],
                    'update' => ['PATCH', '/api/rooms/{id}', 'Update room (manager)']
                ],
                'integration' => [
                    'status' => ['GET', '/api/services/status', 'Check service status'],
                    'discover' => ['GET', '/api/services/discover', 'Discover services'],
                    'book' => ['POST', '/api/services/book', 'Book external service'],
                    'options' => ['GET', '/api/services/{service}/options', 'Get service options']
                ]
            ]
        ]
    ],
    
    'consumed_services' => [
        'taxi' => array_merge([
            'provider_groups' => ['group4', 'group8'],
            'name' => 'Taxi Booking Service',
            'description' => 'Taxi booking and transportation service',
            'endpoints' => [
                'list' => ['GET', '/taxis', 'Get available taxis'],
                'book' => ['POST', '/taxis/book', 'Book a taxi'],
                'status' => ['GET', '/status', 'Service status'],
                'cancel' => ['POST', '/bookings/{id}/cancel', 'Cancel booking']
            ]
        ], $baseConfig['integrations']['taxi'] ?? []),
        
        'restaurant' => array_merge([
            'provider_groups' => ['group3', 'group7'],
            'name' => 'Restaurant Reservation Service',
            'description' => 'Restaurant table booking service',
            'endpoints' => [
                'list' => ['GET', '/restaurants', 'Get restaurants'],
                'book' => ['POST', '/restaurants/reserve', 'Reserve table'],
                'status' => ['GET', '/status', 'Service status'],
                'cancel' => ['POST', '/reservations/{id}/cancel', 'Cancel reservation']
            ]
        ], $baseConfig['integrations']['restaurant'] ?? []),
        
        'tour' => array_merge([
            'provider_groups' => ['group1', 'group5', 'group9'],
            'name' => 'Tour Booking Service',
            'description' => 'Tour packages and experiences',
            'endpoints' => [
                'list' => ['GET', '/tours', 'Get available tours'],
                'book' => ['POST', '/tours/book', 'Book a tour'],
                'status' => ['GET', '/status', 'Service status'],
                'cancel' => ['POST', '/bookings/{id}/cancel', 'Cancel tour']
            ]
        ], $baseConfig['integrations']['tour'] ?? [])
    ]
];

// Helper function to get config (if you have a getConfig() function)
function getConfig() {
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
        } else {
            $config = [];
        }
    }
    return $config;
}