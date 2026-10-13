-- =============================================================================
-- HOTEL MANAGEMENT SYSTEM - COMPLETE INITIALIZATION SCRIPT
-- =============================================================================
-- Version: 1.1 (Consolidated)
-- Date: 2025-12-26
-- Description: Unified file containing both the Full Schema and Seed Data.
--              Ideal for fresh installations and production setup.
-- =============================================================================

-- 1. DATABASE CREATION (Optional - Uncomment if needed)
-- CREATE DATABASE IF NOT EXISTS hotel_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE hotel_system;

-- =============================================================================
-- PART 1: DATABASE SCHEMA (TABLES, INDEXES, CONSTRAINTS)
-- =============================================================================

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','manager','customer') NOT NULL DEFAULT 'customer',
    name VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(50) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hotels table
CREATE TABLE IF NOT EXISTS hotels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    address VARCHAR(255),
    city VARCHAR(100),
    country VARCHAR(100),
    manager_id INT,
    contact_phone VARCHAR(50),
    email VARCHAR(255),
    amenities_text TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_hotels_manager FOREIGN KEY (manager_id) 
        REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_hotels_city (city),
    INDEX idx_hotels_manager (manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Amenities table
CREATE TABLE IF NOT EXISTS amenities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hotel-Amenities junction table
CREATE TABLE IF NOT EXISTS hotel_amenities (
    hotel_id INT NOT NULL,
    amenity_id INT NOT NULL,
    PRIMARY KEY (hotel_id, amenity_id),
    CONSTRAINT fk_ha_hotel FOREIGN KEY (hotel_id) 
        REFERENCES hotels(id) ON DELETE CASCADE,
    CONSTRAINT fk_ha_amenity FOREIGN KEY (amenity_id) 
        REFERENCES amenities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rooms table
CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    room_type VARCHAR(50) NOT NULL,
    room_number VARCHAR(50) NOT NULL,
    price_per_night DECIMAL(10,2) NOT NULL,
    capacity INT NOT NULL DEFAULT 1,
    status ENUM('available','maintenance') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rooms_hotel FOREIGN KEY (hotel_id) 
        REFERENCES hotels(id) ON DELETE CASCADE,
    UNIQUE KEY uq_room_number_per_hotel (hotel_id, room_number),
    INDEX idx_rooms_hotel (hotel_id),
    INDEX idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Room availability table
CREATE TABLE IF NOT EXISTS room_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    date DATE NOT NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_av_room FOREIGN KEY (room_id) 
        REFERENCES rooms(id) ON DELETE CASCADE,
    UNIQUE KEY uq_room_date (room_id, date),
    INDEX idx_av_room_date (room_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bookings table
CREATE TABLE IF NOT EXISTS bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    hotel_id INT NOT NULL,
    room_id INT NOT NULL,
    check_in_date DATE NOT NULL,
    check_out_date DATE NOT NULL,
    nights INT NOT NULL,
    adults INT NOT NULL DEFAULT 1,
    children INT NOT NULL DEFAULT 0,
    total_price DECIMAL(10,2) NOT NULL,
    special_requests TEXT DEFAULT NULL,
    transaction_id VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bk_customer FOREIGN KEY (customer_id) 
        REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bk_hotel FOREIGN KEY (hotel_id) 
        REFERENCES hotels(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bk_room FOREIGN KEY (room_id) 
        REFERENCES rooms(id) ON DELETE RESTRICT,
    INDEX idx_bk_customer (customer_id),
    INDEX idx_bk_hotel (hotel_id),
    INDEX idx_bk_room (room_id),
    INDEX idx_bk_status (status),
    INDEX idx_bk_dates (check_in_date, check_out_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking history table
CREATE TABLE IF NOT EXISTS booking_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    action ENUM('created','confirmed','cancelled','completed') NOT NULL,
    changed_by INT NOT NULL,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_bh_booking FOREIGN KEY (booking_id) 
        REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_bh_user FOREIGN KEY (changed_by) 
        REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_bh_booking (booking_id),
    INDEX idx_bh_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integration bookings (Taxi, Restaurant, Tour services)
CREATE TABLE IF NOT EXISTS integration_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    service_type VARCHAR(50) NOT NULL COMMENT 'taxi, restaurant, or tour',
    request_data JSON COMMENT 'Original request sent to external API',
    response_data JSON COMMENT 'Response received from external API',
    simulated BOOLEAN DEFAULT FALSE COMMENT 'TRUE if API was unavailable (mock data)',
    external_booking_id VARCHAR(100) COMMENT 'Booking ID from external service',
    http_status_code INT COMMENT 'HTTP response code from external API',
    response_time_ms INT COMMENT 'API response time in milliseconds',
    status ENUM('pending', 'confirmed', 'cancelled', 'failed') DEFAULT 'confirmed',
    booked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ib_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_service (user_id, service_type),
    INDEX idx_booked_at (booked_at),
    INDEX idx_external_id (external_booking_id),
    INDEX idx_status (status),
    INDEX idx_service_type (service_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service status monitoring
CREATE TABLE IF NOT EXISTS service_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_name VARCHAR(50) NOT NULL,
    status ENUM('online', 'offline', 'degraded') NOT NULL,
    last_checked TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    response_time_ms INT,
    error_message TEXT,
    UNIQUE KEY unique_service (service_name),
    INDEX idx_status (status),
    INDEX idx_last_checked (last_checked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Service status logs (detailed history)
CREATE TABLE IF NOT EXISTS service_status_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_name VARCHAR(50) NOT NULL,
    status ENUM('online', 'offline', 'error', 'disabled', 'simulated') NOT NULL,
    response_time_ms INT,
    error_message TEXT,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT,
    CONSTRAINT fk_ssl_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_service_status (service_name, status),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integration configuration
CREATE TABLE IF NOT EXISTS integration_configs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    service_name VARCHAR(50) UNIQUE NOT NULL,
    base_url VARCHAR(255),
    api_key VARCHAR(255),
    enabled BOOLEAN DEFAULT TRUE,
    timeout_seconds INT DEFAULT 10,
    max_retries INT DEFAULT 2,
    cache_ttl INT DEFAULT 300,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_status VARCHAR(50),
    last_check TIMESTAMP NULL,
    INDEX idx_enabled (enabled),
    INDEX idx_service_name (service_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API logs
CREATE TABLE IF NOT EXISTS api_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    endpoint VARCHAR(500) NOT NULL,
    method VARCHAR(10) NOT NULL,
    request_body TEXT,
    response_body TEXT,
    status_code INT,
    response_time_ms INT,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_al_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_endpoint (endpoint(255)),
    INDEX idx_created_at (created_at),
    INDEX idx_status_code (status_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API response cache
CREATE TABLE IF NOT EXISTS api_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cache_key VARCHAR(255) NOT NULL UNIQUE,
    cache_data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    INDEX idx_cache_key (cache_key),
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visit statistics
CREATE TABLE IF NOT EXISTS visits_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    date DATE NOT NULL,
    visitors_count INT NOT NULL DEFAULT 0,
    occupancy_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_vs_hotel FOREIGN KEY (hotel_id) 
        REFERENCES hotels(id) ON DELETE CASCADE,
    UNIQUE KEY uq_hotel_date (hotel_id, date),
    INDEX idx_vs_hotel (hotel_id),
    INDEX idx_vs_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reviews table
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hotel_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT DEFAULT NULL,
    reply TEXT DEFAULT NULL,
    reply_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replied_at DATETIME NULL,
    CONSTRAINT fk_reviews_hotel FOREIGN KEY (hotel_id) 
        REFERENCES hotels(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) 
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_reply_by FOREIGN KEY (reply_by) 
        REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_reviews_hotel (hotel_id),
    INDEX idx_reviews_user (user_id),
    INDEX idx_reviews_rating (rating),
    INDEX idx_reviews_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PART 2: CORE SEED DATA (AMENITIES, CONFIGS, ADMIN)
-- =============================================================================

-- Insert default amenities
INSERT IGNORE INTO amenities (name, description) VALUES
    ('WiFi', 'High-speed wireless internet'),
    ('Pool', 'Swimming pool facility'),
    ('Gym', 'Fitness center'),
    ('Restaurant', 'On-site dining'),
    ('Parking', 'Free parking'),
    ('Spa', 'Spa and wellness center'),
    ('Bar', 'Bar and lounge'),
    ('Room Service', '24/7 room service'),
    ('Conference Room', 'Business meeting facilities'),
    ('Airport Shuttle', 'Airport transfer service');

-- Insert default service configurations
INSERT IGNORE INTO integration_configs (service_name, base_url, api_key, enabled) VALUES
    ('taxi', 'https://taxi-system.infinityfreeapp.com/api', 'TAXI_GROUP_SECURE_KEY_2024', TRUE),
    ('restaurant', 'https://restaurant-managment-system.free.nf/api/service-provider.php', 'HOTEL_SERVICE_KEY_2025', TRUE),
    ('tour', 'https://tour-management-web.onrender.com/api/v1', 'demo-api-key', TRUE);

-- Create Admin user (Default password: admin123)
INSERT IGNORE INTO users (email, password_hash, role, name, phone) VALUES
    ('admin@hotel.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator', '+251900000000');

-- =============================================================================
-- PART 3: EXTENDED DEMO DATA (HOTELS, ROOMS, MOCK BOOKINGS)
-- =============================================================================

-- Managers and Customers
-- Password for all: password123
INSERT IGNORE INTO users (email, password_hash, role, name, phone) VALUES
('manager1@hotel.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 'Abebe Kebede', '+251911234567'),
('manager2@hotel.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 'Tigist Haile', '+251922345678'),
('manager3@hotel.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 'Dawit Tesfaye', '+251933456789'),
('john.doe@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'John Doe', '+1234567890'),
('sarah.smith@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'Sarah Smith', '+1234567891'),
('michael.brown@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'Michael Brown', '+1234567892'),
('emma.wilson@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'customer', 'Emma Wilson', '+1234567893');

-- Hotels
INSERT IGNORE INTO hotels (id, name, description, address, city, country, manager_id, contact_phone, email, amenities_text) VALUES
(1, 'Skyline Grand Hotel', 'Luxury 5-star hotel in the heart of Addis Ababa.', 'Bole Road', 'Addis Ababa', 'Ethiopia', 2, '+251116123456', 'info@skylinegrand.et', 'WiFi, Pool, Gym, Restaurant, Spa, Bar, Room Service, Parking'),
(2, 'Blue Nile Resort', 'Lakeside resort offering stunning views of Lake Tana.', 'Lake Tana Road', 'Bahir Dar', 'Ethiopia', 3, '+251582201234', 'reservations@bluenileresort.et', 'WiFi, Pool, Restaurant, Bar, Parking'),
(3, 'Abyssinia Business Suites', 'Modern business hotel with state-of-the-art facilities.', 'Africa Avenue', 'Addis Ababa', 'Ethiopia', 4, '+251116345678', 'contact@abyssinia-suites.et', 'WiFi, Gym, Restaurant, Conference Room, Parking');

-- Amenities mapping for Skyline
INSERT IGNORE INTO hotel_amenities (hotel_id, amenity_id) SELECT 1, id FROM amenities;

-- Sample Rooms
INSERT IGNORE INTO rooms (id, hotel_id, room_type, room_number, price_per_night, capacity, status) VALUES
(1, 1, 'Standard Single', '101', 85.00, 1, 'available'),
(2, 1, 'Standard Double', '103', 120.00, 2, 'available'),
(3, 1, 'Deluxe Suite', '201', 200.00, 3, 'available'),
(4, 2, 'Lake View Single', '201', 75.00, 1, 'available'),
(5, 2, 'Lake View Double', '202', 110.00, 2, 'available');

-- Availability (Next 30 days)
INSERT IGNORE INTO room_availability (room_id, date, is_available)
SELECT r.id, DATE_ADD(CURDATE(), INTERVAL seq DAY), 1
FROM rooms r CROSS JOIN (
    SELECT 0 seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION
    SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION
    SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
) days;

-- =============================================================================
-- END OF SCRIPT
-- =============================================================================
