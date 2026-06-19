<?php
// =============================================
//  timetable.php — Timetable Generation API
//  Faculty Timetable Automation System
//
//  Mirrors the frontend runGenerate() algorithm:
//  - Admin inputs: semester, levels, conflict strategy, algorithm
//  - System reads sessionPlan (course + sessions breakdown)
//  - Backend auto-assigns rooms + time slots
//  - Clash detection: lecturer, room, program conflicts
//  - Returns: placed entries + conflict list + alternatives
// =============================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();
require_once 'db.php';

// -----------------------------------------------
// Session check
// -----------------------------------------------
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in']);
    exit;
}

$method      = $_SERVER['REQUEST_METHOD'];
$currentRole = $_SESSION['role'];

if (in_array($method, ['POST', 'DELETE']) && $currentRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Admin access required']);
    exit;
}

switch ($method) {

    // -----------------------------------------------
    // GET — Fetch saved timetable
    //   Params: semester, academic_year
    //   Optional: level, program_id
    // -----------------------------------------------
    case 'GET':
        $semester     = $_GET['semester']      ?? 'First Semester 2025/2026';
        $academicYear = $_GET['academic_year'] ?? '2024/2025';

        // Get the active timetable session for this semester
        $sessionStmt = $pdo->prepare(
            "SELECT id, algorithm_used, created_at
             FROM timetable_sessions
             WHERE semester = ? AND academic_year = ? AND is_active = 1
             LIMIT 1"
        );
        $sessionStmt->execute([$semester, $academicYear]);
        $ttSession = $sessionStmt->fetch();

        if (!$ttSession) {
            echo json_encode([
                'message'  => 'No timetable generated for this semester yet',
                'entries'  => []
            ]);
            exit;
        }

        // Build filters
        $where  = "WHERE te.timetable_session_id = ?";
        $params = [$ttSession['id']];

        if (!empty($_GET['level'])) {
            $where  .= " AND c.level = ?";
            $params[] = $_GET['level'];
        }
        if (!empty($_GET['program_id'])) {
            $where  .= " AND cp.program_id = ?";
            $params[] = (int) $_GET['program_id'];
        }

        $stmt = $pdo->prepare(
            "SELECT
                te.id,
                c.course_code,
                c.course_name,
                c.credit_units,
                c.level,
                u.full_name    AS lecturer_name,
                l.staff_id,
                r.room_name,
                r.capacity,
                r.room_type,
                ts.day,
                ts.start_time,
                ts.end_time,
                ts.duration_hours,
                te.session_hours,
                te.enrollment
             FROM timetable_entries te
             JOIN courses      c  ON te.course_id    = c.id
             JOIN lecturers    l  ON te.lecturer_id  = l.id
             JOIN users        u  ON l.user_id       = u.id
             JOIN rooms        r  ON te.room_id      = r.id
             JOIN time_slots   ts ON te.time_slot_id = ts.id
             LEFT JOIN course_programs cp ON c.id = cp.course_id
             $where
             GROUP BY te.id
             ORDER BY
               FIELD(ts.day,'Monday','Tuesday','Wednesday','Thursday','Friday'),
               ts.start_time ASC"
        );
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        echo json_encode([
            'session'        => $ttSession,
            'total_entries'  => count($entries),
            'entries'        => $entries
        ]);
        break;

    // -----------------------------------------------
    // POST — Generate timetable
    //
    //  Expects JSON body:
    //  {
    //    "semester":        "First Semester 2025/2026",
    //    "academic_year":   "2024/2025",
    //    "algorithm":       "ea"
    //    "conflict_strategy": "strict" | "warn",
    //    "session_plan": [
    //      {
    //        "course_id":   1,
    //        "lecturer_id": 2,
    //        "enrollment":  65,
    //        "sessions":    [2, 1]   ← hours per session (credit unit split)
    //      },
    //      ...
    //    ]
    //  }
    // -----------------------------------------------
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // ── Validate required fields ──────────────
        if (empty($data['semester']) || empty($data['session_plan'])) {
            http_response_code(400);
            echo json_encode(['error' => 'semester and session_plan are required']);
            exit;
        }

        $semester        = trim($data['semester']);
        $academicYear    = trim($data['academic_year'] ?? '2024/2025');
        $algorithmKey    = $data['algorithm']          ?? 'ea';
        $conflictStrategy= $data['conflict_strategy']  ?? 'strict';
        $sessionPlan     = $data['session_plan'];       // Array of course session plans
        $strict          = ($conflictStrategy === 'strict');

        $algoNames = [
            'ea' => 'Genetic Algorithm'
        ];
        $algorithmName = 'Genetic Algorithm';

        // ── Deactivate any existing timetable for this semester ──
        $deactivate = $pdo->prepare(
            "UPDATE timetable_sessions SET is_active = 0
             WHERE semester = ? AND academic_year = ?"
        );
        $deactivate->execute([$semester, $academicYear]);

        // ── Create a new timetable session ──────────────────────
        $sessionInsert = $pdo->prepare(
            "INSERT INTO timetable_sessions
             (semester, academic_year, algorithm_used, generated_by, is_active)
             VALUES (?, ?, ?, ?, 1)"
        );
        $sessionInsert->execute([
            $semester,
            $academicYear,
            $algorithmName,
            $_SESSION['user_id']
        ]);
        $ttSessionId = $pdo->lastInsertId();

        // ── Load all available rooms (smallest to largest) ──────
        $roomsStmt = $pdo->query(
            "SELECT id, room_name, capacity, room_type
             FROM rooms
             WHERE is_available = 1
             ORDER BY capacity ASC"
        );
        $availRooms = $roomsStmt->fetchAll();

        if (empty($availRooms)) {
            http_response_code(409);
            echo json_encode(['error' => 'No available rooms in the system']);
            exit;
        }

        // ── Load all time slots ordered Mon–Fri, morning first ──
        $slotsStmt = $pdo->query(
            "SELECT id, day, start_time, end_time, duration_hours
             FROM time_slots
             ORDER BY
               FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday'),
               start_time ASC"
        );
        $allSlots = $slotsStmt->fetchAll();

        // ── Load program relationships for conflict checking ─────
        // course_id → [program_id, ...]
        $cpStmt = $pdo->query("SELECT course_id, program_id FROM course_programs");
        $coursePrograms = [];
        foreach ($cpStmt->fetchAll() as $row) {
            $coursePrograms[$row['course_id']][] = $row['program_id'];
        }

        // ── Build all individual sessions to schedule ────────────
        // Each session = one block in the timetable grid
        $allSessions = [];
        foreach ($sessionPlan as $plan) {
            $courseId   = (int) ($plan['course_id']   ?? 0);
            $lecturerId = (int) ($plan['lecturer_id'] ?? 0);
            $enrollment = (int) ($plan['enrollment']  ?? 0);
            $sessions   = $plan['sessions'] ?? [1];  // e.g. [2,1] for 3-credit course

            foreach ($sessions as $hours) {
                $allSessions[] = [
                    'course_id'   => $courseId,
                    'lecturer_id' => $lecturerId,
                    'enrollment'  => $enrollment,
                    'hours'       => (int) $hours,
                    'programs'    => $coursePrograms[$courseId] ?? []
                ];
            }
        }

        // ── Shuffle sessions for variety (mirrors frontend) ──────
        // ══════════════════════════════════════════════════════
        // GENETIC ALGORITHM
        //
        // Chromosome = array of genes, one per session:
        //   gene = [ slot_id, room_id ]
        //
        // FITNESS (violations, lower = better):
        //   Hard (weight 10):
        //     H1 — lecturer double-booked in same slot
        //     H2 — room double-booked in same slot
        //     H3 — shared-program students in two sessions same slot
        //     H4 — same course appearing on same day twice
        //   Soft (weight 1):
        //     S1 — more than MAX_PER_DAY sessions on one day
        //     S2 — room capacity smaller than enrollment (oversubscribed)
        //
        // Parameters tuned for a typical faculty dataset
        // ══════════════════════════════════════════════════════

        $POP_SIZE    = 50;   // chromosomes per generation
        $MAX_GEN     = 200;  // maximum generations
        $ELITE_K     = 6;    // top chromosomes preserved unchanged
        $MUTATE_RATE = 0.12; // gene mutation probability
        $MAX_PER_DAY = 3;    // soft limit: max sessions per day across all courses

        // Pre-index valid slots by required duration
        $slotsByDuration = [];
        foreach ($allSlots as $slot) {
            $dur = (int)$slot['duration_hours'];
            for ($h = 1; $h <= $dur; $h++) {
                $slotsByDuration[$h][] = $slot;
            }
        }

        // Pre-index slots by ID and by day for fast lookup
        $slotById  = [];
        $slotsByDay = [];
        foreach ($allSlots as $slot) {
            $slotById[$slot['id']] = $slot;
            $slotsByDay[$slot['day']][] = $slot['id'];
        }

        $roomList  = array_values($availRooms);
        $sessCount = count($allSessions);

        // Sort rooms smallest to largest for best-fit assignment
        usort($roomList, fn($a,$b) => $a['capacity'] - $b['capacity']);

        if ($sessCount === 0) {
            echo json_encode(['error' => 'No sessions to schedule']);
            exit;
        }

        // ── BEST-FIT ROOM ────────────────────────────────────────
        // Returns the smallest room that fits enrollment,
        // or largest available if nothing fits
        function bestFitRoom($enrollment, $rooms, $excludeRoomIds = []) {
            $free = array_filter($rooms, fn($r) => !in_array($r['id'], $excludeRoomIds));
            if (empty($free)) $free = $rooms; // fallback: allow any room
            foreach ($free as $r) {
                if ($r['capacity'] >= $enrollment) return $r;
            }
            return end($free); // largest available
        }

        // ── FITNESS FUNCTION ─────────────────────────────────────
        function calcFitness($chromosome, $allSessions, $slotById, $coursePrograms, $roomList, $maxPerDay) {
            $violations  = 0;
            $slotUsage   = []; // slot_id => [{lecturer_id, room_id, programs, course_id}]
            $dayCount    = []; // day => session count
            $courseDay   = []; // "course_id-day" => count (H4: same course same day)

            foreach ($chromosome as $sessIdx => $gene) {
                $slotId = $gene['slot_id'];
                $roomId = $gene['room_id'];
                $sess   = $allSessions[$sessIdx];
                $slot   = $slotById[$slotId] ?? null;
                if (!$slot) { $violations += 10; continue; }

                $day = $slot['day'];

                // H4 — same course scheduled twice on same day (hard, weight 50)
                $cdKey = $sess['course_id'] . '-' . $day;
                if (isset($courseDay[$cdKey])) {
                    $violations += 50;
                }
                $courseDay[$cdKey] = true;

                // Day load tracking for soft constraint
                $dayCount[$day] = ($dayCount[$day] ?? 0) + 1;

                if (!isset($slotUsage[$slotId])) $slotUsage[$slotId] = [];

                foreach ($slotUsage[$slotId] as $used) {
                    // H1 — lecturer clash (hard, weight 10)
                    if ($used['lecturer_id'] === $sess['lecturer_id']) {
                        $violations += 10;
                    }
                    // H2 — room clash (hard, weight 10)
                    if ($used['room_id'] === $roomId) {
                        $violations += 10;
                    }
                    // H3 — shared program clash (hard, weight 10)
                    if (!empty(array_intersect($sess['programs'], $used['programs']))) {
                        $violations += 10;
                    }
                }

                // S2 — room too small for enrollment (soft, weight 1)
                $roomCap = 0;
                foreach ($roomList as $r) {
                    if ($r['id'] === $roomId) { $roomCap = $r['capacity']; break; }
                }
                if ($roomCap < $sess['enrollment']) {
                    $violations += 1;
                }

                $slotUsage[$slotId][] = [
                    'lecturer_id' => $sess['lecturer_id'],
                    'room_id'     => $roomId,
                    'programs'    => $sess['programs'],
                    'course_id'   => $sess['course_id']
                ];
            }

            // S1 — too many sessions on one day (soft, weight 1 per excess)
            foreach ($dayCount as $day => $count) {
                if ($count > $maxPerDay) {
                    $violations += ($count - $maxPerDay);
                }
            }

            return $violations;
        }

        // ── SMART RANDOM CHROMOSOME ──────────────────────────────
        // Spreads sessions across days evenly from the start
        // Uses best-fit room based on enrollment
        function randomChromosome($allSessions, $slotsByDuration, $slotsByDay, $roomList) {
            $chromosome  = [];
            $dayNames    = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
            $dayUsed     = array_fill_keys($dayNames, 0);
            $courseOnDay = []; // prevent same course same day during init

            // Shuffle sessions so we don't always assign same course first
            $indices = array_keys($allSessions);
            shuffle($indices);

            foreach ($indices as $idx) {
                $sess = $allSessions[$idx];
                $validSlots = $slotsByDuration[$sess['hours']] ?? $slotsByDuration[1] ?? [];
                if (empty($validSlots)) {
                    $slot = array_values($slotsByDuration)[0][0] ?? null;
                } else {
                    // Sort days by current load, pick least loaded day
                    $slotsByDayLoad = [];
                    foreach ($validSlots as $s) {
                        $slotsByDayLoad[$s['day']][] = $s;
                    }
                    asort($dayUsed);
                    $slot = null;
                    foreach (array_keys($dayUsed) as $day) {
                        // Skip if this course already on this day
                        if (isset($courseOnDay[$sess['course_id'] . '-' . $day])) continue;
                        if (!empty($slotsByDayLoad[$day])) {
                            $slot = $slotsByDayLoad[$day][array_rand($slotsByDayLoad[$day])];
                            break;
                        }
                    }
                    // Fallback: just pick any valid slot
                    if (!$slot) $slot = $validSlots[array_rand($validSlots)];
                }

                // Best-fit room for this enrollment
                $room = bestFitRoom($sess['enrollment'], $roomList);

                $chromosome[$idx] = [
                    'slot_id' => $slot ? $slot['id'] : 1,
                    'room_id' => $room ? $room['id'] : $roomList[0]['id']
                ];

                if ($slot) {
                    $dayUsed[$slot['day']] = ($dayUsed[$slot['day']] ?? 0) + 1;
                    $courseOnDay[$sess['course_id'] . '-' . $slot['day']] = true;
                }
            }
            return $chromosome;
        }

        // ── CROSSOVER ────────────────────────────────────────────
        // Single-point crossover
        function crossover($parentA, $parentB) {
            $keys  = array_keys($parentA);
            $point = rand(1, max(1, count($keys) - 1));
            $child = [];
            foreach ($keys as $i => $idx) {
                $child[$idx] = ($i < $point) ? $parentA[$idx] : $parentB[$idx];
            }
            return $child;
        }

        // ── MUTATION ─────────────────────────────────────────────
        // Reassign slot and/or room, preferring under-loaded days
        function mutate($chromosome, $allSessions, $slotsByDuration, $roomList, $mutateRate) {
            $dayLoad = [];
            foreach ($chromosome as $idx => $gene) {
                // not using slot lookup here — just random reassign
            }
            foreach ($chromosome as $idx => &$gene) {
                if ((mt_rand() / mt_getrandmax()) < $mutateRate) {
                    $sess = $allSessions[$idx];
                    $validSlots = $slotsByDuration[$sess['hours']] ?? $slotsByDuration[1] ?? [];
                    if (!empty($validSlots)) {
                        $gene['slot_id'] = $validSlots[array_rand($validSlots)]['id'];
                    }
                }
                // Separate mutation for room: use best-fit instead of random
                if ((mt_rand() / mt_getrandmax()) < 0.3) {
                    $sess = $allSessions[$idx];
                    $room = bestFitRoom($sess['enrollment'], $roomList);
                    if ($room) $gene['room_id'] = $room['id'];
                }
            }
            return $chromosome;
        }

        // ── REPAIR FUNCTION ──────────────────────────────────────
        // After crossover/mutation, enforce: same course MUST NOT
        // appear on the same day twice. If it does, move the second
        // occurrence to a different day's slot.
        // This converts H4 from a soft penalty into a hard repair.
        function repairChromosome($chromosome, $allSessions, $slotsByDuration, $slotById) {
            $courseDay = []; // course_id => [day => slot_id used]

            foreach ($chromosome as $idx => &$gene) {
                $sess   = $allSessions[$idx];
                $slot   = $slotById[$gene['slot_id']] ?? null;
                if (!$slot) continue;

                $day     = $slot['day'];
                $cid     = $sess['course_id'];
                $cKey    = $cid . '-' . $day;

                if (isset($courseDay[$cKey])) {
                    // This course is already on this day — move it
                    $usedDays = array_keys($courseDay[$cid] ?? []);
                    $validSlots = $slotsByDuration[$sess['hours']] ?? $slotsByDuration[1] ?? [];

                    // Try to find a slot on a day not yet used by this course
                    $moved = false;
                    shuffle($validSlots);
                    foreach ($validSlots as $altSlot) {
                        $altDay = $altSlot['day'];
                        if (!in_array($altDay, $usedDays)) {
                            $gene['slot_id'] = $altSlot['id'];
                            $courseDay[$cid][$altDay] = $altSlot['id'];
                            $courseDay[$cid . '-' . $altDay] = true;
                            $moved = true;
                            break;
                        }
                    }
                    // If no other day available, leave as is
                    if (!$moved) {
                        $courseDay[$cid][$day] = $gene['slot_id'];
                    }
                } else {
                    $courseDay[$cKey]     = true;
                    $courseDay[$cid][$day] = $gene['slot_id'];
                }
            }
            return $chromosome;
        }

        // ── INITIALISE POPULATION ────────────────────────────────
        $population = [];
        for ($i = 0; $i < $POP_SIZE; $i++) {
            $chrom = randomChromosome($allSessions, $slotsByDuration, $slotsByDay, $roomList);
            $chrom = repairChromosome($chrom, $allSessions, $slotsByDuration, $slotById);
            $population[] = $chrom;
        }

        $bestChromosome  = null;
        $bestFitness     = PHP_INT_MAX;
        $generationFound = 0;

        // ── MAIN GA LOOP ─────────────────────────────────────────
        for ($gen = 0; $gen < $MAX_GEN; $gen++) {

            $scored = [];
            foreach ($population as $chrom) {
                $fit = calcFitness($chrom, $allSessions, $slotById, $coursePrograms, $roomList, $MAX_PER_DAY);
                $scored[] = ['chrom' => $chrom, 'fitness' => $fit];
                if ($fit < $bestFitness) {
                    $bestFitness     = $fit;
                    $bestChromosome  = $chrom;
                    $generationFound = $gen;
                }
            }

            // Stop early if perfect solution found
            if ($bestFitness === 0) break;

            // Sort ascending by fitness
            usort($scored, fn($a, $b) => $a['fitness'] - $b['fitness']);

            // Keep elites
            $newPop = [];
            for ($e = 0; $e < min($ELITE_K, count($scored)); $e++) {
                $newPop[] = $scored[$e]['chrom'];
            }

            // Crossover + mutate from top half
            $topHalf = array_slice($scored, 0, (int)ceil(count($scored) / 2));
            while (count($newPop) < $POP_SIZE) {
                $pA    = $topHalf[array_rand($topHalf)]['chrom'];
                $pB    = $topHalf[array_rand($topHalf)]['chrom'];
                $child = crossover($pA, $pB);
                $child = mutate($child, $allSessions, $slotsByDuration, $roomList, $MUTATE_RATE);
                $child = repairChromosome($child, $allSessions, $slotsByDuration, $slotById);
                $newPop[] = $child;
            }

            $population = $newPop;
        }

        // ── DECODE BEST CHROMOSOME ───────────────────────────────
        $placed    = [];
        $unplaced  = [];
        $conflicts = [];

        foreach ($bestChromosome as $sessIdx => $gene) {
            $sess = $allSessions[$sessIdx];
            $slot = $slotById[$gene['slot_id']] ?? null;
            $room = null;
            foreach ($roomList as $r) {
                if ($r['id'] === $gene['room_id']) { $room = $r; break; }
            }

            if (!$slot || !$room) {
                $unplaced[] = [
                    'course_id' => $sess['course_id'],
                    'hours'     => $sess['hours'],
                    'reason'    => 'No valid slot or room in final solution'
                ];
                continue;
            }

            $slotKey = $slot['day'] . '-' . $slot['id'];
            $placed[] = [
                'slot_key'      => $slotKey,
                'course_id'     => $sess['course_id'],
                'lecturer_id'   => $sess['lecturer_id'],
                'room_id'       => $room['id'],
                'time_slot_id'  => $slot['id'],
                'session_hours' => $sess['hours'],
                'enrollment'    => $sess['enrollment'],
                'day'           => $slot['day'],
                'start_time'    => $slot['start_time'],
                'end_time'      => $slot['end_time'],
                'room_name'     => $room['room_name'],
                'capacity'      => $room['capacity']
            ];
        }

        // ── POST-DECODE DECONFLICTION ────────────────────────────
        // The GA may still have residual clashes in the best chromosome.
        // Before inserting to DB, resolve any remaining:
        //   - Room double-bookings in same slot
        //   - Lecturer double-bookings in same slot
        // Strategy: keep first placed entry, reassign conflicting ones
        // to any available slot+room combination.

        $usedRoomSlot     = []; // "room_id-time_slot_id" => true
        $usedLecturerSlot = []; // "lecturer_id-time_slot_id" => true
        $cleanPlaced      = [];

        foreach ($placed as $entry) {
            $rKey = $entry['room_id']     . '-' . $entry['time_slot_id'];
            $lKey = $entry['lecturer_id'] . '-' . $entry['time_slot_id'];

            $roomConflict     = isset($usedRoomSlot[$rKey]);
            $lecturerConflict = isset($usedLecturerSlot[$lKey]);

            if ($roomConflict || $lecturerConflict) {
                // Find a free slot + free room for this entry
                $resolved = false;
                $sess = null;
                foreach ($allSessions as $s) {
                    if ($s['course_id'] === $entry['course_id']) { $sess = $s; break; }
                }
                $validSlots = $slotsByDuration[$entry['session_hours']] ?? $slotsByDuration[1] ?? [];
                shuffle($validSlots);

                foreach ($validSlots as $altSlot) {
                    $altLKey = $entry['lecturer_id'] . '-' . $altSlot['id'];
                    if (isset($usedLecturerSlot[$altLKey])) continue;

                    // Find free room in this slot
                    foreach ($roomList as $altRoom) {
                        $altRKey = $altRoom['id'] . '-' . $altSlot['id'];
                        if (isset($usedRoomSlot[$altRKey])) continue;
                        if ($sess && $altRoom['capacity'] < $sess['enrollment'] && count($roomList) > 1) continue;

                        // Found a free slot + room
                        $entry['room_id']      = $altRoom['id'];
                        $entry['time_slot_id'] = $altSlot['id'];
                        $entry['day']          = $altSlot['day'];
                        $entry['start_time']   = $altSlot['start_time'];
                        $entry['end_time']     = $altSlot['end_time'];
                        $entry['room_name']    = $altRoom['room_name'];
                        $entry['capacity']     = $altRoom['capacity'];
                        $entry['slot_key']     = $altSlot['day'] . '-' . $altSlot['id'];

                        $usedRoomSlot[$altRoom['id'] . '-' . $altSlot['id']]      = true;
                        $usedLecturerSlot[$entry['lecturer_id'] . '-' . $altSlot['id']] = true;
                        $cleanPlaced[] = $entry;
                        $resolved = true;
                        break 2;
                    }
                }

                if (!$resolved) {
                    $unplaced[] = [
                        'course_id' => $entry['course_id'],
                        'hours'     => $entry['session_hours'],
                        'reason'    => 'Residual conflict after GA — no free slot/room found'
                    ];
                    if ($roomConflict)     $conflicts[] = "Course ID {$entry['course_id']}: Room conflict resolved by dropping session";
                    if ($lecturerConflict) $conflicts[] = "Course ID {$entry['course_id']}: Lecturer conflict resolved by dropping session";
                }
            } else {
                $usedRoomSlot[$rKey]     = true;
                $usedLecturerSlot[$lKey] = true;
                $cleanPlaced[] = $entry;
            }
        }

        $placed = $cleanPlaced;

        if ($bestFitness > 0) {
            $conflicts[] = "Genetic Algorithm completed {$MAX_GEN} generations with {$bestFitness} soft constraint violation(s). Hard constraints (no clashes, no course repeated same day) were prioritised. Consider adding more rooms or time slots if sessions remain unplaced.";
        }

        // ── Save all placed entries to DB ────────────────────────
        $insertEntry = $pdo->prepare(
            "INSERT INTO timetable_entries
             (timetable_session_id, course_id, lecturer_id, room_id, time_slot_id, session_hours, enrollment)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $pdo->beginTransaction();
        try {
            foreach ($placed as $entry) {
                $insertEntry->execute([
                    $ttSessionId,
                    $entry['course_id'],
                    $entry['lecturer_id'],
                    $entry['room_id'],
                    $entry['time_slot_id'],
                    $entry['session_hours'],
                    $entry['enrollment']
                ]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'error'   => 'Failed to save timetable. Please try again',
                'detail'  => $e->getMessage(),
                'sample'  => !empty($placed) ? $placed[0] : 'no entries placed',
                'session' => $ttSessionId
            ]);
            exit;
        }

        // ── Build response ───────────────────────────────────────
        http_response_code(201);
        echo json_encode([
            'message'              => 'Timetable generated successfully',
            'timetable_session_id' => $ttSessionId,
            'algorithm'            => $algorithmName,
            'semester'             => $semester,
            'total_sessions'       => count($allSessions),
            'placed'               => count($placed),
            'unplaced'             => count($unplaced),
            'conflicts'            => $conflicts,
            'unplaced_details'     => $unplaced,
            'ga_stats'             => [
                'generations_run'    => $gen ?? $MAX_GEN,
                'generation_solved'  => $generationFound,
                'final_violations'   => $bestFitness,
                'population_size'    => $POP_SIZE,
            ],
            'entries'              => $placed
        ]);
        break;

    // -----------------------------------------------
    // DELETE — Clear/remove a timetable session
    // -----------------------------------------------
    case 'DELETE':
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['timetable_session_id']) && empty($data['semester'])) {
            http_response_code(400);
            echo json_encode(['error' => 'timetable_session_id or semester is required']);
            exit;
        }

        if (!empty($data['timetable_session_id'])) {
            // Delete specific session
            $check = $pdo->prepare("SELECT id FROM timetable_sessions WHERE id = ?");
            $check->execute([$data['timetable_session_id']]);
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Timetable session not found']);
                exit;
            }
            // Cascade deletes all entries automatically
            $del = $pdo->prepare("DELETE FROM timetable_sessions WHERE id = ?");
            $del->execute([$data['timetable_session_id']]);
        } else {
            // Delete by semester
            $del = $pdo->prepare(
                "DELETE FROM timetable_sessions
                 WHERE semester = ? AND academic_year = ?"
            );
            $del->execute([
                $data['semester'],
                $data['academic_year'] ?? '2024/2025'
            ]);
        }

        echo json_encode(['message' => 'Timetable cleared successfully']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
?>