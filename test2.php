<?php
// ============================
// test.php (updated)
// Consumes ARIMA+GB Python JSON output and displays in a scrollable modal
// ============================

// Path to Python executable and script - change if needed

// Start session & simple auth (keep your existing logic)
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

// DB connection for basic stats (your DB settings)
$conn = new mysqli("localhost", "root", "", "mscookies");
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

// Basic stats (optional)
$stats = $conn->query("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(Quantity) AS total_items,
        SUM(Subtotal) AS total_sales 
    FROM sales
")->fetch_assoc();
$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = "C:\\xampp\\htdocs\\roben\\arima_gradientondb.py";

$output = shell_exec("$python $script 2>&1");

// Debug what Python actually returned
//echo "<pre>RAW OUTPUT:\n$output</pre>";

$data = json_decode($output, true);
//if ($data === null) {
//    echo "<pre>JSON decode failed: " . json_last_error_msg() . "</pre>";
//} else {
//    echo "<pre>JSON DECODE OK</pre>";
   echo "<script>var forecastData = " . json_encode($data['forecast']) . ";
  var metricsData = " . json_encode($data['metrics']) . ";</script>";

// --- Prepare forecast/backtest separation ---
// $data['forecast'] is an array of records; backtest rows contain "Actual_Sales",
// future rows may contain "Future_Forecast" only.
$forecast_records = $data['forecast'] ?? [];
$backtest_rows = [];
$future_rows = [];

// Separate records: treat rows with non-null Actual_Sales as backtest
foreach ($forecast_records as $rec) {
    // normalize keys in case of differing capitalization
    if (isset($rec['Actual_Sales']) && $rec['Actual_Sales'] !== null) {
        $backtest_rows[] = $rec;
    } else {
        $future_rows[] = $rec;
    }
}

// For verification table: take last 5 months from backtest (chronological assumed)
$verification_data = [];
if (!empty($backtest_rows)) {
    // keep only rows with Actual & Hybrid_Forecast present
    $valid = array_filter($backtest_rows, function($r) {
        return isset($r['Actual_Sales']) && $r['Actual_Sales'] !== null && isset($r['Hybrid_Forecast']);
    });
    // reindex and slice last 5
    $valid = array_values($valid);
    $verification_data = array_slice($valid, -5);
}

// Metrics from JSON (if any)
$metrics = $data['metrics'] ?? null;

// Future table: prefer $data['future_table'] if provided
$future_table = $data['future_table'] ?? $future_rows;

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Generate Reports - MSCookies</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <style>
    /* page baseline (keeps modal above everything) */
    html,body { height:100%; margin:0; font-family: Arial, Helvetica, sans-serif; }

    /* Generate button */
    #generateReportBtn {
      margin: 18px;
      padding: 10px 18px;
      background: #d96f86;
      color: #fff;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 15px;
    }

    /* Backdrop overlay (covers whole viewport including nav) */
    .modal {
      display: none;
      position: fixed;
      z-index: 5000;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0,0,0,0.38);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      overflow: hidden; /* keep page behind fixed */
    }

    /* Modal content (scrollable body) */
    .modal-content {
      background: #fff;
      margin: 4% auto;
      padding: 22px;
      border-radius: 12px;
      width: 86%;
      max-width: 1100px;
      max-height: 82vh;   /* keep within viewport */
      overflow-y: auto;   /* scroll inside modal */
      box-shadow: 0 8px 30px rgba(0,0,0,0.25);
      position: relative;
      z-index: 6000;
    }

    /* Optional: make header sticky (only the title + controls) */
    .modal-header {
      position: sticky;
      top: 0;
      background: #fff;
      padding-bottom: 10px;
      margin-bottom: 8px;
      z-index: 6100;
    }

    .modal-header h2 { margin: 0; display: inline-block; vertical-align: middle; }
    .modal-actions { float: right; }

    .close {
      cursor: pointer;
      font-size: 20px;
      border: none;
      background: transparent;
      padding: 6px 10px;
    }

    /* Tables */
    table { width:100%; border-collapse: collapse; margin: 14px 0; }
    table th, table td {
      border: 1px solid #e0d5d8;
      padding: 8px 10px;
      text-align: center;
      font-size: 14px;
    }
    table th {
      background: #f8e6ea;
      font-weight: 600;
    }

    .summary p { margin: 8px 0; line-height: 1.45; }

    /* small responsive tweak */
    @media (max-width:800px){
      .modal-content { width: 94%; padding: 14px; }
      table th, table td { font-size: 13px; padding:6px; }
    }
  </style>
</head>
<body>

  <!-- Place the button wherever in your layout -->
  <button id="generateReportBtn">Generate Reports</button>

  <!-- Modal (placed at body level so it overlays nav) -->
  <div id="reportModal" class="modal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
      <!-- header (sticky) -->
      <div class="modal-header">
        <h2 id="reportTitle">📊 Sales Report Summary</h2>
        <div class="modal-actions">
          <!-- optional download icon (placeholder) -->
          <button title="Download" style="margin-right:6px;">⬇️</button>
          <button class="close" id="closeBtn" title="Close">✖</button>
        </div>
        <div style="clear:both"></div>
      </div>

      <!-- summary -->
      <div class="summary">
        <p>The system has recorded a total of <strong>₱<?= number_format($stats['total_sales'] ?? 0, 2) ?></strong> in sales across <strong><?= intval($stats['total_orders'] ?? 0) ?></strong> orders.</p>
        <p>On average, each order contributes <strong>₱<?= number_format((($stats['total_orders'] ?? 1) > 0 ? ($stats['total_sales'] / max(1,$stats['total_orders'])) : 0), 2) ?></strong> in sales.</p>
      </div>

      <hr>

      <!-- Recent Sales (backtest preview if available) -->
      <h3>📈 Verification — Previous 5 Months (Actual vs Hybrid Forecast)</h3>
      <?php if (!empty($verification_data)): ?>
        <table>
          <thead>
            <tr>
              <th>Month</th>
              <th>Actual (₱)</th>
              <th>Hybrid Forecast (₱)</th>
              <th>Error (%)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($verification_data as $row): 
                // compute error safely
                $actual = isset($row['Actual_Sales']) ? floatval($row['Actual_Sales']) : null;
                $pred  = isset($row['Hybrid_Forecast']) ? floatval($row['Hybrid_Forecast']) : null;
                $errPct = (is_numeric($actual) && $actual != 0 && is_numeric($pred)) ? (abs($actual - $pred)/$actual)*100 : null;
            ?>
              <tr>
                <td><?= htmlspecialchars($row['DATE'] ?? ($row['date'] ?? '')) ?></td>
                <td>₱<?= is_numeric($actual) ? number_format($actual,2) : '-' ?></td>
                <td>₱<?= is_numeric($pred) ? number_format($pred,2) : '-' ?></td>
                <td><?= is_numeric($errPct) ? number_format($errPct,2) . '%' : '-' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p><em>No verification/backtest rows available to display.</em></p>
      <?php endif; ?>

      <!-- Prediction Accuracy -->
      <h3>📊 Prediction Accuracy</h3>
      <?php if (!empty($metrics)): ?>
        <table>
          <tbody>
            <tr><th>Metric</th><th>Value</th></tr>
            <tr><td>MAE</td><td><?= isset($metrics['MAE']) ? number_format($metrics['MAE'],2) : '-' ?></td></tr>
            <tr><td>RMSE</td><td><?= isset($metrics['RMSE']) ? number_format($metrics['RMSE'],2) : '-' ?></td></tr>
            <tr><td>MAPE</td><td><?= isset($metrics['MAPE']) ? number_format($metrics['MAPE'],2) . '%' : '-' ?></td></tr>
            <tr><td>Accuracy</td><td><?= isset($metrics['Accuracy']) ? number_format($metrics['Accuracy'],2) . '%' : '-' ?></td></tr>
            <tr><td>R²</td><td><?= isset($metrics['R2']) ? number_format($metrics['R2'],4) : '-' ?></td></tr>
          </tbody>
        </table>
      <?php else: ?>
        <p><em>No metrics available.</em></p>
      <?php endif; ?>

      <!-- Future Forecast Table -->
      <h3>📆 Future Forecast (12 months)</h3>
      <?php if (!empty($future_table)): ?>
        <table>
          <thead>
            <tr>
              <th>Month</th>
              <th>Forecast (₱)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($future_table as $f): 
                // future_table rows could have keys "DATE" and "Future_Forecast"
                $month = $f['DATE'] ?? ($f['date'] ?? '');
                $val = $f['Future_Forecast'] ?? ($f['Future_Forecast'] ?? null);
            ?>
              <tr>
                <td><?= htmlspecialchars($month) ?></td>
                <td>₱<?= is_numeric($val) ? number_format(floatval($val),2) : '-' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <p><em>No future forecast available.</em></p>
      <?php endif; ?>

      <!-- small footer spacing -->
      <div style="height:10px"></div>
    </div>
  </div>

  <script>
    // Modal open/close logic
    const modal = document.getElementById('reportModal');
    const openBtn = document.getElementById('generateReportBtn');
    const closeBtn = document.getElementById('closeBtn');

    openBtn.addEventListener('click', () => {
      modal.style.display = 'block';
      modal.setAttribute('aria-hidden','false');
      // lock body scroll behind modal
      document.body.style.overflow = 'hidden';
    });

    closeBtn.addEventListener('click', () => {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden','true');
      document.body.style.overflow = '';
    });

    window.addEventListener('click', (e) => {
      if (e.target === modal) {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
      }
    });

    // close with escape
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.style.display === 'block') {
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden','true');
        document.body.style.overflow = '';
      }
    });
  </script>
</body>
</html>
