<?php
session_start();
require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * export_report_week.php
 * Export THIS WEEK's sales report
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
            'file_name' => 'sales_report_week.pdf',
            'report_type' => "Weekly Sales Report",
            'download_time' => date('Y-m-d H:i:s')
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'download', ?, ?, ?, ?)");
        $description = "User '{$username}' downloaded Weekly Sales Report (PDF)";
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Download logging error: " . $e->getMessage());
    }
}

// Time filter: THIS WEEK
$whereClause = "WHERE YEARWEEK(Sales_Date) = YEARWEEK(CURDATE())";
$timePeriodText = "this week";

/* Helper functions */
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

// Peak sales day
$peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%W') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
$peakPeriodResult = $conn->query($peakQuery);
$peakPeriod = $peakPeriodResult ? $peakPeriodResult->fetch_assoc() : null;

// Recent sales
$recentSalesQuery = $conn->query("
    SELECT DATE_FORMAT(s.Sales_Date, '%b %d, %Y') as date, s.Customer_Name, s.Subtotal, p.Product_Name, s.Quantity
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    ORDER BY s.Sales_Date DESC
    LIMIT 10
");
$recentSales = $recentSalesQuery->fetch_all(MYSQLI_ASSOC);

// Top 3 products
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

// Run Python for predictive analytics
$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = __DIR__ . "\\arima_gradientondb.py";
$output = shell_exec("$python $script 2>&1");
$data = json_decode($output, true);
if ($data === null) $data = [];

$forecastData = $data['forecast'] ?? [];
$metrics = $data['metrics'] ?? [];
$futureTable = $data['future_table'] ?? [];

// Build hybrid forecast map (same logic as all.php)
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

// Get actual sales (last 5 months)
$actualSales = [];
$actualQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%Y-%m') AS month, SUM(Subtotal) AS actual_sales
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

// Merge actual vs hybrid
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
usort($actualVsHybrid, function($a,$b){ return strcmp($a['month'],$b['month']); });

// Future forecast table
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
}

// Build HTML
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

$html .= '<h2>SALES REPORT SUMMARY (This Week)</h2>';
$html .= '<p>The system has recorded a total of Php'.number_format($totalSales,2).' in sales across '.number_format($totalOrders).' orders '.$timePeriodText.'.</p>';
$html .= '<p>On average, each order contributes Php'.number_format($avgSales,2).' in sales.</p>';
if ($topProduct) {
    $html .= "<p>The top-selling product ".$timePeriodText." is ".htmlspecialchars($topProduct['Product_Name'])." with ".$topProduct['qty']." units sold.</p>";
}
if ($peakPeriod) {
    $html .= "<p>The highest sales were recorded ".$timePeriodText." on ".$peakPeriod['period'].", reaching Php".number_format($peakPeriod['total'],2).".</p>";
}
$html .= "<p>Sales trends ".$timePeriodText." are shown below.</p>";

$html .= '<h3>SALES THIS WEEK (Last 10 Orders)</h3><table><tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (Php)</th></tr>';
foreach ($recentSales as $sale) {
    $html .= "<tr><td>{$sale['date']}</td><td>".htmlspecialchars($sale['Customer_Name'])."</td><td>".htmlspecialchars($sale['Product_Name'])."</td><td>{$sale['Quantity']}</td><td>Php ".number_format($sale['Subtotal'],2)."</td></tr>";
}
$html .= '</table>';
$html .= '<div class="summary">Insight: This table shows this week\'s orders.</div>';

$html .= '<h3>TOP 3 PRODUCTS (This Week)</h3><table><tr><th>Product</th><th>Quantity Sold</th></tr>';
foreach ($topRecentProducts as $prod) {
    $html .= "<tr><td>".htmlspecialchars($prod['Product_Name'])."</td><td>{$prod['qty']} pcs</td></tr>";
}
$html .= '</table>';
$html .= '<div class="summary">Insight: These products are the most popular this week.</div>';

$html .= '<div style="page-break-before:always;"></div>';
$html .= '<h2>EXECUTIVE SUMMARY - THIS WEEK</h2>';
$html .= '<p>This week\'s sales performance shows a total of <b>Php'.number_format($totalSales,2).'</b> across <b>'.number_format($totalOrders).' orders</b>.</p>';
$html .= '<p>The average order value of Php'.number_format($avgSales,2).' reflects weekly customer purchasing patterns.</p>';
if ($topProduct) {
    $html .= '<p><b>'.htmlspecialchars($topProduct['Product_Name']).'</b> is the leading item this week with '.$topProduct['qty'].' units sold.';
    if ($peakPeriod) {
        $html .= ' Peak sales occurred on '.$peakPeriod['period'].'.';
    }
    $html .= '</p>';
}
$html .= '<p><b>Key Insight:</b> This report focuses exclusively on this week\'s sales activity, providing weekly performance trends for tactical business decisions.</p>';

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->get_canvas();
$font = $dompdf->getFontMetrics()->get_font('helvetica', 'normal');
$canvas->page_text(520, 820, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 10, [0,0,0]);

$dompdf->stream("sales_report_this_week.pdf", ["Attachment" => true]);

$conn->close();
exit;
?>
