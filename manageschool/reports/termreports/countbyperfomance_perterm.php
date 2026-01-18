<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bottom 5 Students Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body {
      background: #fff;
      font-family: Arial, sans-serif;
    }
    .report-container {
      max-width: 1100px;
      margin: 20px auto;
      padding: 20px;
      background: #fff;
      border: 1px solid #ccc;
    }
    h4, h5 {
      font-weight: bold;
      margin: 0;
    }
    .header {
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      border-bottom: 2px solid #000;
      padding-bottom: 10px;
    }
    .header img {
      height: 70px;
      width: 70px;
      object-fit: contain;
      margin-right: 15px;
    }
    table {
      font-size: 14px;
    }
    th, td {
      text-align: center;
      vertical-align: middle;
    }
    .red-text {
      color: red;
      font-weight: bold;
    }

    /* Print settings */
    @media print {
      .no-print {
        display: none;
      }
      @page {
        size: landscape;
        margin: 1cm;
      }
    }
  </style>
</head>
<body>
      <div class="text-center mt-3 no-print">
      <button onclick="window.print()" class="btn btn-dark">Print Report</button>
    </div>
  <div class="report-container">

    <!-- School Header -->
    <div class="header">
      <img src="school-logo.png" alt="School Logo">
      <div class="text-center">
        <h4>KEILA HIGH SCHOOL</h4>
        <h5>Bottom 5 Students Report</h5>
        <p>Form Two Term III - Year 2025</p>
      </div>
    </div>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-bordered table-sm">
        <thead class="table-light">
          <tr>
            <th>Adm No</th>
            <th>Student Name</th>
            <th>English</th>
            <th>Kiswahili</th>
            <th>Mathematics</th>
            <th>Biology</th>
            <th>Physics</th>
            <th>Chemistry</th>
            <th>History</th>
            <th>Mean Points</th>
            <th>Grade</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>1012</td>
            <td>John Doe</td>
            <td>32 (D)</td>
            <td>40 (D+)</td>
            <td>28 (E)</td>
            <td>36 (D)</td>
            <td>42 (D+)</td>
            <td>30 (E)</td>
            <td>35 (D)</td>
            <td class="red-text">3.2</td>
            <td class="red-text">D</td>
          </tr>
          <tr>
            <td>1015</td>
            <td>Mary Ann</td>
            <td>40 (D+)</td>
            <td>38 (D)</td>
            <td>30 (E)</td>
            <td>42 (D+)</td>
            <td>36 (D)</td>
            <td>33 (E)</td>
            <td>39 (D)</td>
            <td class="red-text">3.5</td>
            <td class="red-text">D+</td>
          </tr>
          <tr>
            <td>1020</td>
            <td>Paul Kim</td>
            <td>28 (E)</td>
            <td>35 (D)</td>
            <td>25 (E)</td>
            <td>32 (D)</td>
            <td>30 (E)</td>
            <td>28 (E)</td>
            <td>33 (D)</td>
            <td class="red-text">2.8</td>
            <td class="red-text">E</td>
          </tr>
          <tr>
            <td>1033</td>
            <td>Alice Njeri</td>
            <td>36 (D)</td>
            <td>39 (D)</td>
            <td>29 (E)</td>
            <td>35 (D)</td>
            <td>31 (E)</td>
            <td>34 (D)</td>
            <td>30 (E)</td>
            <td class="red-text">3.0</td>
            <td class="red-text">D</td>
          </tr>
          <tr>
            <td>1040</td>
            <td>Samuel Otieno</td>
            <td>34 (D)</td>
            <td>28 (E)</td>
            <td>26 (E)</td>
            <td>30 (E)</td>
            <td>29 (E)</td>
            <td>27 (E)</td>
            <td>32 (D)</td>
            <td class="red-text">2.5</td>
            <td class="red-text">E</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Print button -->
    <div class="text-center mt-3 no-print">
      <button onclick="window.print()" class="btn btn-dark">Print Report</button>
    </div>

  </div>
</body>
</html>
