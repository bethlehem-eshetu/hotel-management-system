<?php
// src/controllers/ReviewController.php
require_once __DIR__ . '/../bootstrap.php';

class ReviewController {

    // ---------------- GET /api/reviews/hotel/{hotelId} ----------------
    public function getByHotel($hotelId = null) {
        start_session();
        
        if (!$hotelId && isset($_GET['hotel_id'])) {
            $hotelId = $_GET['hotel_id'];
        }
        
        if (!$hotelId) {
            return json(['error' => 'Hotel ID required'], 400);
        }
        
        $pdo = db();
        
        // Get reviews with customer info
        $stmt = $pdo->prepare("
            SELECT 
                rv.*,
                u.name as customer_name,
                u.email as customer_email,
                u.profile_picture as customer_avatar,
                h.name as hotel_name
            FROM reviews rv
            JOIN users u ON rv.customer_id = u.id
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE rv.hotel_id = ?
            ORDER BY rv.created_at DESC
        ");
        $stmt->execute([$hotelId]);
        $reviews = $stmt->fetchAll();
        
        // Calculate average rating
        $avgRating = $pdo->prepare("
            SELECT 
                COALESCE(AVG(rating), 0) as avg_rating,
                COUNT(*) as total_reviews,
                COUNT(CASE WHEN rating = 5 THEN 1 END) as five_star,
                COUNT(CASE WHEN rating = 4 THEN 1 END) as four_star,
                COUNT(CASE WHEN rating = 3 THEN 1 END) as three_star,
                COUNT(CASE WHEN rating = 2 THEN 1 END) as two_star,
                COUNT(CASE WHEN rating = 1 THEN 1 END) as one_star
            FROM reviews 
            WHERE hotel_id = ?
        ");
        $avgRating->execute([$hotelId]);
        $ratingStats = $avgRating->fetch();
        
        return json([
            'status' => 'ok',
            'hotel_id' => $hotelId,
            'summary' => [
                'average_rating' => round($ratingStats['avg_rating'], 1),
                'total_reviews' => (int)$ratingStats['total_reviews'],
                'rating_breakdown' => [
                    '5_star' => (int)$ratingStats['five_star'],
                    '4_star' => (int)$ratingStats['four_star'],
                    '3_star' => (int)$ratingStats['three_star'],
                    '2_star' => (int)$ratingStats['two_star'],
                    '1_star' => (int)$ratingStats['one_star']
                ]
            ],
            'reviews' => $reviews
        ]);
    }

    // ---------------- GET /api/reviews/manager ----------------
    public function getByManager() {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        
        // Get all reviews for manager's hotels
        $stmt = $pdo->prepare("
            SELECT 
                rv.*,
                u.name as customer_name,
                u.email as customer_email,
                h.name as hotel_name,
                h.city as hotel_city,
                h.manager_id
            FROM reviews rv
            JOIN users u ON rv.customer_id = u.id
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE h.manager_id = ?
            ORDER BY 
                CASE WHEN rv.reply IS NULL THEN 0 ELSE 1 END,
                rv.created_at DESC
        ");
        $stmt->execute([$uid]);
        $reviews = $stmt->fetchAll();
        
        // Count un-replied reviews
        $unreplied = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM reviews rv
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE h.manager_id = ? AND rv.reply IS NULL
        ");
        $unreplied->execute([$uid]);
        $unrepliedCount = $unreplied->fetch()['count'];
        
        // Get average rating for each hotel
        $hotelRatings = $pdo->prepare("
            SELECT 
                h.id as hotel_id,
                h.name as hotel_name,
                COALESCE(AVG(rv.rating), 0) as avg_rating,
                COUNT(rv.id) as review_count
            FROM hotels h
            LEFT JOIN reviews rv ON h.id = rv.hotel_id
            WHERE h.manager_id = ?
            GROUP BY h.id
            ORDER BY avg_rating DESC
        ");
        $hotelRatings->execute([$uid]);
        
        return json([
            'status' => 'ok',
            'manager_id' => $uid,
            'summary' => [
                'total_reviews' => count($reviews),
                'unreplied_reviews' => (int)$unrepliedCount,
                'average_rating' => count($reviews) > 0 
                    ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1)
                    : 0
            ],
            'hotel_ratings' => $hotelRatings->fetchAll(),
            'reviews' => $reviews
        ]);
    }

    // ---------------- GET /api/reviews/customer ----------------
    public function getByCustomer() {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'customer') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                rv.*,
                h.name as hotel_name,
                h.city as hotel_city,
                h.country as hotel_country,
                h.thumbnail as hotel_image,
                b.check_in_date,
                b.check_out_date
            FROM reviews rv
            JOIN hotels h ON rv.hotel_id = h.id
            LEFT JOIN bookings b ON rv.booking_id = b.id
            WHERE rv.customer_id = ?
            ORDER BY rv.created_at DESC
        ");
        $stmt->execute([$uid]);
        
        return json([
            'status' => 'ok',
            'customer_id' => $uid,
            'reviews' => $stmt->fetchAll()
        ]);
    }

    // ---------------- POST /api/reviews ----------------
    public function create() {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'customer') {
            return json(['error' => 'Only customers can submit reviews'], 403);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validation
        $required = ['hotel_id', 'rating'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return json(['error' => "Missing field: {$field}"], 400);
            }
        }
        
        if ($data['rating'] < 1 || $data['rating'] > 5) {
            return json(['error' => 'Rating must be between 1 and 5'], 400);
        }
        
        $pdo = db();
        
        // Check if customer has stayed at this hotel (optional)
        $hasBooking = $pdo->prepare("
            SELECT 1 FROM bookings 
            WHERE customer_id = ? AND hotel_id = ? 
            AND status IN ('confirmed', 'completed')
            AND check_out_date <= CURDATE()
            LIMIT 1
        ");
        $hasBooking->execute([$uid, $data['hotel_id']]);
        
        // You can comment this out if you want to allow reviews without booking
        // if (!$hasBooking->fetch()) {
        //     return json(['error' => 'You must have stayed at this hotel to review it'], 400);
        // }
        
        // Check for existing review for this hotel by this customer
        $existing = $pdo->prepare("
            SELECT 1 FROM reviews 
            WHERE customer_id = ? AND hotel_id = ?
        ");
        $existing->execute([$uid, $data['hotel_id']]);
        
        if ($existing->fetch()) {
            return json(['error' => 'You have already reviewed this hotel'], 409);
        }
        
        // Create review
        $stmt = $pdo->prepare("
            INSERT INTO reviews 
            (hotel_id, customer_id, booking_id, rating, comment, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $success = $stmt->execute([
            $data['hotel_id'],
            $uid,
            $data['booking_id'] ?? null,
            $data['rating'],
            $data['comment'] ?? null
        ]);
        
        if ($success) {
            $reviewId = $pdo->lastInsertId();
            
            // Update hotel average rating cache (optional optimization)
            $updateHotel = $pdo->prepare("
                UPDATE hotels 
                SET average_rating = (
                    SELECT COALESCE(AVG(rating), 0) 
                    FROM reviews 
                    WHERE hotel_id = ?
                )
                WHERE id = ?
            ");
            $updateHotel->execute([$data['hotel_id'], $data['hotel_id']]);
            
            return json([
                'status' => 'ok',
                'message' => 'Review submitted successfully',
                'review_id' => $reviewId
            ], 201);
        } else {
            return json(['error' => 'Failed to submit review'], 500);
        }
    }

    // ---------------- POST /api/reviews/{reviewId}/reply ----------------
    public function addReply($reviewId = null) {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Only managers can reply to reviews'], 403);
        }
        
        if (!$reviewId) {
            return json(['error' => 'Review ID required'], 400);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['reply'])) {
            return json(['error' => 'Reply content required'], 400);
        }
        
        $pdo = db();
        
        // Verify manager owns this review (through hotel)
        $check = $pdo->prepare("
            SELECT rv.id, h.manager_id
            FROM reviews rv
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE rv.id = ?
        ");
        $check->execute([$reviewId]);
        $review = $check->fetch();
        
        if (!$review) {
            return json(['error' => 'Review not found'], 404);
        }
        
        if ($review['manager_id'] != $uid) {
            return json(['error' => 'You can only reply to reviews for your hotels'], 403);
        }
        
        // Check if already replied
        if (!empty($review['reply'])) {
            return json(['error' => 'Already replied to this review'], 409);
        }
        
        // Add reply
        $stmt = $pdo->prepare("
            UPDATE reviews 
            SET reply = ?, 
                reply_by = ?, 
                replied_at = NOW()
            WHERE id = ?
        ");
        
        $success = $stmt->execute([
            trim($data['reply']),
            $uid,
            $reviewId
        ]);
        
        if ($success) {
            // Log manager action
            $log = $pdo->prepare("
                INSERT INTO manager_actions 
                (manager_id, action_type, target_id, details, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $log->execute([
                $uid,
                'review_reply',
                $reviewId,
                json_encode(['review_id' => $reviewId, 'hotel_id' => $review['hotel_id']])
            ]);
            
            return json([
                'status' => 'ok',
                'message' => 'Reply added successfully',
                'review_id' => $reviewId
            ]);
        } else {
            return json(['error' => 'Failed to add reply'], 500);
        }
    }

    // ---------------- PATCH /api/reviews/{reviewId} ----------------
    public function update($reviewId = null) {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if (!$reviewId) {
            return json(['error' => 'Review ID required'], 400);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $pdo = db();
        
        // Verify ownership
        $check = $pdo->prepare("
            SELECT customer_id, hotel_id FROM reviews WHERE id = ?
        ");
        $check->execute([$reviewId]);
        $review = $check->fetch();
        
        if (!$review) {
            return json(['error' => 'Review not found'], 404);
        }
        
        // Allow update if: customer owns review OR manager owns the hotel
        $canUpdate = false;
        
        if ($role === 'customer' && $review['customer_id'] == $uid) {
            $canUpdate = true;
        } else if ($role === 'manager') {
            $checkManager = $pdo->prepare("
                SELECT 1 FROM hotels 
                WHERE id = ? AND manager_id = ?
            ");
            $checkManager->execute([$review['hotel_id'], $uid]);
            $canUpdate = (bool)$checkManager->fetch();
        }
        
        if (!$canUpdate) {
            return json(['error' => 'Forbidden'], 403);
        }
        
        // Build update query
        $fields = [];
        $params = [];
        
        if (isset($data['rating'])) {
            if ($data['rating'] < 1 || $data['rating'] > 5) {
                return json(['error' => 'Rating must be between 1 and 5'], 400);
            }
            $fields[] = 'rating = ?';
            $params[] = $data['rating'];
        }
        
        if (isset($data['comment'])) {
            $fields[] = 'comment = ?';
            $params[] = trim($data['comment']);
        }
        
        // Only managers can update reply
        if ($role === 'manager' && isset($data['reply'])) {
            $fields[] = 'reply = ?';
            $params[] = trim($data['reply']);
        }
        
        if (empty($fields)) {
            return json(['error' => 'No data to update'], 400);
        }
        
        $fields[] = 'updated_at = NOW()';
        $params[] = $reviewId;
        
        $stmt = $pdo->prepare("
            UPDATE reviews 
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ");
        
        $success = $stmt->execute($params);
        
        if ($success) {
            // Update hotel average rating cache
            $updateHotel = $pdo->prepare("
                UPDATE hotels 
                SET average_rating = (
                    SELECT COALESCE(AVG(rating), 0) 
                    FROM reviews 
                    WHERE hotel_id = ?
                )
                WHERE id = ?
            ");
            $updateHotel->execute([$review['hotel_id'], $review['hotel_id']]);
            
            return json([
                'status' => 'ok',
                'message' => 'Review updated successfully',
                'review_id' => $reviewId
            ]);
        } else {
            return json(['error' => 'Failed to update review'], 500);
        }
    }

    // ---------------- DELETE /api/reviews/{reviewId} ----------------
    public function delete($reviewId = null) {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if (!$reviewId) {
            return json(['error' => 'Review ID required'], 400);
        }
        
        $pdo = db();
        
        // Verify ownership
        $check = $pdo->prepare("
            SELECT rv.*, h.manager_id
            FROM reviews rv
            LEFT JOIN hotels h ON rv.hotel_id = h.id
            WHERE rv.id = ?
        ");
        $check->execute([$reviewId]);
        $review = $check->fetch();
        
        if (!$review) {
            return json(['error' => 'Review not found'], 404);
        }
        
        // Allow delete if: customer owns review OR manager owns the hotel OR admin
        $canDelete = false;
        
        if ($role === 'customer' && $review['customer_id'] == $uid) {
            $canDelete = true;
        } else if ($role === 'manager' && $review['manager_id'] == $uid) {
            $canDelete = true;
        } else if ($role === 'admin') {
            $canDelete = true;
        }
        
        if (!$canDelete) {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $hotelId = $review['hotel_id'];
        
        // Delete review
        $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
        $success = $stmt->execute([$reviewId]);
        
        if ($success) {
            // Update hotel average rating cache
            $updateHotel = $pdo->prepare("
                UPDATE hotels 
                SET average_rating = (
                    SELECT COALESCE(AVG(rating), 0) 
                    FROM reviews 
                    WHERE hotel_id = ?
                )
                WHERE id = ?
            ");
            $updateHotel->execute([$hotelId, $hotelId]);
            
            // Log deletion
            if ($role === 'manager') {
                $log = $pdo->prepare("
                    INSERT INTO manager_actions 
                    (manager_id, action_type, target_id, details, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $log->execute([
                    $uid,
                    'review_deleted',
                    $reviewId,
                    json_encode(['hotel_id' => $hotelId, 'customer_id' => $review['customer_id']])
                ]);
            }
            
            return json([
                'status' => 'ok',
                'message' => 'Review deleted successfully'
            ]);
        } else {
            return json(['error' => 'Failed to delete review'], 500);
        }
    }

    // ---------------- GET /api/reports/reviews ----------------
    public function reviewReport() {
        start_session();
        
        $role = $_SESSION['role'] ?? '';
        $uid = $_SESSION['uid'] ?? null;
        
        if ($role !== 'manager') {
            return json(['error' => 'Forbidden'], 403);
        }
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01'); // Start of current month
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $pdo = db();
        
        // Review trends over time
        $trends = $pdo->prepare("
            SELECT 
                DATE_FORMAT(rv.created_at, '%Y-%m') as month,
                COUNT(*) as review_count,
                AVG(rv.rating) as avg_rating,
                COUNT(CASE WHEN rv.reply IS NOT NULL THEN 1 END) as replied_count
            FROM reviews rv
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE h.manager_id = ?
                AND rv.created_at BETWEEN ? AND ?
            GROUP BY month
            ORDER BY month
        ");
        $trends->execute([$uid, $startDate, $endDate]);
        
        // Hotel-wise review stats
        $hotelStats = $pdo->prepare("
            SELECT 
                h.id,
                h.name,
                COUNT(rv.id) as total_reviews,
                COALESCE(AVG(rv.rating), 0) as avg_rating,
                COUNT(CASE WHEN rv.rating = 5 THEN 1 END) as five_star,
                COUNT(CASE WHEN rv.rating = 4 THEN 1 END) as four_star,
                COUNT(CASE WHEN rv.rating = 3 THEN 1 END) as three_star,
                COUNT(CASE WHEN rv.rating = 2 THEN 1 END) as two_star,
                COUNT(CASE WHEN rv.rating = 1 THEN 1 END) as one_star,
                COUNT(CASE WHEN rv.reply IS NULL THEN 1 END) as unreplied
            FROM hotels h
            LEFT JOIN reviews rv ON h.id = rv.hotel_id
            WHERE h.manager_id = ?
                AND (rv.created_at BETWEEN ? AND ? OR rv.id IS NULL)
            GROUP BY h.id
            ORDER BY avg_rating DESC
        ");
        $hotelStats->execute([$uid, $startDate, $endDate]);
        
        // Recent negative reviews (rating <= 2)
        $negativeReviews = $pdo->prepare("
            SELECT rv.*, u.name as customer_name, h.name as hotel_name
            FROM reviews rv
            JOIN users u ON rv.customer_id = u.id
            JOIN hotels h ON rv.hotel_id = h.id
            WHERE h.manager_id = ?
                AND rv.rating <= 2
                AND rv.created_at BETWEEN ? AND ?
            ORDER BY rv.created_at DESC
            LIMIT 10
        ");
        $negativeReviews->execute([$uid, $startDate, $endDate]);
        
        return json([
            'status' => 'ok',
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'trends' => $trends->fetchAll(),
            'hotel_stats' => $hotelStats->fetchAll(),
            'negative_reviews' => $negativeReviews->fetchAll(),
            'summary' => [
                'total_hotels' => count($hotelStats->fetchAll()),
                'total_reviews' => array_sum(array_column($trends->fetchAll(), 'review_count')),
                'average_rating' => $trends->fetchAll() ? 
                    round(array_sum(array_column($trends->fetchAll(), 'avg_rating')) / count($trends->fetchAll()), 1) : 0
            ]
        ]);
    }
}