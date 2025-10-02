<?php
require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * export_report_custom_full.php
 * - Export report for a specific full date (YYYY-MM-DD)
 * - Uses Python JSON output for hybrid forecasts (robust parsing)
 * - Gets actual sales for the specific date from DB
 * - Builds PDF via Dompdf
 */

// Get the custom date from URL parameter
$customDate = isset($_GET['dateValue']) ? $_GET['dateValue'] : date('Y-m-d');

// === DB Connection ===
session_start();
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
        $formattedDate = date('F j, Y', strtotime($customDate));
        $details = json_encode([
            'username' => $username,
            'file_type' => 'PDF Report',
            'file_name' => "sales_report_date_{$customDate}.pdf",
            'report_type' => "Custom Date Sales Report ({$formattedDate})",
            'custom_date' => $customDate,
            'download_time' => date('Y-m-d H:i:s')
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'download', ?, ?, ?, ?)");
        $description = "User '{$username}' downloaded {$formattedDate} Sales Report (PDF)";
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Download logging error: " . $e->getMessage());
    }
}

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
    // if already YYYY-MM
    if (preg_match('/^\d{4}-\d{2}$/', $s)) return $s;
    // if YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return substr($s, 0, 7);
    // try strtotime
    $ts = strtotime($s);
    if ($ts !== false) return date('Y-m', $ts);
    // fallback null
    return null;
}

/* --------------------------
   Descriptive analytics for specific date
   -------------------------- */
$whereClause = "WHERE DATE(Sales_Date) = '$customDate'";

// Totals
$totalSalesQuery = $conn->query("SELECT SUM(Subtotal) as totalSales, COUNT(DISTINCT Order_Code) as totalOrders FROM sales $whereClause");
$totals = $totalSalesQuery->fetch_assoc();
$totalSales = $totals['totalSales'] ?? 0;
$totalOrders = $totals['totalOrders'] ?? 0;
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

// Best-selling product for this date
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

// Peak sales hour for this date
$peakHourQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%H:00') as hour, SUM(Subtotal) as total
    FROM sales
    $whereClause
    GROUP BY hour
    ORDER BY total DESC
    LIMIT 1
");
$peakHour = $peakHourQuery->fetch_assoc();

// All sales for this date
$allSalesQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%b %d, %Y %H:%i') as date, Customer_Name, Subtotal, p.Product_Name, s.Quantity
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    ORDER BY s.Sales_Date DESC
");
$allSales = $allSalesQuery->fetch_all(MYSQLI_ASSOC);

// Top 3 products for this date
$topProductsQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 3
");
$topProducts = $topProductsQuery->fetch_all(MYSQLI_ASSOC);

/* --------------------------
   Predictive Analytics: run Python and decode JSON
   -------------------------- */
$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = __DIR__ . "\\arima_gradientondb.py";
$output = shell_exec("$python $script 2>&1");

// decode safely
$data = json_decode($output, true);
if ($data === null) {
    $data = [];
}

// safe extractions
$forecastData = $data['forecast'] ?? [];
$metrics = $data['metrics'] ?? [];
$futureTable = $data['future_table'] ?? $data['future_table'] ?? [];

/* --------------------------
   Build hybrid forecast map from Python output
   -------------------------- */
$hybridForecast = [];

// Try explicit hybrid_forecast key
if (!empty($data['hybrid_forecast']) && is_array($data['hybrid_forecast'])) {
    foreach ($data['hybrid_forecast'] as $item) {
        if (!is_array($item)) continue;
        $monthRaw = first_key_value($item, ['month','Month','DATE','date','Date','MONTH']);
        $valRaw   = first_key_value($item, ['forecast','prediction','predicted','Hybrid_Forecast','Hybrid','hybrid','value','Future_Forecast','Future_Forecast']);
        $month = normalize_month($monthRaw);
        if ($month !== null && $valRaw !== null && $valRaw !== '') {
            $hybridForecast[$month] = (float)$valRaw;
        }
    }
}

// Try scanning $data['forecast']
if (empty($hybridForecast) && !empty($data['forecast']) && is_array($data['forecast'])) {
    foreach ($data['forecast'] as $item) {
        if (!is_array($item)) continue;
        $monthRaw = first_key_value($item, ['DATE','date','Date','month','Month']);
        $valRaw = first_key_value($item, ['Hybrid_Forecast','hybrid','Hybrid','HybridForecast','Future_Forecast','Future_Forecast','forecast','prediction']);
        $month = normalize_month($monthRaw);
        if ($month !== null && $valRaw !== null && $valRaw !== '') {
            $hybridForecast[$month] = (float)$valRaw;
        }
    }
}

// Also consider $data['future_table']
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
   Get the hybrid forecast for this specific date's month
   -------------------------- */
$dateMonth = normalize_month($customDate);
$dateForecast = $hybridForecast[$dateMonth] ?? null;

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
$reportDate = date("F d, Y", strtotime($customDate));
$logoPath = __DIR__ . "/mscookies1.png";
$logoBase64 = @file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';
$logoSrc = $logoBase64 ? 'data:image/png;base64,' . $logoBase64 : '';

$html = '<html><head><meta charset="utf-8"><style>
@page { margin: 100px 50px 50px 50px; }
body { font-family: Arial, sans-serif; font-size: 12px; color: #222; }
.header { position: fixed; top: -80px; left: 0; right: 0; height: 80px; border-bottom: 1px solid #ccc; display:flex; justify-content:space-between; align-items:center; padding: 0 10px; }
.header img { height: 60px; }
.header .date { font-size: 12px; color: #555; }
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

$html .= '<h2>SALES REPORT FOR '.strtoupper($reportDate).'</h2>';
$html .= '<p>The system has recorded a total of Php'.number_format($totalSales,2).' in sales across '.number_format($totalOrders).' orders on this date.</p>';
$html .= '<p>On average, each order contributes Php'.number_format($avgSales,2).' in sales.</p>';
if ($topProduct) {
    $html .= "<p>The top-selling product on this date is ".htmlspecialchars($topProduct['Product_Name'])." with ".$topProduct['qty']." units sold.</p>";
}
if ($peakHour) {
    $html .= "<p>The highest sales were recorded at ".$peakHour['hour'].", reaching Php".number_format($peakHour['total'],2).".</p>";
}

// All sales for this date
$html .= '<h3>ALL SALES FOR THIS DATE</h3>';
if (!empty($allSales)) {
    $html .= '<table><tr><th>Date & Time</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (Php)</th></tr>';
    foreach ($allSales as $sale) {
        $html .= "<tr><td>{$sale['date']}</td><td>".htmlspecialchars($sale['Customer_Name'])."</td><td>".htmlspecialchars($sale['Product_Name'])."</td><td>{$sale['Quantity']}</td><td>Php ".number_format($sale['Subtotal'],2)."</td></tr>";
    }
    $html .= '</table>';
    $html .= '<div class="summary">Insight: This table shows all orders for the selected date, useful for detailed daily analysis.</div>';
} else {
    $html .= '<p>No sales recorded for this date.</p>';
}

// Top products for this date
if (!empty($topProducts)) {
    $html .= '<h3>TOP 3 PRODUCTS FOR THIS DATE</h3><table><tr><th>Product</th><th>Quantity Sold</th></tr>';
    foreach ($topProducts as $prod) {
        $html .= "<tr><td>".htmlspecialchars($prod['Product_Name'])."</td><td>{$prod['qty']} pcs</td></tr>";
    }
    $html .= '</table>';
    $html .= '<div class="summary">Insight: These products were the most popular on this specific date.</div>';
}

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

// Show forecast for this month if available
if ($dateForecast !== null) {
    $html .= '<h3>FORECAST FOR '.strtoupper($dateMonth).'</h3>';
    $html .= '<table><tr><th>Month</th><th>Forecasted Sales (Php)</th><th>Actual Sales on '.$reportDate.' (Php)</th></tr>';
    $html .= '<tr><td>'.$dateMonth.'</td><td>Php '.number_format($dateForecast,2).'</td><td>Php '.number_format($totalSales,2).'</td></tr>';
    $html .= '</table>';
    $html .= '<div class="summary">Insight: This shows the monthly forecast compared to actual sales on the selected date.</div>';
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
$html .= '<h2>EXECUTIVE SUMMARY FOR '.strtoupper($reportDate).'</h2>';
$html .= '<p>On '.date('F d, Y', strtotime($customDate)).', MSC Cookies recorded Php'.number_format($totalSales,2).' in total sales across '.number_format($totalOrders).' orders. The average order value of Php'.number_format($avgSales,2).' indicates ';
$html .= ($avgSales > 0 ? 'positive' : 'no').' customer purchasing activity on this date.</p>';

if ($topProduct) {
    $html .= '<p>The leading product for this date was <b>'.htmlspecialchars($topProduct['Product_Name']).'</b> with '.$topProduct['qty'].' units sold, demonstrating clear customer preference.</p>';
}

if ($peakHour) {
    $html .= '<p>Peak sales occurred at '.$peakHour['hour'].', suggesting optimal times for promotional activities or staff allocation.</p>';
}

$html .= '<p>The hybrid forecast model continues to provide reliable predictions, with accuracy metrics supporting data-driven business decisions.</p>';
$html .= '<p><b>In summary:</b> This date-specific analysis provides granular insights into daily performance, customer behavior, and sales patterns for MSC Cookies.</p>';

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
$dompdf->stream("sales_report_".str_replace('-', '_', $customDate).".pdf", ["Attachment" => true]);

$conn->close();
exit;
?>
