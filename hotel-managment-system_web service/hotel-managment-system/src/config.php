<?php
// src/config.php
// Configuration now reads from environment variables (.env file)
// This keeps sensitive credentials out of source code
return [
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? 'sql113.infinityfree.com',
        'port' => $_ENV['DB_PORT'] ?? 3306,
        'name' => $_ENV['DB_NAME'] ?? 'if0_40766198_hotel_service_db',
        'user' => $_ENV['DB_USER'] ?? 'if0_40766198',
        'pass' => $_ENV['DB_PASS'] ?? '',  // No hardcoded password - must be in .env
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'HOTELSESS',
    ],
    
    // Integration settings
    'integrations' => [
        'use_real_api' => true,
        
        // Service configurations
        'taxi' => [
            'base_url' => 'https://taxi-system.infinityfreeapp.com/api',
            'api_key' => $_ENV['TAXI_API_KEY'] ?? 'TAXI_GROUP_SECURE_KEY_2024',
            'enabled' => true
        ],
        'restaurant' => [
            'base_url' => http://restaurant-management.page.gd/api/service-provider.php',
            'api_key' => $_ENV['RESTAURANT_API_KEY'] ?? 'TOUR_SERVICE_KEY_2025',
            'enabled' => true
        ],
        'tour' => [
            'base_url' => 'https://tour-management-web.onrender.com/api/v1',
            'api_key' => $_ENV['TOUR_API_KEY'] ?? 'demo-api-key',
            'enabled' => true
        ]
    ],
    
    // App settings
    'app' => [
        'name' => 'Hotel Management System',
        'version' => '1.0.0',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'timezone' => 'Africa/Addis_Ababa',
        'url' => $_ENV['APP_URL'] ?? 'http://localhost'
    ]
];