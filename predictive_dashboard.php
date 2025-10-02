<?php
// Auto-run the Python forecast script
$output = shell_exec("sarima.py 2>&1");  // Or use "python3" if needed
// echo $output; // Optional: for debugging
?>
<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
// Add at the top after DB connection
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
// Add staff
$error = '';
?>
<?php
$conn = new mysqli("localhost", "root", "", "mscookies");

// Fetch actual data
$sql = "SELECT Quantity, Subtotal, Unit_Price FROM sales 
        JOIN product ON sales.Product_ID = product.Product_ID";
$result = $conn->query($sql);

$data = [
    "Quantity_vs_Subtotal" => [],
    "Quantity_vs_Price" => [],
    "Price_vs_Subtotal" => []
];

while ($row = $result->fetch_assoc()) {
    $qty = (float)$row['Quantity'];
    $subtotal = (float)$row['Subtotal'];
    $price = (float)$row['Unit_Price'];

    $data["Quantity_vs_Subtotal"][] = ["x" => $qty, "y" => $subtotal];
    $data["Quantity_vs_Price"][] = ["x" => $qty, "y" => $price];
    $data["Price_vs_Subtotal"][] = ["x" => $price, "y" => $subtotal];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Interactive Forecast</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --sidebar-bg: #ff7e94;
      --sidebar-active: #fff;
      --sidebar-icon: #fff;
      --sidebar-hover: #ffb3c1;
      --main-bg: #f4c6c6;
      --card-bg: #fff;
      --primary: #ec3462;
      --text-dark: #222;
      --text-light: #fff;
    }

    * {
      box-sizing: border-box;
    }

    html, body {
      height: 100%;
      margin: 0;
      font-family: 'Arial', sans-serif;
      background: var(--main-bg);
    }

    .dashboard {
      display: flex;
      min-height: 100vh;
    }

    .sidebar {
      width: 80px;
      background: var(--sidebar-bg);
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 0 16px 0;
      position: fixed;
      top: 0;
      bottom: 0;
    }

    .sidebar .logo {
      width: 56px;
      height: 56px;
      margin-bottom: 32px;
      border-radius: 50%;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .sidebar .logo img {
      width: 48px;
      height: 48px;
      object-fit: contain;
    }

    .nav {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 24px;
      align-items: center;
    }

    .nav-icon {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
      position: relative;
    }

    .nav-icon.active, .nav-icon:hover {
      background: #fff;
      color: #ec3462;
    }

  .notification-badge {
      position: absolute;
      top: 2px;
      right: 2px;
      width: 12px;
      height: 12px;
      background: #ec3462;
      border-radius: 50%;
      display: block;
      border: 2px solid #fff;
      z-index: 2;
    }

    .logout-btn {
      width: 40px;
      height: 40px;
      background: var(--sidebar-hover);
      color: var(--primary);
      border: none;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 20px;
      transition: background 0.2s;
    }

    .logout-btn:hover {
      background: var(--primary);
      color: #fff;
    }

    .main-content {
      margin-left: 80px;
      padding: 40px 32px;
      flex: 1;
      
    }

    h2 {
      margin-top: 0;
      color: var(--primary);
    }

    canvas {
      max-width: 100%;
      margin: 30px auto;
      display: block;
    }

    #dashboardStats {
      display: flex;
      flex-wrap: wrap;
      gap: 25px;
      justify-content: center;
      margin-top: 40px;
    }

    .card {
      background: #ffe3e3;
      border-radius: 12px;
      padding: 30px 40px;
      font-size: 20px;
      font-weight: 600;
      box-shadow: 0 6px 16px rgba(0,0,0,0.12);
      color: #222;
      min-width: 300px;
      max-width: 350px;
      text-align: center;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .card:hover {
      transform: translateY(-6px);
      box-shadow: 0 10px 22px rgba(0,0,0,0.18);
    }

    .card strong {
      color: #c62828;
    }

    .description {
      margin-top: 15px;
      font-size: 16px;
      font-weight: normal;
      color: #444;
      line-height: 1.5;
    }

    table {
      margin-top: 40px;
      border-collapse: collapse;
      width: 100%;
      max-width: 900px;
      background: white;
    }

    th, td {
      border: 1px solid #ccc;
      padding: 10px 14px;
      text-align: center;
    }

    th {
      background: #f98ca3;
      color: #fff;
    }

    tr:nth-child(even) {
      background: #fce5ea;
    }

    /* Dropdown under Visualization */
    #vizDropdown {
      display: none;
      position: absolute;
      top: 40px;
      left: 50%;
      transform: translateX(-50%);
      background: white;
      border-radius: 6px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.2);
      overflow: hidden;
      z-index: 999;
      min-width: 140px;
    }

    #vizDropdown button {
      all: unset;
      display: block;
      padding: 10px 16px;
      width: 100%;
      text-align: left;
      cursor: pointer;
    }

    #vizDropdown button:hover {
      background: #ffeff4;
    }
       /* Modal styles */
    .modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.35);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .modal {
      background: #fff;
      border-radius: 10px;
      padding: 36px 32px 28px 32px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.18);
      text-align: center;
      min-width: 320px;
      max-width: 90vw;
    }
    .modal-title {
      font-size: 20px;
      font-weight: bold;
      margin-bottom: 32px;
      color: #222;
    }
    .modal-btns {
      display: flex;
      gap: 18px;
      justify-content: center;
    }
    .modal-btn {
      padding: 12px 36px;
      border-radius: 4px;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      border: 2px solid transparent;
      transition: background 0.2s, color 0.2s, border 0.2s;
    }
    .modal-btn.confirm {
      background: #ec3462;
      color: #fff;
      border: 2px solid #ec3462;
    }
    .modal-btn.confirm:hover {
      background: #c72b52;
      border-color: #c72b52;
    }
    .modal-btn.cancel {
      background: #fff;
      color: #ec3462;
      border: 2px solid #ec3462;
    }
    .modal-btn.cancel:hover {
      background: #ffe6ee;
    }
    @media (max-width: 900px) {
      .container {
        flex-direction: column;
        width: 100vw;
        min-width: 0;
        margin: 0;
        border-radius: 0;
      }}
          canvas {
       max-width: 1000px;
      margin: 40px auto;
      display: block;
      background: white;
      border-radius: 10px;
      padding: 20px;
    }
    table {
      margin: 40px auto;
      border-collapse: collapse;
      width: 90%;
      background: white;
      border-radius: 8px;
      overflow: hidden;
    }
 
    th, td {
      border: 1px solid #ccc;
      padding: 12px;
      text-align: center;
    }

    th {
      background: #f98ca3;
      color: white;
    }

    tr:nth-child(even) {
      background: #fdf1f3;
    }
    #dashboardStats {
    display: flex;
    flex-wrap: wrap;
    gap: 25px;
    justify-content: center;
    margin-top: 40px;
  }

  .card {
    background: #ffe3e3;
    border-radius: 12px;
    padding: 30px 40px;
    font-family: 'Segoe UI', sans-serif;
    font-size: 20px;
    font-weight: 600;
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
    color: #222;
    min-width: 320px;
    max-width: 350px;
    text-align: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .card:hover {
    transform: translateY(-6px);
    box-shadow: 0 10px 22px rgba(0,0,0,0.18);
  }

  .card strong {
    color: #c62828;
  }

  .description {
    margin-top: 15px;
    font-size: 16px;
    font-weight: normal;
    color: #444;
    line-height: 1.5;
  }
  .middle{
    display: flex;
    align-items: center;
    justify-content: center;
  }
   .container2 {
            max-width: 950px;
            margin: auto;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 0 16px rgba(0,0,0,0.1);
        }
             .canvas1 {
            background: #fff;
            border: 1px solid #ccc;
            padding: 20px;
            border-radius: 12px;
            margin: 30px auto 10px auto;
            display: block;
        }
        .interpretation {
            margin-top: 25px;
            background: #ffe9ec;
            padding: 18px 20px;
            border-left: 6px solid #ec3462;
            border-radius: 6px;
            font-size: 15px;
            color: #333;
        }
         select {
            display: block;
            margin: 20px auto;
            padding: 10px 16px;
            font-size: 16px;
            border-radius: 6px;
        }
  </style>
</head>
<body onload="showCookieLoader()">
 
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="logo">
        <img src="msclogo.jpg" alt="MSC Cookies Logo">
      </div>
      <div class="nav">
       
        <div style="position: relative;">
          <div class="nav-icon active" title="Visualization" onclick="toggleDropdown()">
            <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4.5V19a1 1 0 0 0 1 1h15M7 14l4-4 4 4 5-5m0 0h-3.207M20 9v3.207"/>
</svg>
          </div>
          <div id="vizDropdown">
            <button onclick="chooseViz('predictive')">📈 Predictive</button>
            <button onclick="chooseViz('descriptive')">📊 Descriptive</button>
          </div>
        </div>
         <div class="nav-icon" title="Home" onclick="window.location.href='admin_dashboard.php'">
          <!-- Home icon SVG -->
          <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.65692 9.41494h.01M7.27103 13h.01m7.67737 1.9156h.01M10.9999 17h.01m3.178-10.90671c-.8316.38094-1.8475.22903-2.5322-.45571-.3652-.36522-.5789-.82462-.6409-1.30001-.0574-.44-.0189-.98879.1833-1.39423-1.99351.20001-3.93304 1.06362-5.46025 2.59083-3.51472 3.51472-3.51472 9.21323 0 12.72793 3.51471 3.5147 9.21315 3.5147 12.72795 0 1.5601-1.5602 2.4278-3.5507 2.6028-5.5894-.2108.008-.6725.0223-.8328.0157-.635.0644-1.2926-.1466-1.779-.633-.3566-.3566-.5651-.8051-.6257-1.2692-.0561-.4293.0145-.87193.2117-1.26755-.1159.20735-.2619.40237-.4381.57865-1.0283 1.0282-2.6953 1.0282-3.7235 0-1.0282-1.02824-1.0282-2.69531 0-3.72352.0977-.09777.2013-.18625.3095-.26543"/>
</svg>
        </div>
         <div class="nav-icon" title="Notifications" onclick="window.location.href='notifications.php'" style="position:relative;">
        <!-- Bell icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($hasNotifications): ?>
          <span class="notification-badge"></span>
        <?php endif; ?>
      </div>
      <div class="nav-icon" title="Sales" onclick="window.location.href='sales_history.php'">
        <!-- Line graph icon -->
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-list-check" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M5 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3.854 2.146a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 3.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 7.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0"/>
</svg>

      </div>
      <div class="nav-icon" title="Settings" onclick="window.location.href='settings.php'">
        <!-- Gear icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </div>
      <div class="nav-icon" title="People" onclick="window.location.href='profile.php'">
        <!-- Person icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 0 1 13 0"/></svg>
      </div>
      <div class="nav-icon active" title="Staff Management">
        <!-- Group icon (active) -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="7" cy="8" r="3"/><circle cx="17" cy="8" r="3"/><circle cx="12" cy="17" r="3"/><path d="M2 21v-2a4 4 0 0 1 4-4h2m8 0h2a4 4 0 0 1 4 4v2"/></svg>
      </div>
      <div class="nav-icon" title="Add Product" onclick="window.location.href='products_management.php'">
        <!-- Add icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
      </div>
    </div>
        <button class="logout-btn" id="logoutBtn" title="Logout">
          <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M17 16l4-4m0 0l-4-4m4 4H7"/><path d="M3 21V3"/>
          </svg>
        </button>
      </div>
   

    <!-- Main content -->
  <div class="main-content">

  <h2>📈 Monthly Sales and Forecast</h2>

  <!-- Forecast Chart -->
  <canvas id="forecastChart" width="500" height="250"></canvas>

  <!-- Prediction Summary -->
  <div id="dashboardStats" style="display:flex; flex-wrap:wrap; gap:20px; justify-content:center; margin-top:40px;">
    <div class="card">
      <strong>Top Product</strong><br>
      <span id="topProduct">Loading...</span>
    </div>
    <div class="card">
      <strong>Prediction Accuracy</strong><br>
      <span id="accuracy">Loading...</span>
    </div>
  </div>

  <!-- Forecasted Table -->
  <h3 style="text-align:center; margin-top: 50px;">📅 Forecasted Sales Table</h3>
  <table id="forecastTable">
    <thead>
      <tr><th>Month</th><th>Forecasted Sales (₱)</th></tr>
    </thead>
    <tbody></tbody>
  </table>
  <h3>🔍 Actual vs Predicted Verification</h3>
  <table id="verificationTable">
    <thead>
      <tr>
        <th>Month</th>
        <th>Actual (₱)</th>
        <th>Predicted (₱)</th>
        <th>Difference (₱)</th>
        <th>% Error</th>
        <th>% Accuracy</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
  <div class="container2">
    <h2>📊 Linear Regression Visualization</h2>
    <select id="topicSelect" onchange="updateChart()">
        <option value="Quantity_vs_Subtotal">Quantity vs Subtotal</option>
        <option value="Quantity_vs_Price">Quantity vs Product Price</option>
        <option value="Price_vs_Subtotal">Product Price vs Subtotal</option>
    </select>

    <canvas id="regressionChart" width="800" height="500" class="canvas1"></canvas>
    <div class="interpretation" id="interpretationBox"></div>
</div>
</div>

  <script>
    // Load forecast chart & table
    fetch("forecast_chart_data.json")
      .then(res => res.json())
      .then(data => {
        const labels = data.labels;
        const values = data.values;
        const types = data.types;

        const actualData = values.map((val, i) => types[i] === 'Actual' ? val : null);
        const forecastData = values.map((val, i) => types[i] === 'Forecast' ? val : null);

        const tbody = document.querySelector("#forecastTable tbody");
        labels.forEach((label, i) => {
          if (types[i] === 'Forecast') {
            const row = document.createElement('tr');
            row.innerHTML = `
              <td>${label}</td>
              <td>₱${values[i].toLocaleString()}</td>
            `;
            tbody.appendChild(row);
          }
        });

        const ctx3 = document.getElementById("forecastChart").getContext("2d");
        new Chart(ctx3, {
          type: "line",
          data: {
            labels: labels,
            datasets: [
              {
                label: "Actual Sales (₱)",
                data: actualData,
                borderColor: "#4A90E2",
                backgroundColor: "rgba(74, 144, 226, 0.1)",
                fill: true,
                tension: 0.4,
                pointRadius: 6,
                pointStyle: "circle"
              },
              {
                label: "Forecasted Sales (₱)",
                data: forecastData,
                borderColor: "#F98CA3",
                backgroundColor: "rgba(249, 140, 163, 0.2)",
                fill: true,
                tension: 0.4,
                pointRadius: 6,
                pointStyle: "triangle",
                borderDash: [5, 5]
              }
            ]
          },
          options: {
            responsive: true,
            plugins: {
              legend: {
                labels: {
                  font: { size: 14 }
                }
              },
              tooltip: {
                callbacks: {
                  label: ctx => `${ctx.dataset.label}: ₱${ctx.parsed.y.toLocaleString()}`
                }
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  callback: val => '₱' + val.toLocaleString(),
                  font: { size: 14 }
                }
              },
              x: {
                ticks: {
                  font: { size: 14 }
                }
              }
            }
          }
        });
      });

    // Load dashboard summary cards
    fetch("dashboard_stats.json")
      .then(res => res.json())
      .then(data => {
        document.getElementById("dashboardStats").innerHTML = `
          <div class="card">
            📊 RMSE: ₱${data.RMSE.toLocaleString()}
            <div class="description">
              Root Mean Squared Error shows average deviation. Lower is better.
            </div>
          </div>
          <div class="card">
            📉 MAE: ₱${data.MAE.toLocaleString()}
            <div class="description">
              Mean Absolute Error reflects average absolute gap between actual and forecast.
            </div>
          </div>
          <div class="card">
            📈 MAPE: ${data.MAPE.toFixed(2)}%
            <div class="description">
              Mean Absolute Percentage Error gives accuracy % — lower = better.
            </div>
          </div>
          <div class="card">
            🍪 Top Product (${data.Top_Product_Month}): <strong>${data.Top_Product}</strong>
            <div class="description">
              This product sold the most in the latest forecasted period.
            </div>
          </div>
        `;
      });

    // Load actual vs predicted verification table
    fetch("prediction_verification.json")
      .then(res => res.json())
      .then(data => {
        const tbody = document.querySelector("#verificationTable tbody");
        data.forEach(item => {
          const row = document.createElement("tr");
          row.innerHTML = `
            <td>${item.month}</td>
            <td>₱${item.actual.toLocaleString()}</td>
            <td>₱${item.predicted.toLocaleString()}</td>
            <td>₱${item.difference.toLocaleString()}</td>
            <td>${item.percent_error.toFixed(2)}%</td>
            <td>${item.accuracy.toFixed(2)}%</td>
            <td style="color:${item.status === 'Met' ? 'green' : 'red'};">
              ${item.status === 'Met' ? '✔ Met' : '❌ Missed'}
            </td>
          `;
          tbody.appendChild(row);
        });
      });
  </script>
  <script>
    // Logout modal logic (matches other pages)
    const logoutBtn = document.getElementById('logoutBtn');
    const modalOverlay = document.getElementById('modalOverlay');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');

    logoutBtn.addEventListener('click', function() {
        modalOverlay.style.display = 'flex';
    });
    cancelLogout.addEventListener('click', function() {
        modalOverlay.style.display = 'none';
    });
    confirmLogout.addEventListener('click', function() {
        window.location.href = 'logout.php';
    });
    
</script>
<script>
function toggleDropdown() {
  const dropdown = document.getElementById('vizDropdown');
  dropdown.style.display = (dropdown.style.display === 'block') ? 'none' : 'block';
}

// Close dropdown if clicked outside
document.addEventListener('click', function(e) {
  const dropdown = document.getElementById('vizDropdown');
  const isClickInside = e.target.closest('.nav-icon[title="Visualization"]') || e.target.closest('#vizDropdown');
  if (!isClickInside) dropdown.style.display = 'none';
});

function chooseViz(type) {
  if (type === 'predictive') {
    window.location.href = 'predictive_dashboard.php';
  } else if (type === 'descriptive') {
    window.location.href = 'descriptive_dashboard.php';
  }
}
</script>
<script>
const allData = <?php echo json_encode($data); ?>;
let chart;

const interpretations = {
  "Quantity_vs_Subtotal": "As <strong>Quantity</strong> increases, the <strong>Subtotal</strong> tends to increase. This means selling more items generally results in higher revenue.",
  "Quantity_vs_Price": "This shows if buying more quantity influences the <strong>Unit Price</strong>. Normally, price is stable, but discounts may cause slight decreases.",
  "Price_vs_Subtotal": "Higher <strong>Product Price</strong> may result in higher <strong>Subtotal</strong>, especially if customers buy premium-priced items."
};

function calculateRegression(dataPoints) {
    const n = dataPoints.length;
    const sumX = dataPoints.reduce((sum, pt) => sum + pt.x, 0);
    const sumY = dataPoints.reduce((sum, pt) => sum + pt.y, 0);
    const sumXY = dataPoints.reduce((sum, pt) => sum + pt.x * pt.y, 0);
    const sumX2 = dataPoints.reduce((sum, pt) => sum + pt.x * pt.x, 0);

    const slope = (n * sumXY - sumX * sumY) / (n * sumX2 - sumX * sumX);
    const intercept = (sumY - slope * sumX) / n;

    return { slope, intercept };
}

function updateChart() {
    const topic = document.getElementById('topicSelect').value;
    const points = allData[topic];
    const regression = calculateRegression(points);

    const minX = Math.min(...points.map(p => p.x));
    const maxX = Math.max(...points.map(p => p.x));
    const regressionLine = [
        { x: minX, y: regression.slope * minX + regression.intercept },
        { x: maxX, y: regression.slope * maxX + regression.intercept }
    ];

    const ctx4 = document.getElementById('regressionChart').getContext('2d');
    if (chart) chart.destroy();

    chart = new Chart(ctx4, {
        type: 'scatter',
        data: {
            datasets: [
                {
                    label: 'Data Points',
                    data: points,
                    backgroundColor: '#222',
                    pointRadius: 5
                },
                {
                    label: `y = ${regression.intercept.toFixed(2)} + ${regression.slope.toFixed(2)}x`,
                    type: 'line',
                    data: regressionLine,
                    borderColor: '#f98ca3',
                    borderWidth: 2,
                    fill: false,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { labels: { font: { size: 14 } } },
                tooltip: {
                    callbacks: {
                        label: context => `x: ${context.parsed.x}, y: ₱${context.parsed.y}`
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: topic.split('_vs_')[0].replace(/_/g, ' '),
                        font: { size: 14 }
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: topic.split('_vs_')[1].replace(/_/g, ' '),
                        font: { size: 14 }
                    }
                }
            }
        }
    });

    document.getElementById("interpretationBox").innerHTML = interpretations[topic];
}

// Initialize
updateChart();
</script>
</body>
</html>
<?php include 'loadingscreen.html'; ?>