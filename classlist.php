<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Class List</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
  <style>
    body {
      background: #f4f6f9;
      font-family: Arial, sans-serif;
      font-size: 11px;
    }
    .class-card {
      background: #fff;
      padding: 10px;
      margin: 10px auto;
      max-width: 950px;
    }
    .header-section {
      text-align: center;
    }
    .header-section h5 {
      margin-bottom: 2px;
      font-weight: bold;
    }
    .header-section p {
      margin: 0;
      font-size: 12px;
    }
    .header-logo img {
      width: 80px;
      height: 80px;
      object-fit: contain;
    }
.school-info {
  text-align: right;
  font-size: 9px; /* smaller so it fits */
  white-space: normal; /* allows wrapping */
  word-break: break-all; /* forces break if needed */
}
    .action-buttons {
      text-align: right;
      margin-bottom: 8px;
    }
    .btn-custom {
      margin-left: 5px;
      border-radius: 20px;
      padding: 4px 10px;
      font-size: 11px;
    }
    table {
      border-collapse: collapse !important;
      width: 100%;
    }
    table th, table td {
      border: 1px solid #000 !important;
      font-size: 10px;
      padding: 2px 4px;
    }
    table th {
      background: #e9ecef !important;
      text-align: center;
      font-weight: bold;
    }
    .exam-label {
      margin: 5px 0;
      font-weight: bold;
      font-size: 11px;
      text-align: center;
    }
    /* Hide buttons when printing or exporting */
    .no-print {
      display: none !important;
    }
    @media print {
      body {
        margin: 8mm;
        background: #fff;
      }
      .class-card {
        margin: 0;
        padding: 0;
        border: none;
        box-shadow: none;
      }
      .action-buttons {
        display: none !important;
      }
    }
  </style>
</head>
<body>

<div class="class-card" id="classList">

  <!-- Buttons -->
  <div class="action-buttons" id="buttons">
    <button class="btn btn-primary btn-custom" onclick="window.print()">🖨 Print</button>
    <button class="btn btn-success btn-custom" onclick="downloadPDF()">⬇ Download PDF</button>
  </div>

  <!-- Header -->
  <div class="row align-items-center mb-2">
    <div class="col-2 text-center header-logo">
      <img src="school-logo.png" alt="School Logo">
    </div>
    <div class="col-8 header-section">
      <h5>Jehova Jire Secondary</h5>
      <p>Class List</p>
    </div>
    <div class="col-2 school-info">
      <p class="mb-0">738-00516</p>
      <p class="mb-0">0727478388</p>
      <p class="mb-0">jehovajire2009@yahoo.com</p>
    </div>
  </div>

  <!-- Exam Label -->
  <div class="exam-label">
    FORM 2 GREEN - BIOLOGY - 2025 - CLASS LIST <br>
    TEACHER: CHRISTINE KAGENDI
  </div>

  <!-- Table -->
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>ADMNO</th>
        <th>NAME</th>
        <th>GENDER</th>
        <th style="width:15%"></th>
        <th style="width:15%"></th>
        <th style="width:15%"></th>
        <th style="width:15%"></th>
      </tr>
    </thead>
    <tbody>
      <tr><td>1</td><td>2851</td><td>Marion Akinyi Okoth</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>2</td><td>2875</td><td>Emanuel Mungai Muthama</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>3</td><td>2876</td><td>Betty Wanja Njoroge</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>4</td><td>2882</td><td>Adrian Mwaura Murugi</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>5</td><td>2883</td><td>Janet Nyambia Njeru</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>6</td><td>2886</td><td>Mary Ruguru Macharia</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>7</td><td>2891</td><td>Allan Mwaura Kinuthia</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>8</td><td>2901</td><td>Kamau Wangui Faith</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>9</td><td>2902</td><td>Clinton Ouma Owino</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>10</td><td>2920</td><td>Mark Gachihi Wambui</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>11</td><td>2923</td><td>Tabitha Mbithe Musila</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>12</td><td>2939</td><td>Ruth Ndinda</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>13</td><td>2949</td><td>Lydia Wangui</td><td>Female</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>14</td><td>2951</td><td>Kanzika Mekvile</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>15</td><td>2975</td><td>Dennis Mutinda</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>16</td><td>2983</td><td>Martin Kabeu</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>17</td><td>3006</td><td>Alex Mwangi</td><td>Male</td><td></td><td></td><td></td><td></td></tr>
    </tbody>
  </table>
</div>

<script>
  function downloadPDF() {
    const buttons = document.getElementById("buttons");
    buttons.classList.add("no-print");

    const element = document.getElementById("classList");
    const opt = {
      margin: [0.3, 0.3, 0.3, 0.3],
      filename: "Class_List.pdf",
      image: { type: 'jpeg', quality: 0.98 },
      html2canvas: { scale: 2 },
      jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().from(element).set(opt).save().then(() => {
      buttons.classList.remove("no-print");
    });
  }
</script>

</body>
</html>
