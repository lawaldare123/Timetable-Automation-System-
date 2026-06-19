<?php
header('Content-Type: application/json');
require_once 'db.php';

// Test insert directly into course_lecturers
$results = [];

// 1. Check what's in courses
$courses = $pdo->query("SELECT id, course_code FROM courses")->fetchAll();
$results['courses'] = $courses;

// 2. Check what's in lecturers
$lecturers = $pdo->query("SELECT l.id, u.full_name FROM lecturers l JOIN users u ON l.user_id=u.id")->fetchAll();
$results['lecturers'] = $lecturers;

// 3. Check course_lecturers
$results['course_lecturers'] = $pdo->query("SELECT * FROM course_lecturers")->fetchAll();

// 4. Try a direct test insert and catch any error
if (!empty($courses) && !empty($lecturers)) {
    $testCourseId   = $courses[0]['id'];
    $testLecturerId = $lecturers[0]['id'];
    try {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO course_lecturers (course_id, lecturer_id) VALUES (?, ?)"
        );
        $stmt->execute([$testCourseId, $testLecturerId]);
        $results['test_insert'] = [
            'status'      => 'success',
            'course_id'   => $testCourseId,
            'lecturer_id' => $testLecturerId,
            'rows_affected'=> $stmt->rowCount()
        ];
    } catch (Exception $e) {
        $results['test_insert'] = [
            'status' => 'FAILED',
            'error'  => $e->getMessage()
        ];
    }

    // 5. Check after insert
    $results['course_lecturers_after'] = $pdo->query("SELECT * FROM course_lecturers")->fetchAll();
}

echo json_encode($results, JSON_PRETTY_PRINT);
?>