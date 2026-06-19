<?php
// =============================================
//  lecturer.php — Lecturers API
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
// Role guard — only admin can write (POST/PUT/DELETE)
// Any logged-in user can read (GET)
// -----------------------------------------------
$writeRoles = ['admin'];
if (in_array($method, ['POST', 'PUT', 'DELETE']) && !in_array($currentRole, $writeRoles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Admin access required']);
    exit;
}

switch ($method) {

    // -----------------------------------------------
    // GET — Fetch all lecturers OR a single lecturer
    // -----------------------------------------------
    case 'GET':
        if (isset($_GET['id'])) {
            // Fetch one lecturer with user and department info
            $stmt = $pdo->prepare(
                "SELECT l.id, l.staff_id, l.specialization,
                        u.full_name, u.email,
                        d.department_name, d.department_code
                 FROM lecturers l
                 JOIN users       u ON l.user_id       = u.id
                 LEFT JOIN departments d ON l.department_id = d.id
                 WHERE l.id = ?"
            );
            $stmt->execute([$_GET['id']]);
            $lecturer = $stmt->fetch();

            if ($lecturer) {
                echo json_encode($lecturer);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Lecturer not found']);
            }
        } else {
            // Fetch all lecturers with user and department info
            $stmt = $pdo->query(
                "SELECT l.id, l.staff_id, l.specialization,
                        u.full_name, u.email,
                        d.department_name, d.department_code
                 FROM lecturers l
                 JOIN users       u ON l.user_id       = u.id
                 LEFT JOIN departments d ON l.department_id = d.id
                 ORDER BY u.full_name ASC"
            );
            echo json_encode($stmt->fetchAll());
        }
        break;

    // -----------------------------------------------
    // POST — Add a new lecturer
    //  Creates both a user account and a lecturer profile
    // -----------------------------------------------
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        $required = ['full_name', 'email', 'password', 'staff_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Field '$field' is required"]);
                exit;
            }
        }

        $email   = strtolower(trim($data['email']));
        $staffId = strtoupper(trim($data['staff_id']));

        // Check duplicate email
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'A user with this email already exists']);
            exit;
        }

        // Check duplicate staff ID
        $check2 = $pdo->prepare("SELECT id FROM lecturers WHERE staff_id = ?");
        $check2->execute([$staffId]);
        if ($check2->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'A lecturer with this staff ID already exists']);
            exit;
        }

        // Use a transaction — both inserts must succeed together
        $pdo->beginTransaction();
        try {
            // Step 1: Create the user account
            $userStmt = $pdo->prepare(
                "INSERT INTO users (full_name, email, password, role, is_active)
                 VALUES (?, ?, ?, 'lecturer', 1)"
            );
            $userStmt->execute([
                trim($data['full_name']),
                $email,
                password_hash($data['password'], PASSWORD_BCRYPT)
            ]);
            $userId = $pdo->lastInsertId();

            // Step 2: Create the lecturer profile
            $lecturerStmt = $pdo->prepare(
                "INSERT INTO lecturers (user_id, staff_id, department_id, specialization, max_hours_week, availability)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $lecturerStmt->execute([
                $userId,
                $staffId,
                $data['department_id']  ?? null,
                $data['specialization'] ?? null,
                $data['max_hours_week'] ?? 12,
                $data['availability']   ?? 'all'
            ]);
            $lecturerId = $pdo->lastInsertId();

            $pdo->commit();

            http_response_code(201);
            echo json_encode([
                'message'     => 'Lecturer created successfully',
                'lecturer_id' => $lecturerId,
                'user_id'     => $userId
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create lecturer. Please try again']);
        }
        break;

    // -----------------------------------------------
    // PUT — Update an existing lecturer
    // -----------------------------------------------
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Lecturer ID is required']);
            exit;
        }

        // Check lecturer exists and get linked user_id
        $check = $pdo->prepare("SELECT id, user_id FROM lecturers WHERE id = ?");
        $check->execute([$data['id']]);
        $existing = $check->fetch();

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Lecturer not found']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Update user account info if provided
            if (!empty($data['full_name']) || !empty($data['email'])) {
                $userStmt = $pdo->prepare(
                    "UPDATE users SET full_name = ?, email = ? WHERE id = ?"
                );
                $userStmt->execute([
                    trim($data['full_name']),
                    strtolower(trim($data['email'])),
                    $existing['user_id']
                ]);
            }

            // Update lecturer profile
            $lecturerStmt = $pdo->prepare(
                "UPDATE lecturers
                 SET staff_id       = ?,
                     department_id  = ?,
                     specialization = ?,
                     max_hours_week = ?,
                     availability   = ?
                 WHERE id = ?"
            );
            $lecturerStmt->execute([
                strtoupper(trim($data['staff_id'])),
                $data['department_id']  ?? null,
                $data['specialization'] ?? null,
                $data['max_hours_week'] ?? 12,
                $data['availability']   ?? 'all',
                $data['id']
            ]);

            $pdo->commit();
            echo json_encode(['message' => 'Lecturer updated successfully']);

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update lecturer. Please try again']);
        }
        break;

    // -----------------------------------------------
    // DELETE — Remove a lecturer and their user account
    // -----------------------------------------------
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Lecturer ID is required']);
            exit;
        }

        // Get linked user_id
        $check = $pdo->prepare("SELECT id, user_id FROM lecturers WHERE id = ?");
        $check->execute([$data['id']]);
        $existing = $check->fetch();

        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Lecturer not found']);
            exit;
        }

        // Deleting the user cascades and deletes the lecturer profile too
        // (because of ON DELETE CASCADE on lecturers.user_id)
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$existing['user_id']]);

        echo json_encode(['message' => 'Lecturer deleted successfully']);
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
