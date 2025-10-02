<?php
session_start();
require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * export_report_all.php
 * Export ALL TIME sales report
 */

// === DB Connection ===
$conn = new mysqli("localhost", "root", "", "mscookies");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log download activity
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    try {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $details = json_encode([
            'username' => $username,
            'file_type' => 'PDF Report',
            'file_name' => 'sales_report_all_time.pdf',
            'report_type' => "All Time Sales Report",
            'download_time' => date('Y-m-d H:i:s')
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'download', ?, ?, ?, ?)");
        $description = "User '{$username}' downloaded All Time Sales Report (PDF)";
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Download logging error: " . $e->getMessage());
    }
}

// Time filter: ALL TIME (no WHERE clause)
$whereClause = "";
$timePeriodText = "overall";

/* --------------------------
   Helper functions
   -------------------------- */
function first_key_value(array $arr, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') {
            return $arr[$k];
        }
    }
    return null;
}

function normalize_month($s) {
    if ($s === null) return null;
    if (preg_match('/^\d{4}-\d{2}$/', $s)) return $s;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return substr($s, 0, 7);
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m', $ts);
    return null;
}

/* --------------------------
   Descriptive analytics
   -------------------------- */
// Totals
$totalSalesQuery = $conn->query("SELECT SUM(Subtotal) as totalSales, COUNT(DISTINCT Order_Code) as totalOrders FROM sales $whereClause");
$totals = $totalSalesQuery->fetch_assoc();
$totalSales = $totals['totalSales'] ?? 0;
$totalOrders = $totals['totalOrders'] ?? 0;
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

// Best-selling product
$topProductQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 1
");
$topProduct = $topProductQuery->fetch_assoc();

// Peak sales period
$peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%Y-%M') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
$peakPeriodResult = $conn->query($peakQuery);
$peakPeriod = $peakPeriodResult ? $peakPeriodResult->fetch_assoc() : null;

// Recent sales (last 10 orders)
$recentSalesQuery = $conn->query("
    SELECT DATE_FORMAT(s.Sales_Date, '%b %d, %Y') as date, s.Customer_Name, s.Subtotal, p.Product_Name, s.Quantity
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    ORDER BY s.Sales_Date DESC
    LIMIT 10
");
$recentSales = $recentSalesQuery->fetch_all(MYSQLI_ASSOC);

// Top 3 products from recent sales
$topRecentProductsQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM (
        SELECT * FROM sales $whereClause ORDER BY Sales_Date DESC LIMIT 10
    ) s
    JOIN product p ON s.Product_ID = p.Product_ID
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 3
");
$topRecentProducts = $topRecentProductsQuery->fetch_all(MYSQLI_ASSOC);

/* --------------------------
   Predictive Analytics: run Python and decode JSON
   -------------------------- */
$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = __DIR__ . "\\arima_gradientondb.py";
$output = shell_exec("$python $script 2>&1");

$data = json_decode($output, true);
if ($data === null) {
    $data = [];
}

$forecastData = $data['forecast'] ?? [];
$metrics = $data['metrics'] ?? [];
$futureTable = $data['future_table'] ?? [];

/* --------------------------
   Build hybrid forecast map
   -------------------------- */
$hybridForecast = [];

if (!empty($data['hybrid_forecast']) && is_array($data['hybrid_forecast'])) {
    foreach ($data['hybrid_forecast'] as $item) {
        if (!is_array($item)) continue;
        $monthRaw = first_key_value($item, ['month','Month','DATE','date','Date','MONTH']);
        $valRaw   = first_key_value($item, ['forecast','prediction','predicted','Hybrid_Forecast','Hybrid','hybrid','value','Future_Forecast']);
        $month = normalize_month($monthRaw);
        if ($month !== null && $valRaw !== null && $valRaw !== '') {
            $hybridForecast[$month] = (float)$valRaw;
        }
    }
}

if (empty($hybridForecast) && !empty($data['forecast']) && is_array($data['forecast'])) {
    foreach ($data['forecast'] as $item) {
        if (!is_array($item)) continue;
        $monthRaw = first_key_value($item, ['DATE','date','Date','month','Month']);
        $valRaw = first_key_value($item, ['Hybrid_Forecast','hybrid','Hybrid','HybridForecast','Future_Forecast','forecast','prediction']);
        $month = normalize_month($monthRaw);
        if ($month !== null && $valRaw !== null && $valRaw !== '') {
            $hybridForecast[$month] = (float)$valRaw;
        }
    }
}

if (empty($hybridForecast) && !empty($data['future_table']) && is_array($data['future_table'])) {
    foreach ($data['future_table'] as $item) {
        if (!is_array($item)) continue;
        $monthRaw = first_key_value($item, ['DATE','date','Month','month']);
        $valRaw = first_key_value($item, ['Future_Forecast','future_forecast','forecast','prediction']);
        $month = normalize_month($monthRaw);
        if ($month !== null && $valRaw !== null && $valRaw !== '') {
            $hybridForecast[$month] = (float)$valRaw;
        }
    }
}

/* --------------------------
   Get actual sales (last 5 months) from DB
   -------------------------- */
$actualSales = [];
$actualQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%Y-%m') AS month,
           SUM(Subtotal) AS actual_sales
    FROM sales
    GROUP BY month
    ORDER BY month DESC
    LIMIT 5
");
if ($actualQuery) {
    while ($row = $actualQuery->fetch_assoc()) {
        $m = normalize_month($row['month']);
        if ($m !== null) $actualSales[$m] = (float)$row['actual_sales'];
    }
}

// Merge into array with computed error/accuracy
$actualVsHybrid = [];
foreach ($actualSales as $month => $actual) {
    $forecast = $hybridForecast[$month] ?? null;
    if ($forecast === null) {
        $error = null;
        $accuracy = null;
    } else {
        if ($actual > 0) {
            $error = abs($actual - $forecast) / $actual * 100;
        } elseif ($forecast > 0) {
            $error = abs($actual - $forecast) / $forecast * 100;
        } else {
            $error = null;
        }
        $accuracy = ($error === null) ? null : max(0, 100 - $error);
    }
    $actualVsHybrid[] = [
        'month' => $month,
        'actual_sales' => $actual,
        'hybrid_forecast' => $forecast,
        'percent_error' => $error,
        'accuracy' => $accuracy
    ];
}

usort($actualVsHybrid, function($a,$b){
    return strcmp($a['month'],$b['month']);
});

/* --------------------------
   Prepare future forecast table
   -------------------------- */
$futureTableNormalized = [];
if (!empty($data['future_table']) && is_array($data['future_table'])) {
    foreach ($data['future_table'] as $row) {
        if (!is_array($row)) continue;
        $monthRaw = first_key_value($row, ['DATE','date','Month','month']);
        $valRaw   = first_key_value($row, ['Future_Forecast','future_forecast','Future','forecast','prediction']);
        $m = normalize_month($monthRaw);
        if ($m !== null && $valRaw !== null && $valRaw !== '') {
            $futureTableNormalized[] = ['month' => $m, 'forecast' => (float)$valRaw];
        }
    }
} else {
    if (!empty($forecastData) && is_array($forecastData)) {
        foreach ($forecastData as $row) {
            if (!is_array($row)) continue;
            if (array_key_exists('Actual_Sales', $row) && $row['Actual_Sales'] !== null) continue;
            $monthRaw = first_key_value($row, ['DATE','date','Month','month']);
            $valRaw   = first_key_value($row, ['Future_Forecast','future_forecast','Future','Hybrid_Forecast','Hybrid','forecast','prediction']);
            $m = normalize_month($monthRaw);
            if ($m !== null && $valRaw !== null && $valRaw !== '') {
                $futureTableNormalized[] = ['month' => $m, 'forecast' => (float)$valRaw];
            }
        }
    }
}

/* --------------------------
   DOMPDF: Build HTML
   -------------------------- */
$reportDate = date("F d, Y");
$logoPath = __DIR__ . "/mscookies1.png";
$logoBase64 = @file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';
$logoSrc = $logoBase64 ? 'data:image/png;base64,' . $logoBase64 : '';

$html = '<html><head><meta charset="utf-8"><style>
@page { margin: 100px 50px 80px 50px; }
body { font-family: Arial, sans-serif; font-size: 12px; color: #222; }
.header { position: fixed; top: -80px; left: 0; right: 0; height: 80px; border-bottom: 1px solid #ccc; display:flex; justify-content:space-between; align-items:center; padding: 0 10px; }
.header img { height: 60px; }
.header .date { font-size: 12px; color: #555; }
.footer { position: fixed; bottom: -60px; left: 0; right: 0; height: 50px; text-align: center; font-size: 10px; color: #555; }
h2 { text-align:center; margin-top:20px; }
h3 { margin-top:20px; }
table { border-collapse: collapse; width: 100%; margin-bottom: 5px; }
th, td { border: 1px solid #000; padding: 6px; text-align: left; }
th { background: #f98ca3; color: white; }
.null-cell { color: #777; font-style: italic; }
.summary { font-style: italic; margin-bottom: 20px; }
</style></head><body>';

$html .= '<div class="header"><div class="logo">';
if ($logoSrc) $html .= '<img src="'.$logoSrc.'" alt="Logo">';
$html .= '</div><div class="date">Report Date: '.$reportDate.'</div></div>';

$html .= '<div class="footer">Page <span class="pagenum"></span></div>';

$html .= '<h2>SALES REPORT SUMMARY (All Time)</h2>';
$html .= '<p>The system has recorded a total of Php'.number_format($totalSales,2).' in sales across '.number_format($totalOrders).' orders '.$timePeriodText.'.</p>';
$html .= '<p>On average, each order contributes Php'.number_format($avgSales,2).' in sales.</p>';
if ($topProduct) {
    $html .= "<p>The top-selling product ".$timePeriodText." is ".htmlspecialchars($topProduct['Product_Name'])." with ".$topProduct['qty']." units sold.</p>";
}
if ($peakPeriod) {
    $html .= "<p>The highest sales were recorded ".$timePeriodText." in ".$peakPeriod['period'].", reaching Php".number_format($peakPeriod['total'],2).".</p>";
}
$html .= "<p>Recent sales trends ".$timePeriodText." are shown below, including the top 3 products in the most recent transactions.</p>";

// Recent sales
$html .= '<h3>RECENT SALES (Last 10 Orders)</h3><table><tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (Php)</th></tr>';
foreach ($recentSales as $sale) {
    $html .= "<tr><td>{$sale['date']}</td><td>".htmlspecialchars($sale['Customer_Name'])."</td><td>".htmlspecialchars($sale['Product_Name'])."</td><td>{$sale['Quantity']}</td><td>Php ".number_format($sale['Subtotal'],2)."</td></tr>";
}
$html .= '</table>';
$html .= '<div class="summary">Insight: This table shows the latest orders, useful for monitoring daily performance and customer demand patterns.</div>';

// Top recent products
$html .= '<h3>TOP 3 PRODUCTS (From Recent Sales)</h3><table><tr><th>Product</th><th>Quantity Sold</th></tr>';
foreach ($topRecentProducts as $prod) {
    $html .= "<tr><td>".htmlspecialchars($prod['Product_Name'])."</td><td>{$prod['qty']} pcs</td></tr>";
}
$html .= '</table>';
$html .= '<div class="summary">Insight: These products are the most frequently purchased in recent transactions, highlighting short-term customer preferences.</div>';

// Page break
$html .= '<div style="page-break-before:always;"></div>';

// Predictive analytics section
$html .= '<h2>PREDICTIVE ANALYTICS (Hybrid Forecast)</h2>';

// Metrics table
$html .= '<h3>Model Performance Metrics</h3><table><tr><th>MAE</th><th>RMSE</th><th>MAPE</th><th>Accuracy</th><th>R²</th></tr>';
$html .= '<tr>';
$html .= '<td>'.(isset($metrics['MAE']) ? number_format($metrics['MAE'],2) : '-') .'</td>';
$html .= '<td>'.(isset($metrics['RMSE']) ? number_format($metrics['RMSE'],2) : '-') .'</td>';
$html .= '<td>'.(isset($metrics['MAPE']) ? number_format($metrics['MAPE'],2). '%' : '-') .'</td>';
$html .= '<td>'.(isset($metrics['Accuracy']) ? number_format($metrics['Accuracy'],2). '%' : '-') .'</td>';
$html .= '<td>'.(isset($metrics['R2']) ? number_format($metrics['R2'],2) : '-') .'</td>';
$html .= '</tr></table>';
$html .= '<div class="summary">Insight: These metrics evaluate forecast performance. Lower MAE/RMSE means smaller errors, while higher Accuracy/R² indicates reliable predictions.</div>';

// Actual vs Hybrid table
$html .= '<h3>ACTUAL vs HYBRID FORECAST (Last 5 Months)</h3>';
if (!empty($actualVsHybrid)) {
    $html .= '<table><tr><th>Month</th><th>Actual Sales (Php)</th><th>Hybrid Forecast (Php)</th><th>% Error</th><th>Accuracy</th></tr>';
    foreach ($actualVsHybrid as $r) {
        $html .= '<tr>';
        $html .= '<td>'.htmlspecialchars($r['month']).'</td>';
        $html .= '<td>Php '.number_format($r['actual_sales'],2).'</td>';
        if ($r['hybrid_forecast'] === null) {
            $html .= '<td class="null-cell">n/a</td>';
            $html .= '<td class="null-cell">n/a</td>';
            $html .= '<td class="null-cell">n/a</td>';
        } else {
            $html .= '<td>Php '.number_format($r['hybrid_forecast'],2).'</td>';
            $html .= '<td>'.($r['percent_error'] === null ? 'n/a' : number_format($r['percent_error'],2).'%').'</td>';
            $html .= '<td>'.($r['accuracy'] === null ? 'n/a' : number_format($r['accuracy'],2).'%').'</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    $html .= '<div class="summary">Insight: This comparison shows how close the forecast is to actual results. Smaller errors mean better alignment with real sales.</div>';
} else {
    $html .= '<p>No hybrid forecast data available for the last 5 months.</p>';
}

// Future 12-month forecast
if (!empty($futureTableNormalized)) {
    $totalForecast = array_sum(array_column($futureTableNormalized,'forecast'));
    $html .= '<h3>12-Month Forecast</h3><table><tr><th>Month</th><th>Predicted Sales (Php)</th></tr>';
    foreach ($futureTableNormalized as $row) {
        $html .= '<tr><td>'.htmlspecialchars($row['month']).'</td><td>Php '.number_format($row['forecast'],2).'</td></tr>';
    }
    $html .= '</table>';
    $html .= '<div class="summary">Insight: Total projected revenue for the next 12 months is Php '.number_format($totalForecast,2).'. This helps guide financial planning and growth targets.</div>';
}

// Executive summary page
$html .= '<div style="page-break-before:always;"></div>';
$html .= '<h2>EXECUTIVE SUMMARY - ALL TIME</h2>';
$html .= '<p>The comprehensive analysis confirms overall sales performance with a total of <b>Php'.number_format($totalSales,2).'</b> across <b>'.number_format($totalOrders).' orders</b>. The average order value of Php'.number_format($avgSales,2).' indicates stable customer purchasing patterns.</p>';
$html .= '<p>Forecast evaluation shows reliable predictive power, with accuracy metrics suggesting the hybrid model closely aligns with actual results. Looking ahead, the projected Php '.number_format($totalForecast ?? 0,2).' in sales provides clear financial benchmarks for the next 12 months.</p>';
if ($topProduct) {
    $html .= '<p>Product-level insights reveal that <b>'.htmlspecialchars($topProduct['Product_Name']).'</b> is the leading item overall with '.$topProduct['qty'].' units sold, while recent orders confirm demand consistency.';
    if ($peakPeriod) {
        $html .= ' Peak sales occurred in '.$peakPeriod['period'].'.';
    }
    $html .= '</p>';
}
$html .= '<p><b>In summary:</b> MSC Cookies demonstrates strong market performance across all time periods, supported by accurate forecasts, high-demand products, and seasonal growth opportunities. This comprehensive report includes predictive analytics for strategic long-term planning.</p>';

$html .= '</body></html>';

/* --------------------------
   Generate PDF
   -------------------------- */
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Add page numbers
$canvas = $dompdf->get_canvas();
$font = $dompdf->getFontMetrics()->get_font('helvetica', 'normal');
$canvas->page_text(520, 820, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 10, [0,0,0]);

$dompdf->stream("sales_report_all_time.pdf", ["Attachment" => true]);

$conn->close();
exit;
?>
