<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Grade Analysis Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: #f8f9fa;
      font-family: Arial, sans-serif;
      font-size: 13px;
    }
    .report-container {
      max-width: 1200px;
      margin: 20px auto;
      padding: 20px;
      background: #fff;
      border: 1px solid #ccc;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 3px solid #0d47a1;
      padding-bottom: 10px;
      margin-bottom: 20px;
    }
    .header img {
      height: 65px;
    }
    .school-title {
      text-align: center;
      flex-grow: 1;
      color: #0d47a1;
    }
    .school-title h3 {
      margin: 0;
      font-weight: bold;
      font-size: 20px;
    }
    .school-title h5, .school-title h6 {
      margin: 2px 0;
      font-weight: 500;
    }
    h5.text-center {
      color: #0d47a1;
    }
    table {
      font-size: 12px;
    }
    th {
      background-color: #0d47a1 !important;
      color: white;
      font-size: 12px;
    }
    td, th {
      text-align: center;
      vertical-align: middle;
      padding: 6px;
    }
    .red-text {
      color: red;
      font-weight: bold;
    }
    /* Buttons */
    .btn-custom {
      background-color: #0d47a1;
      color: white;
      border-radius: 5px;
    }
    .btn-custom:hover {
      background-color: #08306b;
      color: #fff;
    }
    @media print {
      body {
        margin: 0;
        background: #fff;
        font-size: 12px;
      }
      .no-print {
        display: none !important;
      }
      th {
        background-color: #0d47a1 !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>
  <!-- ACTION BUTTONS -->
  <div class="text-center mt-3 no-print">
    <button onclick="window.print()" class="btn btn-custom me-2">Print Report</button>
    <button onclick="downloadPDF()" class="btn btn-danger">Download PDF</button>
  </div>

  <div id="report-content" class="report-container">
    <!-- HEADER WITH LOGO + EXAM NAME -->
    <div class="header">
      <img src="school-logo.png" alt="School Logo"> <!-- replace with actual logo path -->
      <div class="school-title">
        <h3>NAIROBI SCHOOL - GRADE ANALYSIS</h3>
        <h5>FORM TWO TERM III YEAR 2025</h5>
        <h6>STREAM: ....................................</h6>
        <h6>EXAM NAME: END TERM EXAMINATION</h6>
      </div>
    </div>
    
    <!-- REPORT TITLE -->
    <h5 class="text-center fw-bold mb-4">
      SUBJECT GRADE ANALYSIS (Examination Analysis)
    </h5>
    
    <!-- TABLE -->
    <div class="table-responsive">
      <table class="table table-bordered table-sm">
        <thead>
          <tr>
            <th>Subject</th>
            <th>A</th>
            <th>A-</th>
            <th>B+</th>
            <th>B</th>
            <th>B-</th>
            <th>C+</th>
            <th>C</th>
            <th>C-</th>
            <th>D+</th>
            <th>D</th>
            <th>D-</th>
            <th>E</th>
            <th>StudCnt</th>
            <th>M.S</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>101-ENGLISH</td>
            <td>17</td><td>15</td><td>3</td><td>13</td><td>6</td>
            <td>13</td><td>8</td><td>3</td><td>8</td><td>0</td>
            <td>2</td><td>2</td><td>90</td><td class="red-text">8.4000</td>
          </tr>
          <tr>
            <td>102-KISWAHILI</td>
            <td>16</td><td>14</td><td>0</td><td>15</td><td>0</td>
            <td>9</td><td>8</td><td>5</td><td>9</td><td>1</td>
            <td>8</td><td>5</td><td>90</td><td class="red-text">7.5222</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- jsPDF CDN -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script>
    async function downloadPDF() {
      const { jsPDF } = window.jspdf;
      const report = document.getElementById("report-content");
      const canvas = await html2canvas(report, { scale: 2 });
      const imgData = canvas.toDataURL("image/png");

      const pdf = new jsPDF("p", "mm", "a4");
      const pageWidth = pdf.internal.pageSize.getWidth();
      const pageHeight = pdf.internal.pageSize.getHeight();

      const imgWidth = pageWidth;
      const imgHeight = canvas.height * imgWidth / canvas.width;

      pdf.addImage(imgData, "PNG", 0, 0, imgWidth, imgHeight);
      pdf.save("Grade_Analysis_Report.pdf");
    }
  </script>
</body>
</html>
