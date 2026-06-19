-- =============================================
--  database.sql
--  Faculty Timetable Automation System
--  Run this ONCE in phpMyAdmin to set up
--  the entire database from scratch
--
--  Tables:
--  1.  users
--  2.  departments
--  3.  programs
--  4.  program_enrollment
--  5.  courses
--  6.  course_programs
--  7.  lecturers
--  8.  course_lecturers
--  9.  students
--  10. rooms
--  11. time_slots
--  12. timetable_sessions
--  13. timetable_entries
-- =============================================

CREATE DATABASE IF NOT EXISTS timetable_db CHARACTER SET utf8 COLLATE utf8_general_ci;
USE timetable_db;

-- =============================================
--  TABLE 1: users
--  All login accounts (admin, lecturer, student)
-- =============================================
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    full_name   VARCHAR(100) NOT NULL,
    email       VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role        ENUM('admin','lecturer','student') NOT NULL DEFAULT 'student',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
--  TABLE 2: departments
--  Faculty departments
-- =============================================
CREATE TABLE IF NOT EXISTS departments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL UNIQUE,
    department_code VARCHAR(20)  NOT NULL UNIQUE,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
--  TABLE 3: programs
--  Academic programs (CS, SE, IS, CY etc.)
--  Matches the frontend Programs section
-- =============================================
CREATE TABLE IF NOT EXISTS programs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    program_name    VARCHAR(100) NOT NULL,
    program_code    VARCHAR(20)  NOT NULL UNIQUE,
    faculty         VARCHAR(150) NOT NULL,
    department_id   INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- =============================================
--  TABLE 4: program_enrollment
--  Student count per program per level
--  e.g. CS 400L = 65 students
--  Matches the enrollment inputs in the frontend
-- =============================================
CREATE TABLE IF NOT EXISTS program_enrollment (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    program_id      INT NOT NULL,
    level           ENUM('100','200','300','400','500') NOT NULL,
    student_count   INT NOT NULL DEFAULT 0,
    semester        VARCHAR(50) NOT NULL DEFAULT 'First Semester 2025/2026',
    UNIQUE KEY unique_enrollment (program_id, level, semester),
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
);

-- =============================================
--  TABLE 5: courses
--  All courses offered in the faculty
-- =============================================
CREATE TABLE IF NOT EXISTS courses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    course_code     VARCHAR(20)  NOT NULL UNIQUE,
    course_name     VARCHAR(100) NOT NULL,
    credit_units    INT NOT NULL DEFAULT 2,
    level           ENUM('100','200','300','400','500') NOT NULL DEFAULT '400',
    department_id   INT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- =============================================
--  TABLE 6: course_programs
--  Many-to-many: which programs take which course
--  e.g. SEN 401 is taken by CS, SE, IS
-- =============================================
CREATE TABLE IF NOT EXISTS course_programs (
    course_id   INT NOT NULL,
    program_id  INT NOT NULL,
    PRIMARY KEY (course_id, program_id),
    FOREIGN KEY (course_id)  REFERENCES courses(id)  ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
);

-- =============================================
--  TABLE 7: lecturers
--  Lecturer profiles linked to users
-- =============================================
CREATE TABLE IF NOT EXISTS lecturers (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    staff_id        VARCHAR(30) NOT NULL UNIQUE,
    department_id   INT,
    specialization  VARCHAR(100),
    max_hours_week  INT NOT NULL DEFAULT 12,
    availability    ENUM('all','mw','wf','tt') NOT NULL DEFAULT 'all',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- =============================================
--  TABLE 8: course_lecturers
--  Which lecturer teaches which course
-- =============================================
CREATE TABLE IF NOT EXISTS course_lecturers (
    course_id   INT NOT NULL,
    lecturer_id INT NOT NULL,
    PRIMARY KEY (course_id, lecturer_id),
    FOREIGN KEY (course_id)   REFERENCES courses(id)   ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE
);

-- =============================================
--  TABLE 9: students
--  Student profiles linked to users
-- =============================================
CREATE TABLE IF NOT EXISTS students (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    matric_number   VARCHAR(30) NOT NULL UNIQUE,
    program_id      INT,
    level           ENUM('100','200','300','400','500') NOT NULL DEFAULT '400',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL
);

-- =============================================
--  TABLE 10: rooms
--  Lecture halls, classrooms, labs
-- =============================================
CREATE TABLE IF NOT EXISTS rooms (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    room_name       VARCHAR(50) NOT NULL UNIQUE,
    capacity        INT NOT NULL DEFAULT 30,
    room_type       ENUM('lecture_hall','classroom','lab') NOT NULL DEFAULT 'classroom',
    is_available    TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
--  TABLE 11: time_slots
--  Available time periods Mon-Fri
--  Mirrors the frontend grid exactly:
--  8:00-10:00 (2hr), 10:00-11:00, 11:00-12:00,
--  12:00-13:00, [break], 14:00-15:00, 15:00-16:00,
--  16:00-17:00, 17:00-18:00
-- =============================================
CREATE TABLE IF NOT EXISTS time_slots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    day             ENUM('Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    duration_hours  INT NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slot (day, start_time)
);

-- =============================================
--  TABLE 12: timetable_sessions
--  One record per generation run
--  Groups all timetable entries for a semester
-- =============================================
CREATE TABLE IF NOT EXISTS timetable_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    semester        VARCHAR(50) NOT NULL,
    academic_year   VARCHAR(10) NOT NULL DEFAULT '2024/2025',
    algorithm_used  VARCHAR(30) NOT NULL DEFAULT 'Evolutionary',
    generated_by    INT,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =============================================
--  TABLE 13: timetable_entries
--  One record per scheduled session
--  A 3-credit course = 2 entries (2hr + 1hr)
--  This is the core scheduling table
-- =============================================
CREATE TABLE IF NOT EXISTS timetable_entries (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    timetable_session_id    INT NOT NULL,
    course_id               INT NOT NULL,
    lecturer_id             INT NOT NULL,
    room_id                 INT NOT NULL,
    time_slot_id            INT NOT NULL,
    session_hours           INT NOT NULL DEFAULT 1,
    enrollment              INT NOT NULL DEFAULT 0,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (timetable_session_id) REFERENCES timetable_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id)    REFERENCES courses(id)    ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id)  REFERENCES lecturers(id)  ON DELETE CASCADE,
    FOREIGN KEY (room_id)      REFERENCES rooms(id)      ON DELETE CASCADE,
    FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE CASCADE,

    -- Room cannot be in two places at same time within same timetable
    UNIQUE KEY no_room_clash (timetable_session_id, room_id, time_slot_id),

    -- Lecturer cannot teach two courses at same time within same timetable
    UNIQUE KEY no_lecturer_clash (timetable_session_id, lecturer_id, time_slot_id)
);

-- =============================================
--  DEFAULT DATA: Admin account
--  Password: admin123
-- =============================================
INSERT INTO users (full_name, email, password, role, is_active) VALUES
(
    'System Admin',
    'admin@tas.edu.ng',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    1
);

-- =============================================
--  DEFAULT DATA: Department
-- =============================================
INSERT INTO departments (department_name, department_code) VALUES
('Computer Science & Engineering', 'CSE');

-- =============================================
--  DEFAULT DATA: Programs
-- =============================================
INSERT INTO programs (program_name, program_code, faculty, department_id) VALUES
('Computer Science',     'CS', 'Faculty of Computing, Engineering & Technology', 1),
('Software Engineering', 'SE', 'Faculty of Computing, Engineering & Technology', 1),
('Information Systems',  'IS', 'Faculty of Computing, Engineering & Technology', 1),
('Cyber Security',       'CY', 'Faculty of Computing, Engineering & Technology', 1);

-- =============================================
--  DEFAULT DATA: Program Enrollment
-- =============================================
INSERT INTO program_enrollment (program_id, level, student_count, semester) VALUES
-- CS
(1,'100',80,'First Semester 2025/2026'),
(1,'200',75,'First Semester 2025/2026'),
(1,'300',70,'First Semester 2025/2026'),
(1,'400',65,'First Semester 2025/2026'),
-- SE
(2,'100',75,'First Semester 2025/2026'),
(2,'200',70,'First Semester 2025/2026'),
(2,'300',65,'First Semester 2025/2026'),
(2,'400',60,'First Semester 2025/2026'),
-- IS
(3,'100',60,'First Semester 2025/2026'),
(3,'200',55,'First Semester 2025/2026'),
(3,'300',50,'First Semester 2025/2026'),
(3,'400',45,'First Semester 2025/2026'),
-- CY
(4,'100',50,'First Semester 2025/2026'),
(4,'200',45,'First Semester 2025/2026'),
(4,'300',40,'First Semester 2025/2026'),
(4,'400',35,'First Semester 2025/2026');

-- =============================================
--  DEFAULT DATA: Courses
-- =============================================
INSERT INTO courses (course_code, course_name, credit_units, level, department_id) VALUES
('SEN 401', 'Software Project Management', 3, '400', 1),
('SEN 415', 'Internet & Web Technologies',  2, '400', 1),
('CMP 402', 'Compiler Construction',        3, '400', 1),
('CMP 411', 'Artificial Intelligence',      3, '400', 1);

-- =============================================
--  DEFAULT DATA: Course-Program Links
--  SEN 401 → CS, SE, IS
--  SEN 415 → CS, SE
--  CMP 402 → CS, SE
--  CMP 411 → CS, SE, IS, CY
-- =============================================
INSERT INTO course_programs (course_id, program_id) VALUES
(1,1),(1,2),(1,3),
(2,1),(2,2),
(3,1),(3,2),
(4,1),(4,2),(4,3),(4,4);

-- =============================================
--  DEFAULT DATA: Rooms
-- =============================================
INSERT INTO rooms (room_name, capacity, room_type, is_available) VALUES
('LR-1',  300, 'lecture_hall', 1),
('LR-2',  250, 'lecture_hall', 0),
('LAB-A', 60,  'lab',          1);

-- =============================================
--  DEFAULT DATA: Time Slots
--  Mirrors the frontend timetable grid exactly
-- =============================================
INSERT INTO time_slots (day, start_time, end_time, duration_hours) VALUES
-- Monday
('Monday','08:00:00','10:00:00',2),
('Monday','10:00:00','11:00:00',1),
('Monday','11:00:00','12:00:00',1),
('Monday','12:00:00','13:00:00',1),
('Monday','14:00:00','15:00:00',1),
('Monday','15:00:00','16:00:00',1),
('Monday','16:00:00','18:00:00',2),
-- Tuesday
('Tuesday','08:00:00','10:00:00',2),
('Tuesday','10:00:00','11:00:00',1),
('Tuesday','11:00:00','12:00:00',1),
('Tuesday','12:00:00','13:00:00',1),
('Tuesday','14:00:00','15:00:00',1),
('Tuesday','15:00:00','16:00:00',1),
('Tuesday','16:00:00','18:00:00',2),
-- Wednesday
('Wednesday','08:00:00','10:00:00',2),
('Wednesday','10:00:00','11:00:00',1),
('Wednesday','11:00:00','12:00:00',1),
('Wednesday','12:00:00','13:00:00',1),
('Wednesday','14:00:00','15:00:00',1),
('Wednesday','15:00:00','16:00:00',1),
('Wednesday','16:00:00','18:00:00',2),
-- Thursday
('Thursday','08:00:00','10:00:00',2),
('Thursday','10:00:00','11:00:00',1),
('Thursday','11:00:00','12:00:00',1),
('Thursday','12:00:00','13:00:00',1),
('Thursday','14:00:00','15:00:00',1),
('Thursday','15:00:00','16:00:00',1),
('Thursday','16:00:00','18:00:00',2),
-- Friday
('Friday','08:00:00','10:00:00',2),
('Friday','10:00:00','11:00:00',1),
('Friday','11:00:00','12:00:00',1),
('Friday','12:00:00','13:00:00',1),
('Friday','14:00:00','15:00:00',1),
('Friday','15:00:00','16:00:00',1),
('Friday','16:00:00','18:00:00',2);