<?php
$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = "C:\\xampp\\htdocs\\roben\\arima_gradientondb.py";

$output = shell_exec("$python $script 2>&1");

$data = json_decode($output, true);

echo "<script>var forecastData = " . json_encode($data['forecast']) . ";
var metricsData = " . json_encode($data['metrics']) . ";</script>";
?>
<!DOCTYPE html>
<html>
<head>
  <title>Sales Forecast Dashboard</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    canvas { max-width: 1000px; margin: 20px auto; display: block; }

    .metrics-container {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      justify-content: center;
      margin: 20px auto;
      max-width: 1000px;
    }
    .metric-card {
      flex: 1 1 180px;
      background: #f9f9f9;
      border: 1px solid #ccc;
      border-radius: 10px;
      padding: 20px;
      text-align: center;
      cursor: pointer;
      transition: 0.3s;
      box-shadow: 0px 2px 6px rgba(0,0,0,0.1);
    }
    .metric-card:hover {
      background: #eef6ff;
      transform: translateY(-3px);
    }
    .metric-title {
      font-weight: bold;
      font-size: 18px;
      margin-bottom: 10px;
    }
    .metric-value {
      font-size: 22px;
      color: #007BFF;
      margin-bottom: 10px;
    }
    .metric-explanation {
      display: none;
      font-size: 14px;
      color: #555;
      margin-top: 10px;
    }
    .metric-card.active .metric-explanation {
      display: block;
    }

    table {
      border-collapse: collapse;
      margin: 20px auto;
      width: 80%;
    }
    table, th, td {
      border: 1px solid #ccc;
      padding: 8px;
      text-align: center;
    }
    th {
      background: #f4f4f4;
    }
  </style>
</head>
<body>
  <h2 style="text-align:center;">Actual Sales vs Hybrid Forecast + Future Forecast</h2>
  <div style="background-color:aqua; max-width: fit-content; max-height:fit-content; margin:auto;">
    <canvas id="forecastChart" width="1150" height="650"></canvas>
  </div>

  <h2 style="text-align:center;">Model Metrics</h2>
  <div class="metrics-container">
    <div class="metric-card" onclick="toggleCard(this)">
      <div class="metric-title">MAE</div>
      <div class="metric-value" id="mae"></div>
      <div class="metric-explanation">Lower is better. Shows typical forecast error in sales units.</div>
    </div>
    <div class="metric-card" onclick="toggleCard(this)">
      <div class="metric-title">RMSE</div>
      <div class="metric-value" id="rmse"></div>
      <div class="metric-explanation">Lower is better. Sensitive to large mistakes.</div>
    </div>
    <div class="metric-card" onclick="toggleCard(this)">
      <div class="metric-title">MAPE</div>
      <div class="metric-value" id="mape"></div>
      <div class="metric-explanation">Average error as a % of actual sales.</div>
    </div>
    <div class="metric-card" onclick="toggleCard(this)">
      <div class="metric-title">Accuracy</div>
      <div class="metric-value" id="accuracy"></div>
      <div class="metric-explanation">Closer to 100% means a better model.</div>
    </div>
    <div class="metric-card" onclick="toggleCard(this)">
      <div class="metric-title">R²</div>
      <div class="metric-value" id="r2"></div>
      <div class="metric-explanation">Ranges from 0 to 1. Higher means better fit.</div>
    </div>
  </div>

  <h2 style="text-align:center;">12-Month Forecast Table</h2>
  <table>
    <tr>
        <th>Date</th>
        <th>Forecast</th>
    </tr>
    <?php foreach ($data["future_table"] as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row["DATE"]); ?></td>
            <td><?php echo number_format($row["Future_Forecast"], 2); ?></td>
        </tr>
    <?php endforeach; ?>
  </table>

  <script>
    // ===== CHART =====
    const labels = forecastData.map(d => d.DATE);
    const actual = forecastData.map(d => d.Actual_Sales ?? null);
    const hybrid = forecastData.map(d => d.Hybrid_Forecast ?? null);
    const future = forecastData.map(d => d.Future_Forecast ?? null);

    const ctxfuture = document.getElementById("forecastChart").getContext("2d");
    new Chart(ctxfuture, {
      type: "line",
      data: {
        labels: labels,
        datasets: [
          {
            label: "Actual Sales",
            data: actual,
            borderColor: "red",
            pointBackgroundColor: "red",
            borderWidth: 2,
            pointRadius: 4,
            fill: false
          },
          {
            label: "Hybrid Forecast",
            data: hybrid,
            borderColor: "pink",
            pointBackgroundColor: "pink",
            borderWidth: 2,
            pointRadius: 3,
            fill: false
          },
          {
            label: "Future Forecast",
            data: future,
            borderColor: "brown",
            borderDash: [6,6],
            pointBackgroundColor: "brown",
            borderWidth: 2,
            pointRadius: 3,
            fill: false
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          tooltip: { mode: "index", intersect: false },
          legend: { position: "top" }
        },
        interaction: { mode: "nearest", axis: "x", intersect: false },
        scales: {
          x: { title: { display: true, text: "Date" } },
          y: { title: { display: true, text: "Sales" }, beginAtZero: true }
        }
      }
    });

    // ===== METRICS =====
    document.getElementById("mae").innerText = metricsData.MAE.toFixed(2);
    document.getElementById("rmse").innerText = metricsData.RMSE.toFixed(2);
    document.getElementById("mape").innerText = metricsData.MAPE.toFixed(2) + "%";
    document.getElementById("accuracy").innerText = metricsData.Accuracy.toFixed(2) + "%";
    document.getElementById("r2").innerText = metricsData.R2.toFixed(2);

    // Toggle explanation
    function toggleCard(card) {
      card.classList.toggle("active");
    }
  </script>
</body>
</html>
