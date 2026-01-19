<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';
require_once __DIR__ . '/functions.php'; // DB-backed timetable helpers

// Build absolute URL to THIS edit.php (immune to <base href>)
$TT_EDIT_URL = rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/edit.php';

// ---------- selectors ----------
$classes = tt_get_classes();

$selected_class_id = '';
if (isset($_GET['class_id']))  $selected_class_id = (string)$_GET['class_id'];
if (isset($_POST['class_id'])) $selected_class_id = (string)$_POST['class_id'];

$selected_stream_id = '';
if (isset($_GET['stream_id']))  $selected_stream_id = (string)$_GET['stream_id'];
if (isset($_POST['stream_id'])) $selected_stream_id = (string)$_POST['stream_id'];

// Streams list depends on class
$streams = [];
if ($selected_class_id !== '') {
    // Phase 2 ensures this exists
    $streams = tt_get_streams_by_class((int)$selected_class_id);
}

// Validate stream belongs to selected class (soft validation here; Phase 2 will harden server-side)
$stream_ok = false;
if ($selected_class_id !== '' && $selected_stream_id !== '' && !empty($streams)) {
    foreach ($streams as $s) {
        if ((string)($s['stream_id'] ?? '') === (string)$selected_stream_id) {
            $stream_ok = true;
            break;
        }
    }
}

$can_edit = ($selected_class_id !== '' && $selected_stream_id !== '' && $stream_ok);

$flash = [
    'saved'   => isset($_GET['saved']) ? (int)$_GET['saved'] : 0,
    'cleared' => isset($_GET['cleared']) ? (int)$_GET['cleared'] : 0,
    'error'   => isset($_GET['error']) ? (string)$_GET['error'] : '',
];

// Handle POST actions (MUST be before any HTML output / header.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['tt_action'] ?? 'save';

    $class_id  = trim((string)($_POST['class_id'] ?? ''));
    $stream_id = trim((string)($_POST['stream_id'] ?? ''));

    if ($class_id === '') {
        header("Location: {$TT_EDIT_URL}?error=" . urlencode("Please select a class."));
        exit;
    }

    if ($stream_id === '') {
        header("Location: {$TT_EDIT_URL}?class_id=" . urlencode($class_id) . "&error=" . urlencode("Please select a stream."));
        exit;
    }

    // Phase 2 will enforce stream ownership server-side
    if ($action === 'clear') {
        // Phase 2 ensures this exists
        tt_clear_timetable_for_stream((int)$stream_id);
        header("Location: {$TT_EDIT_URL}?class_id=" . urlencode($class_id) . "&stream_id=" . urlencode($stream_id) . "&cleared=1");
        exit;
    }

    // default: save
    $timetable_payload = (string)($_POST['timetable_payload'] ?? '{}');
    $times_payload     = (string)($_POST['times_payload'] ?? '{}');

    // Phase 2 ensures this exists
    $ok1 = tt_save_timetable_for_stream((int)$stream_id, $timetable_payload);
    $ok2 = tt_save_global_times($times_payload);

    if ($ok1 && $ok2) {
        header("Location: {$TT_EDIT_URL}?class_id=" . urlencode($class_id) . "&stream_id=" . urlencode($stream_id) . "&saved=1");
        exit;
    }

    header("Location: {$TT_EDIT_URL}?class_id=" . urlencode($class_id) . "&stream_id=" . urlencode($stream_id) . "&error=" . urlencode("Save failed."));
    exit;
}

// Load selected stream timetable + global times for injection
$loaded_timetable = ($can_edit) ? tt_load_timetable_for_stream((int)$selected_stream_id) : null;
$global_times     = tt_load_global_times();

// Subjects + teacher mapping (teacher is NOT editable; auto-pick per subject)
$subjects = tt_get_subjects();
$teacher_map = ($can_edit) ? tt_get_teacher_subject_map_for_stream((int)$selected_stream_id) : [];

// NOW safe to include layout files (they output HTML)
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../sidebar.php';
?>

<style>
    .content { margin-left: 260px; padding: 24px; }
</style>

<div class="content">
    <div class="container-fluid">

        <div class="mb-4">
            <h3 class="fw-bold">
                <i class="bi bi-pencil-square me-2"></i> Timetable Editor
            </h3>
            <p class="text-muted mb-0">
                Select a class and stream, then select a subject and click a lesson cell. Teacher is assigned automatically.
            </p>
        </div>

        <?php if ($flash['saved']): ?>
            <div class="alert alert-success mb-3">Timetable saved.</div>
        <?php endif; ?>

        <?php if ($flash['cleared']): ?>
            <div class="alert alert-warning mb-3">Timetable cleared for this stream.</div>
        <?php endif; ?>

        <?php if (!empty($flash['error'])): ?>
            <div class="alert alert-danger mb-3"><?= htmlspecialchars($flash['error']) ?></div>
        <?php endif; ?>

        <!-- Editor Controls -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form id="ttEditorForm" method="post" class="row g-3 align-items-end">

                    <!-- Class Selection -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Select Class</label>
                        <select name="class_id" id="ttClassSelect" class="form-select" required>
                            <option value="">-- Select Class --</option>

                            <?php if (empty($classes)): ?>
                                <option value="" disabled>No classes found</option>
                            <?php else: ?>
                                <?php foreach ($classes as $c): ?>
                                    <?php
                                        $cid = (string)($c['id'] ?? '');
                                        $cname = (string)($c['name'] ?? $cid);
                                        $sel = ($cid !== '' && $cid === $selected_class_id) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($cid) ?>" <?= $sel ?>>
                                        <?= htmlspecialchars($cname) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Stream Selection -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Select Stream</label>
                        <select name="stream_id" id="ttStreamSelect" class="form-select" <?= $selected_class_id === '' ? 'disabled' : '' ?> required>
                            <option value="">-- Select Stream --</option>

                            <?php if ($selected_class_id !== '' && empty($streams)): ?>
                                <option value="" disabled>No streams found for this class</option>
                            <?php else: ?>
                                <?php foreach (($streams ?: []) as $s): ?>
                                    <?php
                                        $sid = (string)($s['stream_id'] ?? '');
                                        $sname = (string)($s['stream_name'] ?? $sid);
                                        $ssel = ($sid !== '' && $sid === $selected_stream_id) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($sid) ?>" <?= $ssel ?>>
                                        <?= htmlspecialchars($sname) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div class="text-muted small mt-2">
                            Timetables are stream-based. Select a stream to unlock the editor.
                        </div>

                        <?php if ($selected_class_id !== '' && $selected_stream_id !== '' && !$stream_ok): ?>
                            <div class="text-danger small mt-2">
                                Invalid stream selection for this class. Please re-select.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="col-md-1">
                        <button type="button" id="ttBtnNew" class="btn btn-outline-primary w-100" <?= !$can_edit ? 'disabled' : '' ?>>
                            New
                        </button>
                    </div>

                    <div class="col-md-1">
                        <button type="button" id="ttBtnClear" class="btn btn-outline-danger w-100" <?= !$can_edit ? 'disabled' : '' ?>>
                            Clear
                        </button>
                    </div>

                    <div class="col-md-2">
                        <button type="submit" id="ttBtnSave" class="btn btn-primary w-100" <?= !$can_edit ? 'disabled' : '' ?>>
                            Save
                        </button>
                    </div>

                    <!-- Hidden payload (grid data + global times) -->
                    <input type="hidden" name="tt_action" id="ttAction" value="save">
                    <input type="hidden" name="timetable_payload" id="ttPayload" value="">
                    <input type="hidden" name="times_payload" id="ttTimesPayload" value="">
                </form>

                <!-- Subject picker (teacher auto) -->
                <div class="row g-3 align-items-end mt-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Select Subject</label>
                        <select id="ttSubjectSelect" class="form-select" <?= !$can_edit ? 'disabled' : '' ?>>
                            <option value="">-- Select Subject --</option>
                            <?php foreach (($subjects ?: []) as $s): ?>
                                <?php
                                    $sid = (string)($s['id'] ?? '');
                                    $sname = (string)($s['name'] ?? $sid);
                                    $sinit = (string)($s['initial'] ?? '');
                                    $label = $sname . ($sinit !== '' ? " ({$sinit})" : "");
                                ?>
                                <option value="<?= htmlspecialchars($sid) ?>" data-initial="<?= htmlspecialchars($sinit) ?>">
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-2">
                            Subject appears using its abbreviation on the timetable.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Assigned Teacher</label>
                        <div class="form-control bg-light" id="ttTeacherAutoLabel" style="height:auto; min-height: 38px;">
                            <span class="text-muted">Teacher will be auto-selected when you pick a subject.</span>
                        </div>
                        <div class="text-muted small mt-2">
                            Teacher is automatically assigned from the subject mapping and cannot be edited here.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="alert alert-info mb-0">
                            <div class="fw-semibold">How to fill the timetable</div>
                            <div class="small">
                                1) Select a class<br>
                                2) Select a stream<br>
                                3) Select a subject<br>
                                4) Click a lesson cell to apply (teacher auto-fills)
                            </div>
                            <div class="mt-3 text-muted small">
                                <strong>Note:</strong> Times are shared across all streams. Editing times updates timetable times for everyone.
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <?php if ($can_edit): ?>
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <?php require_once __DIR__ . '/timetable_grid.php'; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="alert alert-info mb-0">
                        Please select a class and a valid stream to unlock the timetable editor.
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- ✅ Shared print engine (prints ONLY #ttPrintArea, no placeholders) -->
<script src="print.php?asset=tt_print"></script>

<script>
window.__TT_EDIT_URL = <?= json_encode($TT_EDIT_URL, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__TT_LOADED = <?= json_encode($loaded_timetable ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__TT_GLOBAL_TIMES = <?= json_encode($global_times ?: null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

window.__TT_INITIAL_CLASS_ID = <?= json_encode($selected_class_id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.__TT_INITIAL_STREAM_ID = <?= json_encode($selected_stream_id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

window.__TT_CAN_EDIT = <?= json_encode($can_edit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

window.__TT_SUBJECTS = <?= json_encode($subjects ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

// subject_id => teachers[] (auto-pick the first teacher in the list)
window.__TT_TEACHER_MAP = <?= json_encode($teacher_map ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
(function(){
    "use strict";

    const form = document.getElementById("ttEditorForm");
    const payload = document.getElementById("ttPayload");
    const timesPayload = document.getElementById("ttTimesPayload");

    const classSelect = document.getElementById("ttClassSelect");
    const streamSelect = document.getElementById("ttStreamSelect");
    const actionField = document.getElementById("ttAction");

    const btnClear = document.getElementById("ttBtnClear");
    const btnNew = document.getElementById("ttBtnNew");

    const subjectSelect = document.getElementById("ttSubjectSelect");
    const teacherAutoLabel = document.getElementById("ttTeacherAutoLabel");

    const subjects = Array.isArray(window.__TT_SUBJECTS) ? window.__TT_SUBJECTS : [];
    const teacherMap = (window.__TT_TEACHER_MAP && typeof window.__TT_TEACHER_MAP === "object") ? window.__TT_TEACHER_MAP : {};

    let __ttDirty = false;
    function markDirty(){ __ttDirty = true; }
    function markClean(){ __ttDirty = false; }

    function ensureTT(){
        if(!window.TT){
            alert("Timetable component not loaded.");
            return false;
        }
        return true;
    }

    function getSubjectInitialById(subjectId){
        const sid = String(subjectId || "");
        const found = subjects.find(s => String(s.id) === sid);
        const init = found ? String(found.initial || "").trim() : "";
        if (init) return init.toUpperCase();
        const name = found ? String(found.name || "") : "";
        const letters = name.replace(/[^A-Za-z]/g, "");
        return letters ? letters.substring(0,5).toUpperCase() : "";
    }

    function formatTeacherDisplay(raw){
        let s = String(raw || "").trim();
        if (!s) return "";
        if (s.includes("@")) s = s.split("@")[0];
        s = s.replace(/[._-]+/g, " ").replace(/\s+/g, " ").trim();
        if (!s) return "";
        s = s.split(" ").map(w => w ? (w[0].toUpperCase() + w.slice(1).toLowerCase()) : "").join(" ");
        return s;
    }

    function getAutoTeacherForSubject(subjectId){
        const sid = String(subjectId || "");
        let list = teacherMap[sid];

        // numeric key fallback
        if (!list && sid && teacherMap[String(parseInt(sid,10))]) {
            list = teacherMap[String(parseInt(sid,10))];
        }

        if (!Array.isArray(list) || list.length === 0) return null;

        // pick first teacher (sorted server-side)
        const t = list[0] || null;
        if (!t || !t.id) return null;

        return {
            id: String(t.id),
            name: formatTeacherDisplay(t.name || "") || String(t.name || t.id || "")
        };
    }

    function setTeacherAutoLabel(text, isEmpty){
        if (!teacherAutoLabel) return;
        if (isEmpty) {
            teacherAutoLabel.innerHTML = `<span class="text-muted">${text}</span>`;
        } else {
            teacherAutoLabel.textContent = text;
        }
    }

    function lockGridFields(){
        // prevent manual typing in subject/teacher fields; selection + click drives updates
        document.querySelectorAll('#ttGridBody [data-field="subject"]').forEach(el => {
            el.setAttribute("contenteditable", "false");
        });
        document.querySelectorAll('#ttGridBody [data-field="teacher"]').forEach(el => {
            el.setAttribute("contenteditable", "false");
        });
    }

    function applySelectionToCell(td){
        if (!td) return;

        const subjEl = td.querySelector('[data-field="subject"]');
        const teachEl = td.querySelector('[data-field="teacher"]');
        if (!subjEl || !teachEl) return;

        const subjectId = subjectSelect ? (parseInt(subjectSelect.value || "0", 10) || 0) : 0;

        if (!subjectId) {
            alert("Select a subject first.");
            return;
        }

        // subject initial
        const initial = getSubjectInitialById(subjectId);
        subjEl.textContent = initial || "";
        td.dataset.subjectId = String(subjectId);

        // auto teacher
        const autoTeacher = getAutoTeacherForSubject(subjectId);
        if (autoTeacher) {
            teachEl.textContent = autoTeacher.name;
            td.dataset.teacherId = autoTeacher.id;
            setTeacherAutoLabel(autoTeacher.name, false);
        } else {
            // No teacher mapping: keep blank (no placeholders)
            teachEl.textContent = "";
            td.dataset.teacherId = "";
            setTeacherAutoLabel("No teacher mapped for this subject.", true);
        }

        markDirty();
    }

    document.addEventListener("input", (e) => {
        if (!window.TT) return;
        const container = document.querySelector(".tt-wrap") || document;
        if (container.contains(e.target)) markDirty();
    });

    // Class change: redirect to class only (stream must be re-selected)
    classSelect.addEventListener("change", () => {
        const v = classSelect.value || "";

        if (__ttDirty) {
            const ok = confirm("You have unsaved changes. Switch class anyway?");
            if (!ok) {
                classSelect.value = window.__TT_INITIAL_CLASS_ID || "";
                return;
            }
        }

        window.location.href = window.__TT_EDIT_URL + "?class_id=" + encodeURIComponent(v);
    });

    // Stream change: redirect with class + stream
    if (streamSelect) {
        streamSelect.addEventListener("change", () => {
            const cid = classSelect ? (classSelect.value || "") : "";
            const sid = streamSelect.value || "";

            if (!cid) return;

            if (__ttDirty) {
                const ok = confirm("You have unsaved changes. Switch stream anyway?");
                if (!ok) {
                    streamSelect.value = window.__TT_INITIAL_STREAM_ID || "";
                    return;
                }
            }

            let url = window.__TT_EDIT_URL + "?class_id=" + encodeURIComponent(cid);
            if (sid) url += "&stream_id=" + encodeURIComponent(sid);
            window.location.href = url;
        });
    }

    if (subjectSelect) {
        subjectSelect.addEventListener("change", () => {
            const subjectId = parseInt(subjectSelect.value || "0", 10) || 0;
            if (!subjectId) {
                setTeacherAutoLabel("Teacher will be auto-selected when you pick a subject.", true);
                return;
            }
            const autoTeacher = getAutoTeacherForSubject(subjectId);
            if (autoTeacher) {
                setTeacherAutoLabel(autoTeacher.name, false);
            } else {
                setTeacherAutoLabel("No teacher mapped for this subject.", true);
            }
        });
    }

    document.addEventListener("click", (e) => {
        if (!window.__TT_CAN_EDIT) return;
        const target = e.target;
        const td = target ? target.closest && target.closest("#ttGridBody td.tt-slot") : null;
        if (!td) return;
        applySelectionToCell(td);
    }, true);

    window.addEventListener("DOMContentLoaded", () => {
        if (!window.__TT_CAN_EDIT) return;

        if(!ensureTT()) return;

        if (window.__TT_LOADED) {
            window.TT.setData(window.__TT_LOADED);
        } else {
            window.TT.clear(false);
        }

        if (window.__TT_GLOBAL_TIMES) {
            window.TT.setTimes(window.__TT_GLOBAL_TIMES);
        }

        lockGridFields();
        markClean();
    });

    btnClear.addEventListener("click", () => {
        if (!window.__TT_CAN_EDIT) {
            alert("Please select a class and a stream first.");
            return;
        }

        if(!ensureTT()) return;

        if(!classSelect.value){
            alert("Please select a class first.");
            return;
        }
        if(!streamSelect.value){
            alert("Please select a stream first.");
            return;
        }

        if(!confirm("Clear this stream timetable? This will delete the saved timetable for this stream.")) return;

        actionField.value = "clear";
        form.submit();
    });

    btnNew.addEventListener("click", () => {
        if (!window.__TT_CAN_EDIT) {
            alert("Please select a class and a stream first.");
            return;
        }

        if(!ensureTT()) return;

        if(!confirm("Start a new timetable? This will clear the current grid (not saved until you click Save).")) return;

        window.TT.clear(true);

        document.querySelectorAll("#ttGridBody td.tt-slot").forEach(td => {
            td.dataset.subjectId = "";
            td.dataset.teacherId = "";
        });

        setTeacherAutoLabel("Teacher will be auto-selected when you pick a subject.", true);
        markDirty();
    });

    form.addEventListener("submit", (e) => {
        if (!window.__TT_CAN_EDIT) {
            e.preventDefault();
            alert("Please select a class and a stream first.");
            return;
        }

        if(!ensureTT()){
            e.preventDefault();
            return;
        }

        if(!classSelect.value){
            e.preventDefault();
            alert("Please select a class first.");
            return;
        }

        if(!streamSelect.value){
            e.preventDefault();
            alert("Please select a stream first.");
            return;
        }

        if (actionField.value === "clear") return;

        actionField.value = "save";

        const gridData = window.TT.getData();
        const timesData = window.TT.getTimes();

        payload.value = JSON.stringify(gridData || {});
        timesPayload.value = JSON.stringify(timesData || {});

        markClean();
    });

})();
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
