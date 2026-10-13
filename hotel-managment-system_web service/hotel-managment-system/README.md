# EthioHotel Management System

EthioHotel is a comprehensive hotel management and booking platform designed for the Ethiopian hospitality industry. It provides a seamless interface for managing hotel reservations while integrating with external services like taxis, tours, and restaurants to offer a complete travel experience.

## 🌟 Key Features

### 🏨 Hotel Management
*   **Real-time Booking:** Browse and book rooms based on availability, capacity, and dates.
*   **Intelligent Validation:** Built-in checks for room capacity and date conflicts with user-friendly warning alerts.
*   **Booking Management:** Dedicated dashboard for customers to view, track, and cancel their hotel stays.

### 🚕 Integrated Services
*   **Taxi Service:** Real-time integration with external taxi APIs to book airport transfers and city travel.
*   **Restaurant Reservations:** Browse menus and book tables at top-rated local restaurants.
*   **Tour Booking:** Explore and book curated tours across famous Ethiopian landmarks.
*   **Unified Dashboard:** All external service bookings are tracked alongside hotel reservations in a single view.

### 👥 Role-Based Access Control
*   **Admin:** Full system oversight, user management, and global statistics.
*   **Manager:** Manage specific hotel operations, bookings, and reporting.
*   **Customer:** Personalized dashboard for booking hotels and integrated services.

## 🛠️ Technology Stack
*   **Backend:** PHP (Custom Routing & MVC Pattern)
*   **Frontend:** HTML5, Modern CSS (Vanilla), JavaScript
*   **Database:** MySQL
*   **Integrations:** RESTful API consumption for external travel services

## 🚀 Getting Started

### Prerequisites
*   Web server (Apache/Nginx with PHP 7.4+)
*   MySQL Database
*   PHP `curl` extension enabled

### Installation
1.  **Extract the project** into your server's root directory.
2.  **Configuration:**
    *   Locate `.env.example` and rename it to `.env`.
    *   Update the database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) in the `.env` file.
    *   Configure your `APP_URL` to match your local environment.
3.  **Database Setup:**
    *   Import the SQL schema provided in the `/database` folder into your MySQL server.
4.  **Launch:**
    *   Navigate to your `APP_URL` in a web browser.
    *   Default login credentials can be found in the system documentation (if applicable).

## 🛡️ Security & Performance
*   **Session Management:** Secure cookie-based authentication.
*   **Error Handling:** Custom middleware to handle API failures and database conflicts gracefully.
*   **Performance:** Optimized card-based UI with responsive design for all devices.

---
*Created for the EthioHotel Management Project.*
