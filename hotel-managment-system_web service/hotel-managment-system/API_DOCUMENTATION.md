# Hotel Management System API Documentation

This document describes the API endpoints available for the Hotel Management System.

## Base URL
The API is accessible at `/api`. All requests should include the `Accept: application/json` header. For `POST`, `PATCH`, and `PUT` requests, include `Content-Type: application/json`.

## Authentication
The API uses session-based authentication.

### Login
**POST** `/api/auth/login`
- **Body**: `{"email": "...", "password": "..."}`
- **Response**: `200 OK` with user details and a session cookie.

### Logout
**POST** `/api/auth/logout`
- **Response**: `200 OK`.

### Register
**POST** `/api/auth/register`
- **Body**: `{"email": "...", "password": "...", "name": "...", "phone": "..."}`
- **Response**: `200 OK` or `201 Created`.

### Check Session
**GET** `/api/auth/session`
- **Response**: `200 OK` with current user info if logged in, `401 Unauthorized` otherwise.

---

## Hotels

### List Hotels
**GET** `/api/hotels`
- **Response**: Array of hotels.

### Get Hotel Details
**GET** `/api/hotels/{id}`
- **Response**: Detailed hotel object including rooms, amenities, and calculated `price_per_night` (cheapest room price).

### Create Hotel (Admin/Manager)
**POST** `/api/hotels`
- **Body**: `{"name": "...", "description": "...", "address": "...", "city": "...", "country": "...", "contact_phone": "...", "email": "...", "amenities_text": "..."}`

### Update Hotel (Admin/Manager)
**PATCH** `/api/hotels/{id}`
- **Body**: Same fields as Create Hotel (all optional).

### Delete Hotel (Admin)
**DELETE** `/api/hotels/{id}`

---

## Rooms

### List Rooms by Hotel
**GET** `/api/hotels/{id}/rooms`
- **Response**: Array of rooms for the specified hotel.

### Get Room Details
**GET** `/api/rooms/{id}`
- **Response**: Room object with hotel details and 30-day availability.

### Create Room (Admin/Manager)
**POST** `/api/hotels/{id}/rooms`
- **Body**: `{"room_type": "...", "room_number": "...", "price_per_night": 100.00, "capacity": 2, "status": "available"}`

### Update Room (Admin/Manager)
**PATCH** `/api/rooms/{id}`
- **Body**: Same fields as Create Room.

### Set Room Availability (Admin/Manager)
**PATCH** `/api/rooms/{id}/availability`
- **Body**: `{"date": "YYYY-MM-DD", "is_available": true/false}`

### Get Room Availability
**GET** `/api/rooms/{id}/availability`
- **Response**: Array of availability records from current date.

---

## Bookings

### Create Booking
**POST** `/api/bookings`
- **Body**: `{"room_id": 123, "check_in_date": "YYYY-MM-DD", "check_out_date": "YYYY-MM-DD", "adults": 2, "children": 0, "special_requests": "..."}`
- **Note**: Dates must have availability in `room_availability` table.

### List Bookings
**GET** `/api/bookings`
- **Admin**: Returns all bookings.
- **Manager**: Returns bookings for their hotels.
- **Customer**: Returns their own bookings.

### Get Booking Details
**GET** `/api/bookings/{id}`

### Update Booking Status (Admin/Manager/Customer)
**PATCH** `/api/bookings/{id}/status`
- **Body**: `{"status": "confirmed/cancelled/checked_in/checked_out/completed", "cancellation_reason": "..."}`
- **Rules**:
  - Customers can only change status to `cancelled`.
  - Cancellation is only allowed before the check-in date.

### Cancel Booking (Customer)
**POST** `/api/bookings/{id}/cancel`
- **Body**: `{"reason": "..."}`

---

## Service Integrations (Exeternal)
These endpoints interact with external service providers (Taxis, Restaurants, Tours).

### Taxis
- **GET** `/api/services/taxis`: List available taxi services.
- **POST** `/api/services/taxis/book`: Book a taxi.
- **GET** `/api/services/taxis/status/{bookingId}`: Check status.

### Restaurants
- **GET** `/api/services/restaurants`: List restaurants.
- **POST** `/api/services/restaurants/book`: Reserve a table.
- **GET** `/api/services/restaurants/status/{bookingId}`: Check status.

### Tours
- **GET** `/api/services/tours`: List available tours.
- **POST** `/api/services/tours/book`: Book a tour.
- **GET** `/api/services/tours/status/{bookingId}`: Check status.

### Integration History
- **GET** `/api/integration-bookings`: Returns all integration bookings for the current user.
- **GET** `/api/admin/integration-bookings`: List all integration bookings (Admin/Manager).

---

## Reviews

### Get Hotel Reviews
**GET** `/api/reviews/hotel/{hotelId}`

### Submit Review
**POST** `/api/reviews`
- **Body**: `{"hotel_id": 123, "rating": 5, "comment": "...", "booking_id": 456}`

### Reply to Review (Manager)
**POST** `/api/reviews/{reviewId}/reply`
- **Body**: `{"reply": "..."}`

---

## Error Response Format
All error responses follow this JSON structure:
```json
{
  "error": "Short error message",
  "message": "Detailed error description (optional)",
  "code": 401
}
```
Common Status Codes:
- `200/201`: Success
- `400`: Bad Request (Invalid parameters)
- `401`: Unauthorized (Login required)
- `403`: Forbidden (Insufficient permissions)
- `404`: Not Found
- `409`: Conflict (e.g., date already booked)
- `500`: Server Error
