<?php
// =============================================
//  course.php — Courses API
//  Faculty Timetable Automation System
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request (sent by browsers before actual request)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // -----------------------------------------------
    // GET — Fetch all courses OR a single course by ID
    // -----------------------------------------------
    case 'GET':
        $baseQuery = "
            SELECT c.*,
                   u.full_name  AS lecturer_name,
                   l.id         AS lecturer_id_fk
            FROM courses c
            LEFT JOIN course_lecturers cl ON c.id = cl.course_id
            LEFT JOIN lecturers l         ON cl.lecturer_id = l.id
            LEFT JOIN users u             ON l.user_id = u.id
        ";

        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare($baseQuery . " WHERE c.id = ? LIMIT 1");
            $stmt->execute([$_GET['id']]);
            $course = $stmt->fetch();
            if ($course) {
                // Attach program codes
                $ps = $pdo->prepare(
                    "SELECT p.program_code FROM course_programs cp
                     JOIN programs p ON cp.program_id = p.id
                     WHERE cp.course_id = ?"
                );
                $ps->execute([$course['id']]);
                $course['programs'] = array_column($ps->fetchAll(), 'program_code');
                echo json_encode($course);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Course not found']);
            }
        } else {
            $stmt = $pdo->query($baseQuery . " ORDER BY c.course_name ASC");
            $courses = $stmt->fetchAll();

            // Fetch all program links in one query for efficiency
            $allLinks = $pdo->query(
                "SELECT cp.course_id, p.program_code
                 FROM course_programs cp
                 JOIN programs p ON cp.program_id = p.id"
            )->fetchAll();

            // Build a map: course_id => [program_codes]
            $progMap = [];
            foreach ($allLinks as $link) {
                $progMap[$link['course_id']][] = $link['program_code'];
            }

            foreach ($courses as &$course) {
                $course['programs'] = $progMap[$course['id']] ?? [];
            }
            echo json_encode($courses);
        }
        break;

    // -----------------------------------------------
    // POST — Add a new course
    // -----------------------------------------------
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        if (empty($data['course_code']) || empty($data['course_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Course code and course name are required']);
            break;
        }

        // Check for duplicate course code
        $check = $pdo->prepare("SELECT id FROM courses WHERE course_code = ?");
        $check->execute([$data['course_code']]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'A course with this code already exists']);
            break;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO courses (course_code, course_name, credit_units, department_id, level)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            strtoupper(trim($data['course_code'])),
            trim($data['course_name']),
            $data['credit_units']  ?? 2,
            $data['department_id'] ?? null,
            $data['level']         ?? null
        ]);
        $courseId = $pdo->lastInsertId();

        // Save lecturer assignment into course_lecturers
        if (!empty($data['lecturer_id'])) {
            $lStmt = $pdo->prepare(
                "INSERT IGNORE INTO course_lecturers (course_id, lecturer_id) VALUES (?, ?)"
            );
            $lStmt->execute([$courseId, (int)$data['lecturer_id']]);
        }

        // Save program assignments — frontend sends program codes e.g. ["CS","SE"]
        if (!empty($data['programs']) && is_array($data['programs'])) {
            $pStmt = $pdo->prepare(
                "INSERT IGNORE INTO course_programs (course_id, program_id)
                 SELECT ?, id FROM programs WHERE program_code = ?"
            );
            foreach ($data['programs'] as $code) {
                $pStmt->execute([$courseId, trim($code)]);
            }
        }

        http_response_code(201);
        echo json_encode([
            'message' => 'Course created successfully',
            'id'      => $courseId
        ]);
        break;

    // -----------------------------------------------
    // PUT — Update an existing course
    // -----------------------------------------------
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);

        // Validate ID
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Course ID is required']);
            break;
        }

        // Validate required fields
        if (empty($data['course_code']) || empty($data['course_name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Course code and course name are required']);
            break;
        }

        // Check course exists
        $check = $pdo->prepare("SELECT id FROM courses WHERE id = ?");
        $check->execute([$data['id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Course not found']);
            break;
        }

        // Check duplicate code (excluding current course)
        $dupCheck = $pdo->prepare("SELECT id FROM courses WHERE course_code = ? AND id != ?");
        $dupCheck->execute([$data['course_code'], $data['id']]);
        if ($dupCheck->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Another course with this code already exists']);
            break;
        }

        $stmt = $pdo->prepare(
            "UPDATE courses
             SET course_code  = ?,
                 course_name  = ?,
                 credit_units = ?,
                 department_id = ?,
                 level        = ?
             WHERE id = ?"
        );
        $stmt->execute([
            strtoupper(trim($data['course_code'])),
            trim($data['course_name']),
            $data['credit_units'] ?? 2,
            $data['department_id'] ?? null,
            $data['level']        ?? null,
            $data['id']
        ]);

        // Update lecturer assignment — delete old, insert new
        $delL = $pdo->prepare("DELETE FROM course_lecturers WHERE course_id = ?");
        $delL->execute([$data['id']]);
        if (!empty($data['lecturer_id'])) {
            $insL = $pdo->prepare(
                "INSERT IGNORE INTO course_lecturers (course_id, lecturer_id) VALUES (?, ?)"
            );
            $insL->execute([$data['id'], (int)$data['lecturer_id']]);
        }

        // Update program assignments — delete old, insert new from codes
        $delP = $pdo->prepare("DELETE FROM course_programs WHERE course_id = ?");
        $delP->execute([$data['id']]);
        if (!empty($data['programs']) && is_array($data['programs'])) {
            $insP = $pdo->prepare(
                "INSERT IGNORE INTO course_programs (course_id, program_id)
                 SELECT ?, id FROM programs WHERE program_code = ?"
            );
            foreach ($data['programs'] as $code) {
                $insP->execute([$data['id'], trim($code)]);
            }
        }

        echo json_encode(['message' => 'Course updated successfully']);
        break;

    // -----------------------------------------------
    // DELETE — Remove a course
    // -----------------------------------------------
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Course ID is required']);
            break;
        }

        // Check course exists
        $check = $pdo->prepare("SELECT id FROM courses WHERE id = ?");
        $check->execute([$data['id']]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Course not found']);
            break;
        }

        $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
        $stmt->execute([$data['id']]);

        echo json_encode(['message' => 'Course deleted successfully']);
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