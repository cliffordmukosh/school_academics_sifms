<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';

/**
 * Shared Timetable Print Engine
 * Usage (from any timetable page):
 *   <script src="print.php?asset=tt_print"></script>
 *   window.TTPrint.printTimetable(); // prints ONLY #ttPrintArea
 */
if (isset($_GET['asset']) && $_GET['asset'] === 'tt_print') {
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo <<<JS
(function(){
  "use strict";

  function escapeHtml(s){
    return String(s || "")
      .replace(/&/g,"&amp;")
      .replace(/</g,"&lt;")
      .replace(/>/g,"&gt;")
      .replace(/"/g,"&quot;")
      .replace(/'/g,"&#039;");
  }

  function getTimetableStyles(){
    // timetable_grid.php provides <style id="ttComponentStyles">...</style>
    var el = document.getElementById("ttComponentStyles");
    return el ? (el.innerHTML || "") : "";
  }

  function buildPrintHtml(title, bodyHtml, extraCss){
    var safeTitle = escapeHtml(title || "Timetable Print");
    var styles = getTimetableStyles();

    // Hard print overrides:
    // - hide placeholders
    // - hide toolbars/buttons
    // - remove borders/shadows around wrappers
    // - force clean page
    var hardCss = `
      [data-placeholder]:empty::before{ content:"" !important; } // this ndio inaficha placeholders... you can disable it if you want
      .tt-toolbar{ display:none !important; }
      .tt-page-wrap{ padding:0 !important; }

      /* Auto-fit (slight scale down) to avoid clipping on A4 landscape */
      .tt-print-root{
        transform: scale(0.96);
        transform-origin: top left;
        width: calc(100% / 0.96);
      }

      .tt-paper{
        border:none !important;
        box-shadow:none !important;
        border-radius:0 !important;
        width:auto !important;
        padding:0 !important;
        margin:0 !important;
      }

      /* Slightly thicker grid lines on paper */
      table.tt-timetable{ border-width:3px !important; }
      table.tt-timetable th, table.tt-timetable td{ border-width:2px !important; }

      /* Also thicken simple report tables (subject allocations) */
      table{ border-collapse:collapse; }
      th,td{ border-width:1.4px; }

      .tt-edit-inline,
      .tt-subject,
      .tt-teacher-code{
        background:transparent !important;
        box-shadow:none !important;
        border-color:transparent !important;
        outline:none !important;
      }

      @page{ size: A4 landscape; margin: 8mm; }
    `;

    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>\${safeTitle}</title>
<style>\${styles}</style>
<style>\${hardCss}</style>
<style>\${extraCss || ""}</style>
</head>
<body style="margin:0;background:#fff;">
  <div class="tt-print-root">\${bodyHtml}</div>
</body>
</html>`;
  }

  function openAndPrint(html){
    var win = window.open("", "_blank", "noopener,noreferrer");
    if(!win){
      // Popup blocked; fallback
      window.print();
      return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
    win.focus();

    // Print after render
    setTimeout(function(){
      win.print();
      setTimeout(function(){ win.close(); }, 200);
    }, 150);
  }

  function printTimetable(opts){
    opts = opts || {};
    var title = opts.title || "Timetable Print";
    var areaId = opts.areaId || "ttPrintArea";

    var area = document.getElementById(areaId);
    if(!area){
      // fallback: try timetable container
      var alt = document.querySelector("#ttPrintArea") || document.querySelector(".tt-paper") || document.body;
      area = alt;
    }

    // Print ONLY the timetable area content
    var html = buildPrintHtml(title, area.outerHTML, opts.extraCss || "");
    openAndPrint(html);
  }

  window.TTPrint = window.TTPrint || {};
  window.TTPrint.printTimetable = printTimetable;
})();
JS;

    exit;
}

require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../sidebar.php';
?>

<style>
    .content {
        margin-left: 260px;
        padding: 24px;
    }
</style>

<div class="content">
    <div class="container-fluid">

        <h3 class="fw-bold mb-4">
            <i class="bi bi-grid-3x3-gap me-2"></i> Class Timetables
        </h3>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center p-4">
                        <h5 class="fw-semibold">Print by Subject</h5>
                        <p class="text-muted">Print timetable for a specific subject</p>
                        <a href="subject_print.php" class="btn btn-warning">
                            Subject Timetable
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center p-4">
                        <h5 class="fw-semibold">Print by Teacher</h5>
                        <p class="text-muted">Print timetable for an individual teacher</p>
                        <a href="teacher_print.php" class="btn btn-danger">
                            Teacher Timetable
                        </a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
