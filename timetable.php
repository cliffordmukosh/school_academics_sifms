<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Form One Timetable</title>

    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            margin: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 10px;
        }

        .header h1 {
            margin: 0;
            font-size: 26px;
            letter-spacing: 1px;
        }

        .header h2 {
            margin: 5px 0;
            font-size: 18px;
            font-weight: normal;
        }

        .meta {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            margin-bottom: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th,
        td {
            border: 1px solid #000;
            text-align: center;
            vertical-align: middle;
            padding: 4px;
            position: relative;
        }

        th {
            font-size: 12px;
            font-weight: bold;
        }

        .day {
            font-size: 26px;
            font-weight: bold;
            width: 60px;
        }

        .time {
            font-size: 10px;
            font-weight: normal;
            display: block;
            margin-top: 3px;
        }

        .break,
        .lunch {
            font-size: 11px;
            font-weight: bold;
            background: #f5f5f5;
            writing-mode: vertical-rl;
            transform: rotate(180deg);
        }

        .subject {
            font-size: 18px;
            font-weight: bold;
        }

        .score {
            position: absolute;
            bottom: 2px;
            right: 4px;
            font-size: 10px;
        }

        .footer {
            margin-top: 8px;
            font-size: 10px;
            text-align: center;
        }
    </style>
</head>

<body>

    <div class="header">
        <h2>TERM I 2018</h2>
        <h1>FORM ONE</h1>
    </div>

    <div class="meta">
        <div>EXAMS DEPARTMENT</div>
        <div>Class teacher : MR DAVID</div>
    </div>
    <table>
        <tr>
            <th></th>
            <th>1<span class="time">08:00 - 08:40</span></th>
            <th>2<span class="time">08:40 - 09:20</span></th>
            <th class="break">BREAK<span class="time">09:20 - 09:30</span></th>
            <th>3<span class="time">09:30 - 10:10</span></th>
            <th>4<span class="time">10:10 - 10:50</span></th>
            <th class="break">BREAK<span class="time">10:50 - 11:20</span></th>
            <th>5<span class="time">11:20 - 12:00</span></th>
            <th>6<span class="time">12:00 - 12:40</span></th>
            <th>7<span class="time">12:40 - 01:20</span></th>
            <th class="lunch">LUNCH<span class="time">01:20 - 02:00</span></th>
            <th>8<span class="time">02:00 - 02:40</span></th>
            <th>9<span class="time">02:40 - 03:20</span></th>
            <th>10<span class="time">03:20 - 04:00</span></th>
        </tr>

        <!-- MONDAY -->
        <tr>
            <td class="day">Mo</td>
            <td>
                <div class="subject">GEO</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">MAT</div><span class="score">05</span>
            </td>
            <td rowspan="5" class="break"></td>
            <td>
                <div class="subject">HIS</div><span class="score">09</span>
            </td>
            <td>
                <div class="subject">CHE</div><span class="score">08</span>
            </td>
            <td rowspan="5" class="break"></td>
            <td>
                <div class="subject">CRE</div><span class="score">09</span>
            </td>
            <td>
                <div class="subject">MAT</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">KIS</div><span class="score">07</span>
            </td>
            <td rowspan="5" class="lunch"></td>
            <td>
                <div class="subject">PHY</div><span class="score">08</span>
            </td>
            <td>
                <div class="subject">ENG</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">GA</div><span class="score">06 / 08</span>
            </td>
        </tr>

        <!-- TUESDAY -->
        <tr>
            <td class="day">Tu</td>
            <td colspan="2">
                <div class="subject">BIO</div><span class="score">04</span>
            </td>
            <td>
                <div class="subject">ENG</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">MAT</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">KIS</div><span class="score">07</span>
            </td>
            <td>
                <div class="subject">CRE</div><span class="score">09</span>
            </td>
            <td>
                <div class="subject">B/ST</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">ENG</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">PE</div><span class="score">08</span>
            </td>
            <td>
                <div class="subject">CL</div><span class="score">03</span>
            </td>
        </tr>

        <!-- WEDNESDAY -->
        <tr>
            <td class="day">We</td>
            <td>
                <div class="subject">PHY</div><span class="score">08</span>
            </td>
            <td>
                <div class="subject">ENG</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">MAT</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">AGR</div><span class="score">04</span>
            </td>
            <td>
                <div class="subject">HIS</div><span class="score">09</span>
            </td>
            <td>
                <div class="subject">KIS</div><span class="score">07</span>
            </td>
            <td>
                <div class="subject">GEO</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">BIO</div><span class="score">04</span>
            </td>
            <td>
                <div class="subject">B/ST</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">GA</div><span class="score">06 / 08</span>
            </td>
        </tr>

        <!-- THURSDAY -->
        <tr>
            <td class="day">Th</td>
            <td colspan="2">
                <div class="subject">CHE</div><span class="score">08</span>
            </td>
            <td>
                <div class="subject">ENG</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">MAT</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">CRE</div><span class="score">09</span>
            </td>
            <td>
                <div class="subject">GEO</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">HIS</div><span class="score">09</span>
            </td>
            <td>
                <div class="subject">KIS</div><span class="score">07</span>
            </td>
            <td>
                <div class="subject">AGR</div><span class="score">04</span>
            </td>
            <td>
                <div class="subject">SOC</div><span class="score">06</span>
            </td>
        </tr>

        <!-- FRIDAY -->
        <tr>
            <td class="day">Fr</td>
            <td colspan="2">
                <div class="subject">PHY</div><span class="score">08</span>
            </td>
            <td>
                <div class="subject">B/ST</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">BIO</div><span class="score">04</span>
            </td>
            <td>
                <div class="subject">MAT</div><span class="score">05</span>
            </td>
            <td>
                <div class="subject">ENG</div><span class="score">10</span>
            </td>
            <td>
                <div class="subject">CHE</div><span class="score">08</span>
            </td>
            <td>
                <div class="subject">AGR</div><span class="score">04</span>
            </td>
            <td>
                <div class="subject">KIS</div><span class="score">07</span>
            </td>
            <td>
                <div class="subject">GA</div><span class="score">06 / 08</span>
            </td>
        </tr>
    </table>

    <div class="footer">
        01-MR. KIHORO, 02-MRS. JOHN, 03-MR. MUTUNGA, 04-MR. KIVOTO,
        05-MS. MUTUA, 06-MR. MUTUA, 07-MRS. JACOB, 08-MR. DAVID,
        09-MR. MULWA, 10-MR. MBITHI
    </div>

</body>

</html>