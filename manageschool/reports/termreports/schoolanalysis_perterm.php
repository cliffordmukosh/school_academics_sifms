<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>School Mean Grade Summary</title>
  <style>
    body {
      font-family: "Segoe UI", Arial, sans-serif;
      font-size: 13px;
      margin: 20px auto;
      max-width: 1000px;
      color: #222;
    }
    h2, h3 {
      margin: 4px 0;
      text-align: center;
    }
    table {
      border-collapse: collapse;
      width: 100%;
      margin: 15px 0;
      font-size: 12px;
    }
    th, td {
      border: 1px solid #444;
      padding: 4px 6px;
      text-align: center;
    }
    th {
      background: #003366 !important;
      color: #fff !important;
    }
    tr.totals {
      background: #f4f4f4;
      font-weight: bold;
    }
    .btn-bar {
      text-align: center;
      margin: 10px 0 20px;
    }
    .action-btn {
      margin: 0 5px;
      padding: 6px 12px;
      background: #0066cc;
      color: #fff;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 13px;
    }
    .action-btn:hover { background: #004999; }

    .footer-signatures {
      margin-top: 40px;
      display: flex;
      justify-content: space-between;
      font-size: 13px;
    }
    .footer-signatures div {
      width: 45%;
    }
    .footer-signatures b {
      display: block;
      margin-top: 5px;
      text-decoration: underline;
    }

    @media print {
      @page { size: A4 landscape; margin: 10mm; }
      body {
        margin: 0 auto;
        font-size: 11px;
        max-width: 100%;
      }
      .btn-bar { display: none; }
      table { font-size: 11px; }
      th {
        background: #003366 !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
      .footer-signatures { font-size: 11px; }
    }
  </style>
</head>
<body>
  <!-- Action buttons -->
  <div class="btn-bar">
    <button class="action-btn" onclick="window.print()"> Print</button>
    <button class="action-btn" onclick="downloadPDF()">Download PDF</button>
  </div>

  <!-- Header with Logo -->
  <table style="border:none; width:100%;">
    <tr>
      <td style="border:none; width: 15%; text-align:left;">
        <img src="./school-logo.png" alt="School Logo" width="70">
      </td>
      <td style="border:none; text-align:center; width: 85%;">
        <h2>KEILAH HIGH SCHOOL</h2>
        <h3>SCHOOL MEAN GRADE SUMMARY - TERM 2 YEAR 2025</h3>
      </td>
    </tr>
  </table>

  <!-- FORM 1 -->
  <h3>FORM 1 RESULTS</h3>
  <table id="form1">
    <tr>
      <th>Stream</th><th>Entry</th><th>A</th><th>A-</th><th>B+</th>
      <th>B</th><th>B-</th><th>C+</th><th>C</th><th>C-</th>
      <th>D+</th><th>D</th><th>D-</th><th>E</th><th>X</th><th>Y</th>
      <th>M.S</th><th>GRD</th>
    </tr>
    <tr>
      <td>1A</td><td>45</td><td>1</td><td>2</td><td>5</td>
      <td>7</td><td>8</td><td>6</td><td>7</td><td>3</td>
      <td>2</td><td>2</td><td>1</td><td>1</td><td>0</td><td>0</td>
      <td>8.20</td><td>B-</td>
    </tr>
    <tr>
      <td>1B</td><td>42</td><td>0</td><td>1</td><td>4</td>
      <td>6</td><td>7</td><td>5</td><td>9</td><td>3</td>
      <td>2</td><td>1</td><td>2</td><td>1</td><td>1</td><td>0</td>
      <td>7.50</td><td>C+</td>
    </tr>
    <tr>
      <td>1C</td><td>44</td><td>0</td><td>2</td><td>3</td>
      <td>8</td><td>6</td><td>7</td><td>8</td><td>4</td>
      <td>2</td><td>2</td><td>1</td><td>1</td><td>0</td><td>0</td>
      <td>7.80</td><td>B-</td>
    </tr>
    <tr class="totals">
      <td>TOTAL</td><td>131</td><td>1</td><td>5</td><td>12</td>
      <td>21</td><td>21</td><td>18</td><td>24</td><td>10</td>
      <td>6</td><td>5</td><td>4</td><td>3</td><td>1</td><td>0</td>
      <td>7.83</td><td>B-</td>
    </tr>
  </table>

  <!-- FORM 2 -->
  <h3>FORM 2 RESULTS</h3>
  <table id="form2">
    <tr>
      <th>Stream</th><th>Entry</th><th>A</th><th>A-</th><th>B+</th>
      <th>B</th><th>B-</th><th>C+</th><th>C</th><th>C-</th>
      <th>D+</th><th>D</th><th>D-</th><th>E</th><th>X</th><th>Y</th>
      <th>M.S</th><th>GRD</th>
    </tr>
    <tr>
      <td>2A</td><td>47</td><td>1</td><td>3</td><td>4</td>
      <td>8</td><td>7</td><td>6</td><td>7</td><td>3</td>
      <td>2</td><td>3</td><td>1</td><td>1</td><td>0</td><td>0</td>
      <td>8.40</td><td>B</td>
    </tr>
    <tr>
      <td>2B</td><td>43</td><td>0</td><td>2</td><td>3</td>
      <td>7</td><td>8</td><td>5</td><td>9</td><td>4</td>
      <td>2</td><td>2</td><td>0</td><td>1</td><td>0</td><td>0</td>
      <td>7.90</td><td>B-</td>
    </tr>
    <tr>
      <td>2C</td><td>46</td><td>1</td><td>2</td><td>5</td>
      <td>6</td><td>7</td><td>7</td><td>8</td><td>3</td>
      <td>2</td><td>2</td><td>2</td><td>0</td><td>1</td><td>0</td>
      <td>8.10</td><td>B-</td>
    </tr>
    <tr class="totals">
      <td>TOTAL</td><td>136</td><td>2</td><td>7</td><td>12</td>
      <td>21</td><td>22</td><td>18</td><td>24</td><td>10</td>
      <td>6</td><td>7</td><td>3</td><td>2</td><td>1</td><td>0</td>
      <td>8.13</td><td>B-</td>
    </tr>
  </table>

  <!-- Footer signatures -->
  <div class="footer-signatures">
    <div>
      <p><b>PREPARED BY</b></p>
      <p>SIGN: ....................................   DATED:.........................</p>
      <p>MRS. MARY WANGIO</p>
      <b>DEPUTY PRINCIPAL (ACADEMICS).</b>
    </div>
    <div>
      <p><b>APPROVED BY</b></p>
      <p>SIGN: ....................................   DATED:.........................</p>
      <p>MR. CASPAL M. MAINA</p>
      <b>PRINCIPAL.</b>
    </div>
  </div>

  <script>
    function downloadPDF() {
      const element = document.body.cloneNode(true);
      element.querySelector('.btn-bar').remove();
      const opt = {
        margin:       0.5,
        filename:     'school_summary.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2 },
        jsPDF:        { unit: 'in', format: 'a4', orientation: 'landscape' }
      };
      html2pdf().set(opt).from(element).save();
    }
  </script>

  <!-- jsPDF + html2pdf library -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
</body>
</html>
