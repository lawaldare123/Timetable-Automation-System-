<?php
// =============================================
//  room.php — Rooms API
//  Faculty Timetable Automation System
//  Only admins can add, edit, or delete
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
require_once 'db.php';

// -----------------------------------------------
// Session check — user must be logged in
// -----------------------------------------------
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in']);
    exit;
}

$method      = $_SERVER['REQUEST_METHOD'];
$currentRole = $_SESSION['role'];

// -----------------------------------------------
// Role guard — only admin can write
// Any logged-in user can read
// -----------------------------------------------
if (in_array($method, ['POST', 'PUT', 'DELETE']) && $currentRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Admin access required']);
    exit;
}

switch ($method) {

    // -----------------------------------------------
    // GET — Fetch all rooms OR a single room by ID
    //       Optionally filter by availability
    // -----------------------------------------------
    case 'GET':
        if (isset($_GET['id'])) {
            // Fetch one room
            $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $room = $stmt->fetch();

            if ($room) {
                echo json_encode($room);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Room not found']);
            }

        } elseif (isset($_GET['available'])) {
            // Fetch only available rooms (for timetable scheduling)
            $stmt = $pdo->query(
                "SELECT * FROM rooms
                 WHERE is_available = 1
                 ORDER BY room_name ASC"
            );
            echo json_encode($stmt->fetchAll());

        } else {
            // Fetch all rooms
            $stmt = $pdo->query("SELECT * FROM rooms ORDER BY room_name ASC");
            echo json_encode($stmt->fetchAll());
        }
        break;

    // -----------------------------------------------
    // POST — Add a new room
    // -----------------------------------------------
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        if (empty($data['room_name']) || empty($data['capacity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Room name and capacity are required']);
            exit;
        }

        // Map frontend display values to DB enum values
        $typeMap = [
            'Lecture Theatre' => 'lecture_hall',
            'Seminar Room'    => 'classroom',
            'Computer Lab'    => 'lab',
            'lecture_hall'    => 'lecture_hall',
            'classroom'       => 'classroom',
            'lab'             => 'lab'
        ];
        $roomType = $typeMap[$data['room_type'] ?? ''] ?? 'classroom';

        // Check duplicate room name
        $check = $pdo->prepare("SELECT id FROM rooms WHERE room_name = ?");
        $check->execute([trim($data['room_name'])]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'A room with this name already exists']);
            exit;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO rooms (room_name, capacity, room_type, is_available)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            trim($data['room_name']),
            (int) $data['capacity'],
            $roomType,
            isset($data['is_available']) ? (int) $data['is_available'] : 1
        ]);

        http_response_code(201);
        echo json_encode([
            'message' => 'Room created successfully',
            'id'      => $pdo->lastInsertId()
        ]);
        break;

    // -----------------------------------------------
    // PUT — Update an existing room
    // -----------------------------------------------
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Room ID is required']);
            exit;
        }

        // Check room exists
        $check = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
        $check->execute([$data['id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Room not found']);
            exit;
        }

        // Validate required fields
        if (empty($data['room_name']) || empty($data['capacity'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Room name and capacity are required']);
            exit;
        }

        // Map frontend display values to DB enum values
        $typeMap = [
            'Lecture Theatre' => 'lecture_hall',
            'Seminar Room'    => 'classroom',
            'Computer Lab'    => 'lab',
            'lecture_hall'    => 'lecture_hall',
            'classroom'       => 'classroom',
            'lab'             => 'lab'
        ];
        $roomType = $typeMap[$data['room_type'] ?? ''] ?? 'classroom';

        // Check duplicate name (excluding current room)
        $dupCheck = $pdo->prepare("SELECT id FROM rooms WHERE room_name = ? AND id != ?");
        $dupCheck->execute([trim($data['room_name']), $data['id']]);
        if ($dupCheck->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Another room with this name already exists']);
            exit;
        }

        $stmt = $pdo->prepare(
            "UPDATE rooms
             SET room_name    = ?,
                 capacity     = ?,
                 room_type    = ?,
                 is_available = ?
             WHERE id = ?"
        );
        $stmt->execute([
            trim($data['room_name']),
            (int) $data['capacity'],
            $roomType,
            isset($data['is_available']) ? (int) $data['is_available'] : 1,
            $data['id']
        ]);

        echo json_encode(['message' => 'Room updated successfully']);
        break;

    // -----------------------------------------------
    // DELETE — Remove a room
    // -----------------------------------------------
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Room ID is required']);
            exit;
        }

        // Check room exists
        $check = $pdo->prepare("SELECT id FROM rooms WHERE id = ?");
        $check->execute([$data['id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Room not found']);
            exit;
        }

        // Check if room is assigned in the timetable
        $inUse = $pdo->prepare("SELECT id FROM timetable WHERE room_id = ? LIMIT 1");
        $inUse->execute([$data['id']]);
        if ($inUse->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot delete room — it is currently assigned in the timetable']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$data['id']]);

        echo json_encode(['message' => 'Room deleted successfully']);
        break;

    // -----------------------------------------------
    // Unsupported method
    // -----------------------------------------------
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
?>