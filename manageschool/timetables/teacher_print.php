<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../sidebar.php';

/**
 * Teacher list (ONLY teachers that actually appear in timetables)
 * Stream-based
 */
function tt_teacher_print_get_teachers_used() {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return [];

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return [];

    $sql = "
        SELECT DISTINCT
            u.user_id,
            u.username,
            u.initials,
            CONCAT(TRIM(u.first_name),' ',TRIM(u.other_names)) AS full_name
        FROM timetables t
        JOIN users u ON u.user_id = t.teacher_id
        WHERE t.school_id=?
          AND t.timetable_setting_id=?
          AND t.teacher_id IS NOT NULL
          AND u.deleted_at IS NULL
          AND (u.status IS NULL OR u.status='active')
        ORDER BY full_name ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $school_id, $tset_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($r = $res->fetch_assoc()) {
        $label = trim(
            $r['initials']
            ?: ($r['full_name'] ?: $r['username'])
        );
        $out[] = [
            'id' => (int)$r['user_id'],
            'name' => $label
        ];
    }
    $stmt->close();

    return $out;
}

/**
 * Build timetable payload for timetable_grid.php (READ-ONLY VIEW)
 * STREAM-BASED
 */
function tt_teacher_print_build_view(int $teacher_id): ?array {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0 || $teacher_id <= 0) return null;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return null;

    tt_ensure_time_slots($tset_id);

    $daysMap = tt_days_map();           // Mo => Monday
    $revDays = array_flip($daysMap);    // Monday => Mo

    $slotMap = tt_col_to_slot_number(); // p1 => 1
    $slotToCol = array_flip($slotMap);  // 1 => p1

    $data = [
        'meta' => [
            'form' => ''   // Not class-based anymore
        ],
        'days' => []
    ];

    $sql = "
        SELECT
            t.day_of_week,
            ts.slot_number,
            s.subject_initial,
            s.name AS subject_name,
            c.form_name,
            st.stream_name
        FROM timetables t
        JOIN time_slots ts ON ts.time_slot_id = t.time_slot_id
        LEFT JOIN subjects s ON s.subject_id = t.subject_id
        JOIN streams st ON st.stream_id = t.stream_id
        JOIN classes c ON c.class_id = st.class_id
        WHERE t.school_id=?
          AND t.timetable_setting_id=?
          AND t.teacher_id=?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $school_id, $tset_id, $teacher_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $dayKey = $revDays[$r['day_of_week']] ?? null;
        $colKey = $slotToCol[(int)$r['slot_number']] ?? null;
        if (!$dayKey || !$colKey) continue;

        $subject = trim(
            $r['subject_initial']
            ?: ($r['subject_name'] ?? '')
        );

        $classLabel = trim(
            ($r['form_name'] ?? '') . ' ' . ($r['stream_name'] ?? '')
        );

        if (!isset($data['days'][$dayKey])) {
            $data['days'][$dayKey] = [];
        }

        $data['days'][$dayKey][$colKey] = [
            'subject' => $subject,
            'teacher' => $classLabel   // Teacher cell shows class+stream (same as before)
        ];
    }

    $stmt->close();
    return $data;
}

/* ===========================
   Page logic
=========================== */

$teachers = tt_teacher_print_get_teachers_used();
$teacher_id = (int)($_GET['teacher_id'] ?? 0);
$teacher_view = $teacher_id ? tt_teacher_print_build_view($teacher_id) : null;
$global_times = tt_load_global_times();

// Resolve teacher label
$teacher_label = '';
foreach ($teachers as $t) {
    if ((int)$t['id'] === $teacher_id) {
        $teacher_label = $t['name'];
        break;
    }
}
?>

<style>
.content { margin-left:260px; padding:24px; }
</style>

<div class="content">
<div class="container-fluid">

    <div class="d-flex justify-content-between mb-4">
        <div>
            <h3 class="fw-bold mb-1">
                <i class="bi bi-person-badge me-2"></i> Teacher Timetable
            </h3>
            <div class="text-muted">
                View and print an individual teacher timetable (derived from saved stream timetables).
            </div>
        </div>

        <button id="btnPrintTeacher"
                class="btn btn-dark"
                <?= $teacher_id ? '' : 'disabled' ?>>
            <i class="bi bi-printer me-1"></i> Print
        </button>
    </div>

    <!-- Controls -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-lg-8">
                    <label class="form-label fw-semibold">Select Teacher</label>
                    <select name="teacher_id" class="form-select" required>
                        <option value="">-- Select Teacher --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $teacher_id==(int)$t['id']?'selected':'' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-4">
                    <button class="btn btn-primary w-100">
                        <i class="bi bi-arrow-repeat me-1"></i> Load Timetable
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Grid -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <?php require __DIR__ . '/timetable_grid.php'; ?>
        </div>
    </div>

</div>
</div>

<script>
window.__TT_VIEW = <?= json_encode($teacher_view ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__TT_GLOBAL_TIMES = <?= json_encode($global_times ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function(){
    function safeName(s){
        return String(s||'')
            .replace(/[\\/:*?"<>|]+/g,'-')
            .replace(/\s+/g,' ')
            .trim();
    }

    window.addEventListener("DOMContentLoaded", () => {
        if (window.TT) {
            if (window.__TT_VIEW) window.TT.setData(window.__TT_VIEW);
            else window.TT.clear(true);

            if (window.__TT_GLOBAL_TIMES) {
                window.TT.setTimes(window.__TT_GLOBAL_TIMES);
            }
        }

        document.getElementById("btnPrintTeacher")?.addEventListener("click", () => {
            const oldTitle = document.title;
            document.title = "Teacher - " + safeName("<?= addslashes($teacher_label) ?>");
            window.print();
            setTimeout(() => document.title = oldTitle, 500);
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
