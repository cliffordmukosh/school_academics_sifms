<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../sidebar.php';

/**
 * Build Subject Allocation Report (derived from saved STREAM timetables)
 * Returns:
 *  [
 *    'subject_name' => string,
 *    'term' => string,
 *    'rows' => [
 *       ['day'=>..., 'time'=>..., 'slot'=>..., 'class'=>..., 'teacher'=>...],
 *       ...
 *    ]
 *  ]
 */
function tt_build_subject_view($subject_id) {
    $conn = tt_db();
    $school_id = tt_school_id();
    if (!$conn || $school_id <= 0) return null;

    $subject_id = (int)$subject_id;
    if ($subject_id <= 0) return null;

    $tset_id = tt_get_or_create_timetable_setting_id($school_id);
    if (!$tset_id) return null;

    tt_ensure_time_slots($tset_id);

    // Subject name
    $subject_name = '';
    $stmtS = $conn->prepare("SELECT name, subject_initial FROM subjects WHERE school_id=? AND subject_id=? AND deleted_at IS NULL LIMIT 1");
    if ($stmtS) {
        $stmtS->bind_param("ii", $school_id, $subject_id);
        $stmtS->execute();
        $rs = $stmtS->get_result();
        if ($rs && ($row = $rs->fetch_assoc())) {
            $nm = trim((string)($row['name'] ?? ''));
            $ini = trim((string)($row['subject_initial'] ?? ''));
            $subject_name = $nm !== '' ? $nm : $ini;
        }
        $stmtS->close();
    }

    // Term label (best-effort)
    $term_label = '';
    $stmtT = $conn->prepare("
        SELECT ss.term_name, ss.academic_year
        FROM timetable_settings ts
        LEFT JOIN school_settings ss ON ss.setting_id = ts.setting_id
        WHERE ts.school_id=? AND ts.timetable_setting_id=?
        LIMIT 1
    ");
    if ($stmtT) {
        $stmtT->bind_param("ii", $school_id, $tset_id);
        $stmtT->execute();
        $rt = $stmtT->get_result();
        if ($rt && ($rowt = $rt->fetch_assoc())) {
            $tn = trim((string)($rowt['term_name'] ?? ''));
            $ay = trim((string)($rowt['academic_year'] ?? ''));
            $term_label = trim(($tn !== '' ? $tn : '') . ($ay !== '' ? " {$ay}" : ''));
        }
        $stmtT->close();
    }

    // Allocation rows
    $rows = [];

    $sql = "
        SELECT
            t.day_of_week,
            ts.slot_number,
            ts.start_time,
            ts.end_time,
            c.form_name AS class_name,
            st.stream_name AS stream_name,
            u.initials AS teacher_initials,
            u.username AS teacher_username,
            CONCAT(TRIM(u.first_name),' ',TRIM(u.other_names)) AS teacher_fullname
        FROM timetables t
        JOIN time_slots ts ON ts.time_slot_id = t.time_slot_id
        JOIN streams st ON st.stream_id = t.stream_id
        JOIN classes c ON c.class_id = st.class_id
        LEFT JOIN users u ON u.user_id = t.teacher_id
        WHERE t.school_id=? AND t.timetable_setting_id=? AND t.subject_id=?
        ORDER BY
            FIELD(t.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') ASC,
            ts.slot_number ASC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iii", $school_id, $tset_id, $subject_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($res && ($r = $res->fetch_assoc())) {
            $day = (string)($r['day_of_week'] ?? '');

            $stt = substr((string)($r['start_time'] ?? ''), 0, 5);
            $enn = substr((string)($r['end_time'] ?? ''), 0, 5);
            $time = ($stt !== '' && $enn !== '') ? "{$stt} - {$enn}" : '';

            $slot = (string)($r['slot_number'] ?? '');

            $className  = trim((string)($r['class_name'] ?? ''));
            $streamName = trim((string)($r['stream_name'] ?? ''));
            $classLabel = trim($className . ($streamName !== '' ? " {$streamName}" : ''));

            // Prefer Initials > Full Name > Username
            $teacher = trim((string)($r['teacher_initials'] ?? ''));
            if ($teacher === '') $teacher = trim((string)($r['teacher_fullname'] ?? ''));
            if ($teacher === '') $teacher = trim((string)($r['teacher_username'] ?? ''));

            $rows[] = [
                'day' => $day,
                'time' => $time,
                'slot' => $slot,
                'class' => $classLabel,
                'teacher' => $teacher,
            ];
        }

        $stmt->close();
    }

    return [
        'subject_name' => $subject_name,
        'term' => $term_label,
        'rows' => $rows
    ];
}

$subjects = tt_get_subjects();
$selected_subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;

$report = $selected_subject_id ? tt_build_subject_view($selected_subject_id) : null;
$subject_label = (string)($report['subject_name'] ?? '');
$term_label = (string)($report['term'] ?? '');
?>

<style>
    .content { margin-left: 260px; padding: 24px; }
</style>

<div class="content">
    <div class="container-fluid">

        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-4">
            <div>
                <h3 class="fw-bold mb-1">
                    <i class="bi bi-book me-2"></i> Subject Allocation Report
                </h3>
                <div class="text-muted">
                    View and print where a subject is taught across classes/streams (derived from saved stream timetables).
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-lg-8">
                        <label class="form-label fw-semibold">Select Subject</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">-- Select Subject --</option>
                            <?php foreach ($subjects as $s): ?>
                                <?php
                                    $sid = (string)($s['id'] ?? '');
                                    $sname = (string)($s['name'] ?? $sid);
                                    $sel = ($selected_subject_id > 0 && (string)$selected_subject_id === $sid) ? 'selected' : '';
                                ?>
                                <option value="<?= htmlspecialchars($sid) ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($sname) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($subjects)): ?>
                            <div class="text-muted small mt-2">No subjects found yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="col-lg-4">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-arrow-repeat me-1"></i> Load Report
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Report -->
        <div class="card shadow-sm border-0">
            <div class="card-body">

                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <div>
                        <div class="fw-bold">
                            Subject: <?= htmlspecialchars($subject_label) ?>
                        </div>
                        <div class="text-muted small">
                            <?= htmlspecialchars($term_label) ?>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-dark"
                        id="btnPrintSubject"
                        <?= $selected_subject_id ? '' : 'disabled' ?>
                    >
                        <i class="bi bi-printer me-1"></i> Print
                    </button>
                </div>

                <div id="subjectPrintArea">
                    <?php
                        $rows = $report['rows'] ?? [];
                        if (!$selected_subject_id) {
                            echo '<div class="text-muted">Select a subject to view allocations.</div>';
                        } elseif (empty($rows)) {
                            echo '<div class="alert alert-warning mb-0">No allocations found for this subject in saved stream timetables.</div>';
                        } else {
                            // Group by day
                            $grouped = [];
                            foreach ($rows as $r) {
                                $d = (string)($r['day'] ?? '');
                                if (!isset($grouped[$d])) $grouped[$d] = [];
                                $grouped[$d][] = $r;
                            }

                            foreach ($grouped as $day => $items) {
                                echo '<div class="mt-3">';
                                echo '<div class="fw-bold mb-2">' . htmlspecialchars($day) . '</div>';
                                echo '<div class="table-responsive">';
                                echo '<table class="table table-sm table-bordered align-middle mb-0">';
                                echo '<thead class="table-light">';
                                echo '<tr>';
                                echo '<th style="width:140px">Time</th>';
                                echo '<th style="width:90px">Slot</th>';
                                echo '<th>Class/Stream</th>';
                                echo '<th style="width:260px">Teacher</th>';
                                echo '</tr>';
                                echo '</thead><tbody>';

                                foreach ($items as $it) {
                                    echo '<tr>';
                                    echo '<td>' . htmlspecialchars((string)($it['time'] ?? '')) . '</td>';
                                    echo '<td>' . htmlspecialchars((string)($it['slot'] ?? '')) . '</td>';
                                    echo '<td class="fw-semibold">' . htmlspecialchars((string)($it['class'] ?? '')) . '</td>';
                                    echo '<td>' . htmlspecialchars((string)($it['teacher'] ?? '')) . '</td>';
                                    echo '</tr>';
                                }

                                echo '</tbody></table></div></div>';
                            }
                        }
                    ?>
                </div>

            </div>
        </div>

    </div>
</div>

<script src="print.php?asset=tt_print"></script>
<script>
(function(){
    "use strict";

    function safeFileTitle(s){
        return String(s || "").trim().replace(/[\\/:*?"<>|]+/g, "-").replace(/\s+/g, " ").trim();
    }

    document.getElementById("btnPrintSubject")?.addEventListener("click", function(){
        if (!window.TTPrint || typeof window.TTPrint.printTimetable !== "function") {
            window.print();
            return;
        }

        const subj = <?= json_encode($subject_label, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const title = "Subject - " + safeFileTitle(subj || "Allocation Report");

        window.TTPrint.printTimetable({
            title: title,
            areaId: "subjectPrintArea",
            extraCss: `
                body{ font-family: Arial, sans-serif; padding: 18px; color:#111; }
                .fw-bold{ font-weight:700; }
                table{ width:100%; border-collapse:collapse; margin-top:8px; }
                th,td{ border:2px solid #111; padding:8px; font-size:12px; }
                thead th{ background:#f2f2f2; }
            `
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
