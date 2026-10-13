<?php
// src/controllers/StatsController.php
require_once __DIR__ . '/../bootstrap.php';

class StatsController {

  // ---------------- GET /api/hotels/{hotelId}/dashboard ----------------
  public function dashboard($hotelId) {
    start_session();
    $uid  = $_SESSION['uid'] ?? null;
    $role = $_SESSION['role'] ?? '';

    $hotelId = (int)$hotelId;
    $pdo = db();

    // Optional query params
    $availabilityDays = isset($_GET['availability_days']) ? (int)$_GET['availability_days'] : 7;
    $availabilityDays = max(1, min($availabilityDays, 30)); // Limit: 1–30 days
    $upcomingLimit = isset($_GET['upcoming_bookings']) ? (int)$_GET['upcoming_bookings'] : 10;
    $upcomingLimit = max(1, min($upcomingLimit, 50)); // Limit: 1–50 bookings

    // ---- 1. Check hotel existence ----
    $chk = $pdo->prepare("SELECT id FROM hotels WHERE id = ?");
    $chk->execute([$hotelId]);
    if (!$chk->fetch()) return json(['error' => 'Hotel not found'], 404);

    // ---- 2. Managers can only view their own hotel ----
    if ($role === 'manager') {
      $own = $pdo->prepare("SELECT 1 FROM hotels WHERE id=? AND manager_id=?");
      $own->execute([$hotelId, $uid]);
      if (!$own->fetch()) return json(['error' => 'Forbidden'], 403);
    }

    // ---------------- 3. Basic stats ----------------
    $stmt = $pdo->prepare("
      SELECT 
        COUNT(*) AS total_bookings,
        COALESCE(SUM(price),0) AS total_revenue,
        COALESCE(AVG(DATEDIFF(check_out_date, check_in_date)),0) AS avg_stay_length
      FROM bookings
      WHERE hotel_id = ?
    ");
    $stmt->execute([$hotelId]);
    $stats = $stmt->fetch();

    // ---------------- 4. Occupancy rate ----------------
    $stmt = $pdo->prepare("
      SELECT 
        (SELECT COUNT(*) FROM bookings WHERE hotel_id=? AND status='confirmed') AS booked_rooms,
        (SELECT COUNT(*) FROM rooms WHERE hotel_id=?) AS total_rooms
    ");
    $stmt->execute([$hotelId, $hotelId]);
    $occupancy = $stmt->fetch();
    $stats['occupancy_rate'] = $occupancy['total_rooms'] > 0
      ? round(($occupancy['booked_rooms'] / $occupancy['total_rooms']) * 100, 2)
      : 0;

    // ---------------- 5. Revenue trend (monthly) ----------------
    $stmt = $pdo->prepare("
      SELECT DATE_FORMAT(check_in_date, '%Y-%m') AS month,
             COALESCE(SUM(price),0) AS revenue
      FROM bookings
      WHERE hotel_id=?
      GROUP BY month
      ORDER BY month ASC
    ");
    $stmt->execute([$hotelId]);
    $trend = $stmt->fetchAll();

    // ---------------- 6. Top customers ----------------
    $stmt = $pdo->prepare("
      SELECT u.id, u.name, u.email, COUNT(b.id) AS bookings_count
      FROM bookings b
      JOIN users u ON b.user_id = u.id
      WHERE b.hotel_id=?
      GROUP BY u.id, u.name, u.email
      ORDER BY bookings_count DESC
      LIMIT 5
    ");
    $stmt->execute([$hotelId]);
    $topCustomers = $stmt->fetchAll();

    // ---------------- 7. Rooms + availability ----------------
    $stmt = $pdo->prepare("
      SELECT id, room_type, room_number, price_per_night, capacity, status
      FROM rooms
      WHERE hotel_id = ?
    ");
    $stmt->execute([$hotelId]);
    $rooms = $stmt->fetchAll();

    $today = new DateTime();
    $dates = [];
    for ($i = 0; $i < $availabilityDays; $i++) {
      $dates[] = $today->modify($i === 0 ? 'now' : '+1 day')->format('Y-m-d');
    }

    foreach ($rooms as &$room) {
      if (!$dates) {
        $room['availability'] = [];
        continue;
      }

      $in = implode(',', array_fill(0, count($dates), '?'));
      $params = array_merge([$room['id']], $dates);

      $a = $pdo->prepare("
        SELECT date, is_available
        FROM room_availability
        WHERE room_id = ? AND date IN ($in)
        ORDER BY date ASC
      ");
      $a->execute($params);
      $room['availability'] = $a->fetchAll();
    }

    // ---------------- 8. Upcoming bookings ----------------
    $stmt = $pdo->prepare("
      SELECT id, user_id, check_in_date, check_out_date, price, status
      FROM bookings
      WHERE hotel_id = ? AND check_in_date >= CURDATE()
      ORDER BY check_in_date ASC
      LIMIT ?
    ");
    $stmt->execute([$hotelId, $upcomingLimit]);
    $upcomingBookings = $stmt->fetchAll();

    // ---------------- 9. Return combined dashboard ----------------
    return json([
      'status' => 'ok',
      'hotel_id' => $hotelId,
      'stats' => $stats,
      'revenue_trend' => $trend,
      'top_customers' => $topCustomers,
      'rooms' => $rooms,
      'upcoming_bookings' => $upcomingBookings
    ]);
  }
}
