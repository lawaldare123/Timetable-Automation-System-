<?php
// =============================================
//  register.php — Self-Registration API
//  Faculty Timetable Automation System
//  Allows students and lecturers to register
//  Admins are created only via phpMyAdmin/seeding
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

require_once 'db.php';

$data = json_decode(file_get_contents('php://input'), true);

// ── Validate required fields ──────────────────
$required = ['full_name', 'email', 'password', 'role'];
foreach ($required as $f) {
    if (empty($data[$f])) {
        http_response_code(400);
        echo json_encode(['error' => "Field '$f' is required"]);
        exit;
    }
}

$role = $data['role'];
if (!in_array($role, ['student', 'lecturer'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Role must be student or lecturer']);
    exit;
}

$email = strtolower(trim($data['email']));

// ── Check duplicate email ─────────────────────
$check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$check->execute([$email]);
if ($check->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Email already registered']);
    exit;
}

$pdo->beginTransaction();
try {
    // Insert user
    $userStmt = $pdo->prepare(
        "INSERT INTO users (full_name, email, password, role, is_active)
         VALUES (?, ?, ?, ?, 1)"
    );
    $userStmt->execute([
        trim($data['full_name']),
        $email,
        password_hash($data['password'], PASSWORD_BCRYPT)
    ]);
    $userId = $pdo->lastInsertId();

    // Insert student or lecturer profile
    if ($role === 'student') {
        $matric = trim($data['matric_number'] ?? '');
        if (empty($matric)) {
            throw new Exception('Matric number is required for students');
        }
        $stuStmt = $pdo->prepare(
            "INSERT INTO students (user_id, matric_number, level)
             VALUES (?, ?, ?)"
        );
        $stuStmt->execute([$userId, $matric, $data['level'] ?? '400']);

    } elseif ($role === 'lecturer') {
        // Auto-generate staff ID: LEC-{userId}
        $staffId = 'LEC-' . str_pad($userId, 4, '0', STR_PAD_LEFT);
        $lecStmt = $pdo->prepare(
            "INSERT INTO lecturers (user_id, staff_id, specialization)
             VALUES (?, ?, ?)"
        );
        $lecStmt->execute([
            $userId,
            $staffId,
            $data['specialization'] ?? 'General'
        ]);
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'message' => 'Account created successfully',
        'user' => [
            'id'        => $userId,
            'full_name' => trim($data['full_name']),
            'email'     => $email,
            'role'      => $role
        ]
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>