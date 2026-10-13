<?php
// Root index.php - Main Entry Point for InfinityFree Deployment

// Quick check: if requesting root, redirect to login page
if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '') {
    header('Location: /pages/login.html');
    exit;
}

// For all other requests, load the router
require_once __DIR__ . '/src/router.php';

$method = $_SERVER['REQUEST_METHOD'];
$uri = strtok($_SERVER['REQUEST_URI'], '?');

// Routes Definition
$routes = [
    'POST' => [
        '/api/auth/register' => 'AuthController@register',
        '/api/auth/login'    => 'AuthController@login',
        '/api/auth/logout'   => 'AuthController@logout',
        '/api/hotels'        => 'HotelController@create',
        '/api/hotels/{id}/rooms' => 'RoomController@create',
        '/api/bookings'      => 'BookingController@create',
        
        '/api/services/taxis/book' => 'TaxiController@book',
        '/api/services/tours/book' => 'TourController@book',
        '/api/services/restaurants/book' => 'RestaurantController@book',
        
        '/api/manager/staff' => 'ManagerController@addStaff',
        '/api/bookings/{id}/cancel'=> 'BookingController@cancel',
        '/api/manager/requests/{requestId}' => 'ManagerController@handleRequest',
    ],

    'GET' => [
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
        
        '/api/services/taxis' => 'TaxiController@index',
        '/api/services/taxis/status/{bookingId}' => 'TaxiController@status',
        '/api/services/tours' => 'TourController@index',
        '/api/services/tours/status/{bookingId}' => 'TourController@status',
        '/api/services/restaurants' => 'RestaurantController@index',
        '/api/services/restaurants/status/{bookingId}' => 'RestaurantController@status',
        
        '/api/integration-bookings' => 'IntegrationBookingController@getUserBookings',
        '/api/admin/integration-bookings' => 'IntegrationBookingController@getAllBookings',
        '/api/admin/integration-bookings/stats' => 'IntegrationBookingController@getStatistics',

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
        
        '/api/reviews/hotel/{hotelId}' => 'ReviewController@getByHotel',
        '/api/reviews/manager' => 'ReviewController@getByManager',
        '/api/reviews/customer' => 'ReviewController@getByCustomer',
        '/api/reviews/report' => 'ReviewController@reviewReport',
        
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

// Dispatch Request
route($method, $uri, $routes);
