<?php
require_once __DIR__ . '/../../apis/auth_middleware.php';
require_once __DIR__ . '/functions.php';

/**
 * print.php serves TWO roles:
 *  1) UI page: "Class Timetables" (links to subject/teacher prints)
 *  2) Shared print engine asset: print.php?asset=tt_print
 *
 * IMPORTANT CHANGE:
 *  - NO new tab / NO about:blank
 *  - Printing is done via a hidden iframe (in-page), and only the requested container prints.
 */

if (isset($_GET['asset']) && $_GET['asset'] === 'tt_print') {
    header('Content-Type: application/javascript; charset=UTF-8');

    echo <<<'JS'
(function(){
  "use strict";

  function $(sel, root){
    return (root || document).querySelector(sel);
  }

  function safeTitle(s){
    return String(s || "Print")
      .trim()
      .replace(/[\\/:*?"<>|]+/g, "-")
      .replace(/\s+/g, " ")
      .trim() || "Print";
  }

  function collectComponentStyles(){
    // Prefer timetable component styles when present
    var el = document.getElementById("ttComponentStyles");
    if (el && el.innerHTML) return String(el.innerHTML);

    // Fallback: gather inline <style> blocks (best-effort)
    var css = "";
    var styles = document.querySelectorAll("style");
    styles.forEach(function(s){
      if (s && s.innerHTML) css += "\n" + s.innerHTML;
    });
    return css;
  }

  function buildPrintHtml(opts, bodyHtml){
    opts = opts || {};
    var title = safeTitle(opts.title || "Print");
    var orientation = (opts.orientation || "landscape").toLowerCase() === "portrait" ? "portrait" : "landscape";
    var pageSize = opts.pageSize || "A4";
    var margin = opts.margin || "8mm";
    var extraCss = String(opts.extraCss || "");

    var baseCss = collectComponentStyles();

    // Hard reset + print safety rules
    var hardCss = `
      html, body{
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
        color:#111 !important;
      }

      /* Hide any placeholder text when printing */
      [data-placeholder]:empty::before{ content:"" !important; }

      /* Avoid weird spacing breaks */
      *{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }

      /* Timetable-specific cleanup (won't hurt other reports) */
      .tt-toolbar{ display:none !important; }
      .tt-wrap > :not(.tt-page-wrap){ display:none !important; }

      .tt-page-wrap,
      .tt-paper{
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
        border:none !important;
        box-shadow:none !important;
        border-radius:0 !important;
      }

      /* Page control */
      @page{ size: ${pageSize} ${orientation}; margin: ${margin}; }
    `;

    // Wrap to keep a predictable printable "sheet"
    var wrapCss = `
      .tt-print-root{
        width: 100%;
        box-sizing: border-box;
      }
    `;

    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>${title}</title>
<style>${baseCss}</style>
<style>${hardCss}</style>
<style>${wrapCss}</style>
<style>${extraCss}</style>
</head>
<body>
  <div class="tt-print-root">${bodyHtml}</div>
</body>
</html>`;
  }

  function createHiddenIframe(){
    var iframe = document.createElement("iframe");
    iframe.setAttribute("aria-hidden", "true");
    iframe.style.position = "fixed";
    iframe.style.right = "0";
    iframe.style.bottom = "0";
    iframe.style.width = "0";
    iframe.style.height = "0";
    iframe.style.border = "0";
    iframe.style.opacity = "0";
    iframe.style.pointerEvents = "none";
    document.body.appendChild(iframe);
    return iframe;
  }

  function printHtmlInIframe(html, opts){
    opts = opts || {};
    return new Promise(function(resolve){
      try{
        var iframe = createHiddenIframe();
        var win = iframe.contentWindow;
        var doc = win.document;

        var cleaned = false;
        function cleanup(){
          if (cleaned) return;
          cleaned = true;
          try{ iframe.remove(); } catch(e){ if (iframe.parentNode) iframe.parentNode.removeChild(iframe); }
          resolve(true);
        }

        // Cleanup after print (best-effort)
        win.onafterprint = function(){
          setTimeout(cleanup, 50);
        };

        doc.open();
        doc.write(html);
        doc.close();

        // Give the browser a moment to layout
        setTimeout(function(){
          try{
            win.focus();
            win.print();

            // Fallback cleanup if onafterprint doesn't fire
            setTimeout(cleanup, 1500);
          }catch(e){
            cleanup();
          }
        }, 120);

      }catch(e){
        resolve(false);
      }
    });
  }

  /**
   * Public API
   * window.TTPrint.printTimetable({
   *   title: "My Report",
   *   areaId: "subjectPrintArea",
   *   orientation: "portrait" | "landscape",
   *   pageSize: "A4",
   *   margin: "8mm",
   *   extraCss: "..."
   * })
   */
  function printTimetable(opts){
    opts = opts || {};
    var areaId = opts.areaId || "ttPrintArea";

    var area = document.getElementById(areaId);
    if (!area) {
      // fallback: try common timetable container
      area = document.querySelector("#ttPrintArea") || document.querySelector(".tt-paper") || null;
    }

    if (!area) {
      // last fallback: print current page
      window.print();
      return;
    }

    var html = buildPrintHtml(opts, area.outerHTML);
    printHtmlInIframe(html, opts);
  }

  window.TTPrint = window.TTPrint || {};
  window.TTPrint.printTimetable = printTimetable;

})();
JS;

    exit;
}

// ---- UI PAGE (unchanged) ----
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
