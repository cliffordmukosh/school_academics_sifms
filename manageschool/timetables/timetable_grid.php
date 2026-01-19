<?php
/**
 * Timetable Grid Component (Canonical)
 * - Full width inside card (no centered fixed paper)
 * - Print button prints ONLY the timetable area (#ttPrintArea)
 * - Placeholders DO NOT print (empty prints blank)
 * - Editable UI remains (contenteditable) unless locked by parent page
 */
?>

<style id="ttComponentStyles">
  :root{
    --ink:#111;
    --grid:#111;
    --paper:#fff;

    --ui-bg:#f2f2f2;
    --ui-bar:#ffffff;
    --ui-border:#d9d9d9;
    --ui-text:#111;
    --ui-sub:#555;

    /* --- Responsive sizing (screen) --- */
    --tt-left-col: clamp(88px, 16vw, 140px);

    --tt-col-lesson: clamp(58px, 7.6vw, 86px);
    --tt-col-break:  clamp(58px, 7.6vw, 86px);
    --tt-col-lunch:  clamp(72px, 8.6vw, 98px);

    --tt-phead-h:   clamp(40px, 6.2vw, 52px);
    --tt-slot-h:    clamp(78px, 10.8vw, 112px);

    --tt-day-font:  clamp(22px, 6vw, 54px);
    --tt-day-pad-y: clamp(10px, 2.8vw, 20px);

    --tt-term-font: clamp(14px, 2.6vw, 20px);
    --tt-form-font: clamp(28px, 7.2vw, 56px);

    --tt-pnum-font: clamp(12px, 2.2vw, 22px);
    --tt-ptime-font: clamp(9px, 1.4vw, 10px);

    --tt-break-font: clamp(12px, 1.9vw, 18px);

    --tt-subject-font: clamp(16px, 3.2vw, 30px);
    --tt-teacher-font: clamp(10px, 1.6vw, 11px);

    --tt-paper-pad: clamp(10px, 2.4vw, 14px);


  }

  .tt-wrap{ width:100%; }

  .tt-toolbar{
    position: sticky;
    top: 0;
    z-index: 10;
    background: var(--ui-bar);
    border-bottom: 1px solid var(--ui-border);
    padding: 12px 14px;
  }
  .tt-toolbar-inner{
    display:flex;
    gap:12px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
  }
  .tt-toolbar .left, .tt-toolbar .right{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
  }
  .tt-hint{
    font-size:12px;
    color:var(--ui-sub);
    line-height:1.25;
    max-width:720px;
  }

  .tt-btn{
    border:1px solid #111;
    background:#fff;
    color:#111;
    padding:10px 14px;
    cursor:pointer;
    font-weight:900;
    letter-spacing:.2px;
    border-radius:10px;
    transition: transform .08s ease, background .12s ease, border-color .12s ease;
  }
  .tt-btn:hover{ background:#f6f6f6; }
  .tt-btn:active{ transform: translateY(1px); }

  .tt-btn.primary{
    background:#111;
    color:#fff;
    border-color:#111;
  }
  .tt-btn.primary:hover{ background:#000; }

  .tt-page-wrap{
    padding: 14px;
    width: 100%;
  }

  .tt-paper{
    background:var(--paper);
    border:1px solid var(--ui-border);
    width: 100%;
    padding: var(--tt-paper-pad) var(--tt-paper-pad) 10px;
    border-radius:12px;

    overflow-x:auto;
    -webkit-overflow-scrolling: touch;
  }


  .tt-topline{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    margin-bottom:2px;
  }
  .tt-dept{
    font-weight:700;
    font-size:13px;
  }
  .tt-class-teacher{
    font-size:13px;
    font-weight:700;
  }

  .tt-titleblock{
    text-align:center;
    margin:0 0 8px;
  }
  .tt-term{
    font-size: var(--tt-term-font);

    font-weight:800;
    letter-spacing:1px;
  }
  .tt-form{
    font-size: var(--tt-form-font);

    font-weight:900;
    letter-spacing:1px;
    line-height:1.0;
    margin-top:2px;
  }

  .tt-edit-inline{
    display:inline-block;
    padding:2px 6px;
    border:1px dashed transparent;
    border-radius:4px;
    min-width: 40px;
    outline:none;
    background:transparent;
  }
  .tt-edit-inline:focus{
    border-color:#777;
    background:transparent !important;
    box-shadow:none !important;
  }

  table.tt-timetable{
    width:100%;
    border-collapse:collapse;
    table-layout:fixed;
    border:2.5px solid var(--grid);
  }
  table.tt-timetable th, table.tt-timetable td{
    border:1.6px solid var(--grid);
    vertical-align:middle;
    padding:0;
    background:#fff;
  }

  .tt-left-header-blank{
    width: var(--tt-left-col);
    border-right:2.2px solid var(--grid) !important;
    background:#fff;
  }
  .tt-day-col{
    width: var(--tt-left-col);
    font-size: var(--tt-day-font);
    padding: var(--tt-day-pad-y) 0 !important;
    border-right:2.2px solid var(--grid) !important;
    text-align:center;
    font-weight:900;
    letter-spacing:.5px;
    line-height:1;
  }
  .tt-day-label{
    display:inline-block;
    transform: translateY(2px);
  }

  .tt-phead{
    height: var(--tt-phead-h);
    font-size: var(--tt-pnum-font);
    text-align:center;
    font-weight:900;
    padding-top:5px !important;
    position:relative;
  }

  .tt-pnum{ font-size: var(--tt-pnum-font); font-weight:900; }

  .tt-ptime{
    font-size: var(--tt-ptime-font);
    font-weight:700;
    letter-spacing:.2px;
    opacity:.9;
    margin-top:3px;
    background:transparent;
  }
  .tt-breakhead, .tt-lunchhead{
    font-size: var(--tt-break-font);
    letter-spacing:.5px;
  }

  .tt-col-lesson { width: var(--tt-col-lesson); }
  .tt-col-break  { width: var(--tt-col-break); }
  .tt-col-lunch  { width: var(--tt-col-lunch); }


  .tt-slot{
    height: var(--tt-slot-h);

    position:relative;
    padding:10px 6px 22px !important;
    text-align:center;
    overflow:hidden;
  }
  .tt-slot.breakcell, .tt-slot.lunchcell{ background:#fff; }

  .tt-slot-inner{
    display:flex;
    height:100%;
    width:100%;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    padding:0 2px;
    overflow:hidden;
  }

  .tt-subject{
    font-weight:900;
    font-size: var(--tt-subject-font);

    letter-spacing:.5px;
    line-height:1.1;
    padding:2px 6px;
    outline:none;
    border:1px dashed transparent;
    border-radius:4px;
    background:transparent;

    display:-webkit-box;
    -webkit-line-clamp:3;
    line-clamp:3;
    -webkit-box-orient:vertical;

    max-width:100%;
    overflow:hidden;
    white-space:normal;
    word-break:break-word;
    hyphens:auto;
  }
  .tt-subject:focus{
    border-color:#777;
    background:transparent !important;
    box-shadow:none !important;
  }

  .tt-teacher-code{
    position:absolute;
    right:6px;
    bottom:6px;
    font-size: var(--tt-teacher-font);

    font-weight:900;
    opacity:.95;
    outline:none;
    border:1px dashed transparent;
    border-radius:4px;
    padding:2px 4px;
    min-width:18px;
    text-align:right;
    white-space:nowrap;
    background:transparent;

    overflow:hidden;
    max-width:70px;
  }
  .tt-teacher-code:focus{
    border-color:#777;
    background:transparent !important;
    box-shadow:none !important;
  }

  [data-placeholder]:empty::before{
    content: attr(data-placeholder);
    opacity:.28;
    font-weight:800;
  }

  .tt-footerline{
    margin-top:6px;
    font-size:11px;
    font-weight:700;
    display:flex;
    justify-content:space-between;
    opacity:.95;
  }
  .tt-footerline .codes{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width:82%;
  }
  .tt-footerline .brand{ white-space:nowrap; }
  
  @media (max-width: 560px){
    .tt-toolbar{ padding: 10px 10px; }
    .tt-page-wrap{ padding: 10px; }
    .tt-hint{ max-width: 100%; }
  }

  @media print{
    body *{ visibility:hidden !important; }
    /* Lock print sizes to original fixed values (so responsiveness doesn't affect PDF) */
    #ttPrintArea{
      --tt-left-col: 140px;
      --tt-col-lesson: 86px;
      --tt-col-break: 86px;
      --tt-col-lunch: 98px;
      --tt-phead-h: 52px;
      --tt-slot-h: 112px;
      --tt-day-font: 54px;
      --tt-day-pad-y: 20px;
      --tt-term-font: 20px;
      --tt-form-font: 56px;
      --tt-pnum-font: 22px;
      --tt-ptime-font: 10px;
      --tt-break-font: 18px;
      --tt-subject-font: 30px;
      --tt-teacher-font: 11px;
      --tt-paper-pad: 14px;
    }

    #ttPrintArea, #ttPrintArea *{ visibility:visible !important; }
    #ttPrintArea{ position:absolute; left:0; top:0; width:100%; }
    .tt-toolbar{ display:none !important; }
    .tt-page-wrap{ padding:0 !important; }
    .tt-paper{
      border:none !important;
      box-shadow:none !important;
      width:auto !important;
      padding:0 !important;
      border-radius:0 !important;
    }
    @page{
      size: A4 landscape;
      margin: 8mm;
    }
    .tt-edit-inline,
    .tt-subject,
    .tt-teacher-code{
      background:transparent !important;
      box-shadow:none !important;
      border-color:transparent !important;
    }
    /* ✅ KEY: never print placeholders */
    [data-placeholder]:empty::before{ content:"" !important; }

    table.tt-timetable{
      break-inside: avoid;
      page-break-inside: avoid;
    }
  }
</style>

<div class="tt-wrap">
  <div class="tt-toolbar">
    <div class="tt-toolbar-inner">
      <div class="left">
        <button class="tt-btn primary" type="button" id="ttBtnPrint">Print</button>
      </div>
      <div class="right">
        <div class="tt-hint">
          Click any lesson cell to edit. <b>Subject</b> wraps. <b>Teacher</b> is bottom-right.
        </div>
      </div>
    </div>
  </div>

  <div class="tt-page-wrap">
    <div class="tt-paper" id="ttPrintArea">

      <div class="tt-topline">
        <div class="tt-dept">
          <span class="tt-edit-inline" contenteditable="true" spellcheck="false" id="ttHdrDept" data-placeholder="EXAMS DEPARTMENT"></span>
        </div>
        <div class="tt-class-teacher">
          Class teacher :
          <span class="tt-edit-inline" contenteditable="true" spellcheck="false" id="ttHdrTeacher" data-placeholder="MR DAVID"></span>
        </div>
      </div>

      <div class="tt-titleblock">
        <div class="tt-term">
          <span class="tt-edit-inline" contenteditable="true" spellcheck="false" id="ttHdrTerm" data-placeholder="TERM I 2026"></span>
        </div>
        <div class="tt-form">
          <span class="tt-edit-inline" contenteditable="true" spellcheck="false" id="ttHdrForm" data-placeholder="FORM ONE"></span>
        </div>
      </div>

      <table class="tt-timetable" aria-label="Timetable">
        <colgroup>
          <col style="width:140px" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lesson" />
          <col class="tt-col-break" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lesson" />
          <col class="tt-col-break" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lunch" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lesson" />
          <col class="tt-col-lesson" />
        </colgroup>

        <tr>
          <th class="tt-left-header-blank"></th>

          <th class="tt-phead">
            <div class="tt-pnum">1</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="08:00 - 08:40" id="ttT1"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">2</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="08:40 - 09:20" id="ttT2"></div>
          </th>
          <th class="tt-phead tt-breakhead">
            BREAK
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="09:20 - 09:30" id="ttTB1"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">3</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="09:30 - 10:10" id="ttT3"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">4</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="10:10 - 10:50" id="ttT4"></div>
          </th>
          <th class="tt-phead tt-breakhead">
            BREAK
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="10:50 - 11:20" id="ttTB2"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">5</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="11:20 - 12:00" id="ttT5"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">6</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="12:00 - 12:40" id="ttT6"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">7</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="12:40 - 01:20" id="ttT7"></div>
          </th>
          <th class="tt-phead tt-lunchhead">
            LUNCH
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="01:20 - 02:00" id="ttTL"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">8</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="02:00 - 02:40" id="ttT8"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">9</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="02:40 - 03:20" id="ttT9"></div>
          </th>
          <th class="tt-phead">
            <div class="tt-pnum">10</div>
            <div class="tt-ptime tt-edit-inline" contenteditable="true" spellcheck="false" data-placeholder="05:00 - 05:45" id="ttT10"></div>
          </th>
        </tr>

        <tbody id="ttGridBody"></tbody>
      </table>

      <div class="tt-footerline">
        <div class="codes">
          <span class="tt-edit-inline" contenteditable="true" spellcheck="false" id="ttHdrCodes"
                data-placeholder="01-MR. KIHORO,02-MRS. JOHN,03-MR. MUTUNGA,..."></span>
        </div>
        <div class="brand">
          <span class="tt-edit-inline" contenteditable="true" spellcheck="false" id="ttHdrBrand" data-placeholder="Timetables"></span>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
(function(){
  "use strict";

  const COLS = [
    { key: "p1",  type:"lesson" },
    { key: "p2",  type:"lesson" },
    { key: "b1",  type:"break"  },
    { key: "p3",  type:"lesson" },
    { key: "p4",  type:"lesson" },
    { key: "b2",  type:"break"  },
    { key: "p5",  type:"lesson" },
    { key: "p6",  type:"lesson" },
    { key: "p7",  type:"lesson" },
    { key: "l1",  type:"lunch"  },
    { key: "p8",  type:"lesson" },
    { key: "p9",  type:"lesson" },
    { key: "p10", type:"lesson" }
  ];

  const DAYS = [
    { key:"Mo", label:"Mo" },
    { key:"Tu", label:"Tu" },
    { key:"We", label:"We" },
    { key:"Th", label:"Th" },
    { key:"Fr", label:"Fr" }
  ];

  const $ = (sel, root=document) => root.querySelector(sel);

  function safeText(el){
    return (el && el.textContent ? el.textContent : "").replace(/\u00A0/g," ").trim();
  }

  function autoFitSubject(subjectEl){
    if(!subjectEl) return;
    const base = 30, min = 16;
    subjectEl.style.fontSize = base + "px";
    if(!safeText(subjectEl)) return;

    const maxHeight = 74;
    for(let size = base; size >= min; size--){
      subjectEl.style.fontSize = size + "px";
      if(subjectEl.scrollHeight <= maxHeight) break;
    }
  }

  function fitAllSubjects(){
    document.querySelectorAll(".tt-subject").forEach(autoFitSubject);
  }

  function buildGrid(){
    const tbody = $("#ttGridBody");
    tbody.innerHTML = "";

    for(const day of DAYS){
      const tr = document.createElement("tr");

      const dayTd = document.createElement("td");
      dayTd.className = "tt-day-col";
      dayTd.innerHTML = `<span class="tt-day-label">${day.label}</span>`;
      tr.appendChild(dayTd);

      for(const col of COLS){
        const td = document.createElement("td");
        td.className = "tt-slot" + (col.type === "break" ? " breakcell" : col.type === "lunch" ? " lunchcell" : "");
        td.dataset.day = day.key;
        td.dataset.col = col.key;

        // IDs (stored invisibly for DB saves)
        td.dataset.subjectId = "";
        td.dataset.teacherId = "";

        if(col.type !== "lesson"){
          td.innerHTML = `<div class="tt-slot-inner"></div>`;
        } else {
          td.innerHTML = `
            <div class="tt-slot-inner">
              <div class="tt-subject" contenteditable="true" spellcheck="false"
                   data-field="subject" data-placeholder="SUB"></div>
            </div>
            <div class="tt-teacher-code" contenteditable="true" spellcheck="false"
                 data-field="teacher" data-placeholder="00"></div>
          `;
        }

        tr.appendChild(td);
      }

      tbody.appendChild(tr);
    }

    tbody.addEventListener("input", (e) => {
      const el = e.target;
      if(el && el.getAttribute && el.getAttribute("data-field") === "subject"){
        autoFitSubject(el);
      }
    }, true);

    tbody.addEventListener("keydown", (e) => {
      const el = e.target;
      if(!el || !el.getAttribute) return;
      if(e.key === "Enter"){
        e.preventDefault();
        el.blur();
      }
    }, true);

    fitAllSubjects();
  }

  // ✅ Shared print engine (from print.php?asset=tt_print)
  function doSharedPrint(){
    if (window.TTPrint && typeof window.TTPrint.printTimetable === "function") {
      // Use the grid header (Form/Teacher) to auto-name the PDF in most browsers
      let metaForm = "";
      try {
        const data = (window.TT && typeof window.TT.getData === "function") ? window.TT.getData() : null;
        metaForm = (data && data.meta && data.meta.form) ? String(data.meta.form) : "";
      } catch(e) {}

      const fileSafe = (s) => String(s || "")
        .trim()
        .replace(/[\\/:*?"<>|]+/g, "-")
        .replace(/\s+/g, " ")
        .trim();

      const base = fileSafe(metaForm) || "Timetable";
      const title = base.toLowerCase().includes("timetable") ? base : (base + " Timetable");

      window.TTPrint.printTimetable({ title: title, areaId: "ttPrintArea" });
      return true;
    }
    return false;
  }


  // Fallback print (kept as backup)
  function openPrintWindowFallback(){
    if(document.activeElement && document.activeElement.blur) document.activeElement.blur();
    fitAllSubjects();

    const stylesEl = document.getElementById("ttComponentStyles");
    const styles = stylesEl ? stylesEl.innerHTML : "";

    const printArea = document.getElementById("ttPrintArea");
    if(!printArea) {
      window.print();
      return;
    }

    const win = window.open("", "_blank", "noopener,noreferrer");
    if(!win) {
      window.print();
      return;
    }

    win.document.open();
    win.document.write(`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Timetable Print</title>
<style>${styles}</style>
<style>
  body{ margin:0; background:#fff; }
  .tt-paper{ border:none !important; box-shadow:none !important; border-radius:0 !important; width:auto !important; padding:0 !important; }
  .tt-page-wrap{ padding:0 !important; }
  /* ✅ never print placeholders */
  [data-placeholder]:empty::before{ content:"" !important; }
</style>
</head>
<body>
  <div class="tt-page-wrap">
    ${printArea.outerHTML}
  </div>
</body>
</html>`);
    win.document.close();

    win.focus();
    setTimeout(() => {
      win.print();
      setTimeout(() => win.close(), 200);
    }, 150);
  }

  function wirePrint(){
    const btn = $("#ttBtnPrint");
    if(!btn) return;
    btn.addEventListener("click", () => {
      // Prefer shared print engine
      if (doSharedPrint()) return;
      // Otherwise fallback
      openPrintWindowFallback();
    });
  }

  function wireResizeFit(){
    window.addEventListener("resize", () => {
      clearTimeout(window.__ttFitTO);
      window.__ttFitTO = setTimeout(fitAllSubjects, 80);
    });
  }

  // --- Data model in memory ---
  function getCellValues(){
    const out = { days:{} };

    document.querySelectorAll("#ttGridBody td.tt-slot").forEach(td => {
      const day = td.dataset.day;
      const col = td.dataset.col;
      if(!day || !col) return;

      const subj = td.querySelector('[data-field="subject"]');
      const teach = td.querySelector('[data-field="teacher"]');
      if(!subj || !teach) return;

      if(!out.days[day]) out.days[day] = {};
      out.days[day][col] = {
        subject: safeText(subj),
        teacher: safeText(teach),
        subject_id: parseInt(td.dataset.subjectId || "0", 10) || 0,
        teacher_id: parseInt(td.dataset.teacherId || "0", 10) || 0
      };
    });

    return out;
  }

  function setCellValues(days){
    document.querySelectorAll("#ttGridBody td.tt-slot").forEach(td => {
      const day = td.dataset.day;
      const col = td.dataset.col;
      if(!day || !col) return;

      const subj = td.querySelector('[data-field="subject"]');
      const teach = td.querySelector('[data-field="teacher"]');
      if(!subj || !teach) return;

      const cell = (days && days[day] && days[day][col]) ? days[day][col] : {subject:"", teacher:""};
      subj.textContent = cell.subject || "";
      teach.textContent = cell.teacher || "";

      const sid = (cell && cell.subject_id && parseInt(cell.subject_id,10) > 0) ? String(parseInt(cell.subject_id,10)) : "";
      const tid = (cell && cell.teacher_id && parseInt(cell.teacher_id,10) > 0) ? String(parseInt(cell.teacher_id,10)) : "";
      td.dataset.subjectId = sid;
      td.dataset.teacherId = tid;

      autoFitSubject(subj);
    });

    fitAllSubjects();
  }

  function getTimes(){
    return {
      p1: safeText(document.getElementById("ttT1")),
      p2: safeText(document.getElementById("ttT2")),
      b1: safeText(document.getElementById("ttTB1")),
      p3: safeText(document.getElementById("ttT3")),
      p4: safeText(document.getElementById("ttT4")),
      b2: safeText(document.getElementById("ttTB2")),
      p5: safeText(document.getElementById("ttT5")),
      p6: safeText(document.getElementById("ttT6")),
      p7: safeText(document.getElementById("ttT7")),
      l1: safeText(document.getElementById("ttTL")),
      p8: safeText(document.getElementById("ttT8")),
      p9: safeText(document.getElementById("ttT9")),
      p10: safeText(document.getElementById("ttT10"))
    };
  }

  function setTimes(times){
    if(!times) return;
    const map = {
      ttT1:"p1", ttT2:"p2", ttTB1:"b1", ttT3:"p3", ttT4:"p4", ttTB2:"b2",
      ttT5:"p5", ttT6:"p6", ttT7:"p7", ttTL:"l1", ttT8:"p8", ttT9:"p9", ttT10:"p10"
    };
    Object.keys(map).forEach(id => {
      const key = map[id];
      const el = document.getElementById(id);
      if(el) el.textContent = times[key] || "";
    });
  }

  function getMeta(){
    return {
      department: safeText(document.getElementById("ttHdrDept")),
      classTeacher: safeText(document.getElementById("ttHdrTeacher")),
      term: safeText(document.getElementById("ttHdrTerm")),
      form: safeText(document.getElementById("ttHdrForm")),
      footerCodes: safeText(document.getElementById("ttHdrCodes")),
      footerBrand: safeText(document.getElementById("ttHdrBrand"))
    };
  }

  function setMeta(meta){
    if(!meta) meta = {};
    const map = {
      ttHdrDept:"department",
      ttHdrTeacher:"classTeacher",
      ttHdrTerm:"term",
      ttHdrForm:"form",
      ttHdrCodes:"footerCodes",
      ttHdrBrand:"footerBrand"
    };
    Object.keys(map).forEach(id => {
      const key = map[id];
      const el = document.getElementById(id);
      if(el) el.textContent = meta[key] || "";
    });
  }

  function clearAll(clearMeta){
    setCellValues({});
    if(clearMeta){
      setMeta({});
      setTimes({});
    }
  }

  function getData(){
    const meta = getMeta();
    const times = getTimes();
    const cells = getCellValues();
    return {
      meta: meta,
      times: times,
      days: cells.days || {}
    };
  }

  function setData(data){
    data = data || {};
    if(data.meta) setMeta(data.meta);
    if(data.times) setTimes(data.times);
    if(data.days) setCellValues(data.days);
  }

  window.TT = window.TT || {};
  window.TT.getData = getData;
  window.TT.setData = setData;
  window.TT.clear = clearAll;
  window.TT.getTimes = getTimes;
  window.TT.setTimes = setTimes;

  buildGrid();
  wirePrint();
  wireResizeFit();
})();
</script>
