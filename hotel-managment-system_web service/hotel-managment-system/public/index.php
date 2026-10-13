<?php
// public/index.php
require_once __DIR__ . '/../src/router.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = strtok($_SERVER['REQUEST_URI'], '?');

// Debug: Log the URI
error_log("=== ROUTER DEBUG ===");
error_log("Request URI: $uri");
error_log("Request Method: $method");

// Strip project folder prefix
// Strip project folder prefix dynamically
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
// Ensure normalized slashes
$scriptDir = str_replace('\\', '/', $scriptDir);
$basePath = rtrim($scriptDir, '/');

if (strpos($uri, $basePath) === 0) {
    $uri = substr($uri, strlen($basePath));
}

// Normalize URI
$uri = rtrim($uri, '/');
if (empty($uri)) {
    $uri = '/';
}

error_log("Processed URI: $uri");

// Define routes - REORDERED FOR PROPER MATCHING
$routes = [
    'POST' => [
        '/api/auth/register' => 'AuthController@register',
        '/api/auth/login'    => 'AuthController@login',
        '/api/auth/logout'   => 'AuthController@logout',
        '/api/hotels'        => 'HotelController@create',
        '/api/hotels/{id}/rooms' => 'RoomController@create',
        '/api/bookings'      => 'BookingController@create',
        
        // ========== INTEGRATION BOOKING ENDPOINTS ==========
        '/api/services/taxis/book' => 'TaxiController@book',
        '/api/services/tours/book' => 'TourController@book',
        '/api/services/restaurants/book' => 'RestaurantController@book',
        
        // // Integration cancel endpoints
        // '/api/services/taxis/cancel' => 'TaxiController@cancel',
        // '/api/services/tours/cancel' => 'TourController@cancel',
        // '/api/services/restaurants/cancel' => 'RestaurantController@cancel',
        
        '/api/manager/staff' => 'ManagerController@addStaff',
        '/api/bookings/{id}/cancel'=> 'BookingController@cancel',
        '/api/manager/requests/{requestId}' => 'ManagerController@handleRequest',

        
    ],

    'GET' => [
        '/' => 'HomeController@index',
        '/login' => 'HomeController@loginPage',
        '/api/auth/session'  => 'AuthController@session',
        '/api/users'=> 'UserController@index',
        '/api/users/{id}'=> 'UserController@show',
        '/api/users/me'      => 'UserController@me',
        '/api/hotels'        => 'HotelController@index',
        '/api/hotels/{id}'   => 'HotelController@show',
        '/api/hotels/{id}/rooms' => 'RoomController@byHotel',
        '/api/rooms/{id}'    => 'RoomController@show',
        '/api/rooms/{id}/availability' => 'RoomController@availability',
        '/api/bookings'      => 'BookingController@index',
        '/api/bookings/{id}' => 'BookingController@show',
        '/api/stats/hotels/{id}'  => 'StatsController@hotelStats',
        '/api/stats/hotels/{id}/occupancy' => 'StatsController@occupancyRate',
        '/api/stats/hotels/{id}/revenue'   => 'StatsController@revenueTrend',
        '/api/stats/hotels/{id}/customers' => 'StatsController@topCustomers',
        '/api/stats/global' => 'StatsController@globalStats',
        '/api/admin/users'    => 'AdminController@users',
        '/api/admin/dashboard' => 'AdminController@dashboard',
        '/api/admin/bookings' => 'AdminController@bookings',
        
        // ========== RESTFUL INTEGRATION ENDPOINTS ==========
        // Taxi endpoints
        '/api/services/taxis' => 'TaxiController@index',
        '/api/services/taxis/status/{bookingId}' => 'TaxiController@status',
        
        // Tour endpoints
        '/api/services/tours' => 'TourController@index',
        '/api/services/tours/status/{bookingId}' => 'TourController@status',
        
        // Restaurant endpoints
        '/api/services/restaurants' => 'RestaurantController@index',
        '/api/services/restaurants/status/{bookingId}' => 'RestaurantController@status',
        
        // ========== INTEGRATION BOOKING HISTORY ENDPOINTS ==========
        // User's own integration bookings (taxi/restaurant/tour)
        '/api/integration-bookings' => 'IntegrationBookingController@getUserBookings',
        
        // Admin/Manager views all integration bookings
        '/api/admin/integration-bookings' => 'IntegrationBookingController@getAllBookings',
        '/api/admin/integration-bookings/stats' => 'IntegrationBookingController@getStatistics',


        // ========== OLD/LEGACY INTEGRATION ROUTES (keep for compatibility) ==========
        '/api/integrations/restaurants' => 'IntegrationsController@getRestaurants',
        '/api/integrations/taxis' => 'IntegrationsController@getTaxis',
        '/api/integrations/tours' => 'IntegrationsController@getTours',
        '/api/integrations/status' => 'IntegrationsController@getServicesStatus',
        
        // ========== OTHER ROUTES ==========
        '/api/amenities'     => 'AmenityController@index',
        '/api/amenities/{id}' => 'AmenityController@show',
        '/api/hotels/{hotelId}/amenities' => 'AmenityController@byHotel',
        '/api/manager/dashboard' => 'ManagerController@dashboard',
        '/api/manager/hotels' => 'ManagerController@myHotels',
        '/api/manager/bookings' => 'ManagerController@myBookings',
        '/api/manager/reviews' => 'ManagerController@myReviews',
        '/api/manager/hotels/{hotelId}/performance' => 'ManagerController@hotelPerformance',
        '/api/manager/requests/pending' => 'ManagerController@pendingRequests',
        '/api/manager/staff' => 'ManagerController@staff',
        '/api/manager/reports/daily' => 'ManagerController@dailyReport',
        
        '/api/bookings/{id}/status' => 'BookingController@updateStatus',
        
        '/api/test/users'=> 'UserController@test',
        '/api/test/bookings'=> 'BookingController@test',
         
        '/api/admin/hotels'   => 'AdminController@hotels',
        '/api/admin/stats'    => 'AdminController@stats',
        
        // Reviews
        '/api/reviews/hotel/{hotelId}' => 'ReviewController@getByHotel',
        '/api/reviews/manager' => 'ReviewController@getByManager',
        '/api/reviews/customer' => 'ReviewController@getByCustomer',
        '/api/reviews/report' => 'ReviewController@reviewReport',
        
        // ========== PARAMETERIZED ROUTES - COMES LAST ==========
        '/api/services/{service}/options' => 'IntegrationsController@getServiceOptions',
    ],

    'PATCH' => [
        '/api/rooms/{id}'  => 'RoomController@update',
        '/api/users/me'    => 'UserController@update',
        '/api/amenities/{id}' => 'AmenityController@update',
        '/api/hotels/{id}' => 'HotelController@update',
        '/api/bookings/{id}' => 'BookingController@update',
        '/api/manager/requests/{requestId}' => 'ManagerController@handleRequest',
        '/api/manager/staff/{id}' => 'ManagerController@updateStaff',
        '/api/rooms/{id}/availability'=> 'RoomController@setAvailability',
        
        '/api/bookings/{id}/status' => 'BookingController@updateStatus',
        '/api/integration-bookings/{id}/status' => 'IntegrationBookingController@updateStatus',

        '/api/reviews/{id}' => 'ReviewController@update',
    ],

    'DELETE' => [
        '/api/rooms/{id}'  => 'RoomController@delete',
        '/api/hotels/{id}' => 'HotelController@delete',
        '/api/amenities/{id}' => 'AmenityController@delete',
        '/api/bookings/{id}'=> 'BookingController@delete',
        '/api/manager/staff/{id}' => 'ManagerController@deleteStaff',
        '/api/users/{id}' => 'UserController@delete',

        '/api/reviews/{id}' => 'ReviewController@delete',
    ],
];

// Debug specific route
if (strpos($uri, '/api/services/') === 0) {
    error_log("SPECIAL DEBUG: Integration route detected: $uri");
}

// Route the request
route($method, $uri, $routes);