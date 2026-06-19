<?php
// =============================================
//  login.php — Authentication API
//  Faculty Timetable Automation System
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only POST is allowed on this endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

session_start();
require_once 'db.php';

// -----------------------------------------------
// Read and validate incoming JSON body
// -----------------------------------------------
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['email']) || empty($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required']);
    exit;
}

$email    = strtolower(trim($data['email']));
$password = $data['password'];

// -----------------------------------------------
// Look up the user by email
// -----------------------------------------------
$stmt = $pdo->prepare(
    "SELECT id, full_name, email, password, role, is_active
     FROM users
     WHERE email = ?
     LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// -----------------------------------------------
// Validate user exists and is active
// -----------------------------------------------
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password']);
    exit;
}

if (!$user['is_active']) {
    http_response_code(403);
    echo json_encode(['error' => 'Account is deactivated. Contact the administrator']);
    exit;
}

// -----------------------------------------------
// Verify password against hashed password in DB
// -----------------------------------------------
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password']);
    exit;
}

// -----------------------------------------------
// Start session — store user info
// -----------------------------------------------
session_regenerate_id(true); // Prevent session fixation attacks

$_SESSION['user_id']   = $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['email']     = $user['email'];
$_SESSION['role']      = $user['role'];

// -----------------------------------------------
// Return success response with user info
// -----------------------------------------------
http_response_code(200);
echo json_encode([
    'message'   => 'Login successful',
    'user' => [
        'id'        => $user['id'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'],
        'role'      => $user['role']  // 'admin', 'lecturer', or 'student'
    ]
]);
exit;
?>