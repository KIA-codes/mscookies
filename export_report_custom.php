<?php
require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * export_report_custom.php
 * Export CUSTOM DATE sales report
 * Accepts: custom_date and date_type parameters
 */

$conn = new mysqli("localhost", "root", "", "mscookies");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Get custom date parameters
$customDate = isset($_GET['custom_date']) ? $_GET['custom_date'] : '';
$dateType = isset($_GET['date_type']) ? $_GET['date_type'] : 'full';

// Build WHERE clause based on date type
$whereClause = "";
$timePeriodText = "for the selected period";

if (!empty($customDate)) {
    switch ($dateType) {
        case 'full':
            // Full date: YYYY-MM-DD
            $whereClause = "WHERE DATE(Sales_Date) = '" . $conn->real_escape_string($customDate) . "'";
            $timePeriodText = "on " . date('M d, Y', strtotime($customDate));
            break;
        case 'month':
            // Month and year: YYYY-MM
            $y = substr($customDate, 0, 4);
            $m = substr($customDate, 5, 2);
            $whereClause = "WHERE YEAR(Sales_Date) = " . intval($y) . " AND MONTH(Sales_Date) = " . intval($m);
            $timePeriodText = "in " . date('F Y', strtotime($customDate . '-01'));
            break;
        case 'year':
            // Year only: YYYY
            $whereClause = "WHERE YEAR(Sales_Date) = " . intval($customDate);
            $timePeriodText = "in " . $customDate;
            break;
        default:
            $whereClause = "WHERE DATE(Sales_Date) = '" . $conn->real_escape_string($customDate) . "'";
            $timePeriodText = "on " . date('M d, Y', strtotime($customDate));
    }
}

function first_key_value(array $arr, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k];
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

$totalSalesQuery = $conn->query("SELECT SUM(Subtotal) as totalSales, COUNT(DISTINCT Order_Code) as totalOrders FROM sales $whereClause");
$totals = $totalSalesQuery->fetch_assoc();
$totalSales = $totals['totalSales'] ?? 0;
$totalOrders = $totals['totalOrders'] ?? 0;
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

$topProductQuery = $conn->query("SELECT p.Product_Name, SUM(s.Quantity) as qty FROM sales s JOIN product p ON s.Product_ID = p.Product_ID $whereClause GROUP BY p.Product_Name ORDER BY qty DESC LIMIT 1");
$topProduct = $topProductQuery->fetch_assoc();

// Peak period based on date type
$peakQuery = "";
switch ($dateType) {
    case 'full':
        $peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%H:00') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
        break;
    case 'month':
        $peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%d') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
        break;
    case 'year':
        $peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%M') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
        break;
    default:
        $peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%Y-%M') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
}
$peakPeriodResult = $conn->query($peakQuery);
$peakPeriod = $peakPeriodResult ? $peakPeriodResult->fetch_assoc() : null;

$recentSalesQuery = $conn->query("SELECT DATE_FORMAT(s.Sales_Date, '%b %d, %Y') as date, s.Customer_Name, s.Subtotal, p.Product_Name, s.Quantity FROM sales s JOIN product p ON s.Product_ID = p.Product_ID $whereClause ORDER BY s.Sales_Date DESC LIMIT 10");
$recentSales = $recentSalesQuery->fetch_all(MYSQLI_ASSOC);

$topRecentProductsQuery = $conn->query("SELECT p.Product_Name, SUM(s.Quantity) as qty FROM (SELECT * FROM sales $whereClause ORDER BY Sales_Date DESC LIMIT 10) s JOIN product p ON s.Product_ID = p.Product_ID GROUP BY p.Product_Name ORDER BY qty DESC LIMIT 3");
$topRecentProducts = $topRecentProductsQuery->fetch_all(MYSQLI_ASSOC);

$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = __DIR__ . "\\arima_gradientondb.py";
$output = shell_exec("$python $script 2>&1");
$data = json_decode($output, true);
if ($data === null) $data = [];

$metrics = $data['metrics'] ?? [];
$futureTable = $data['future_table'] ?? [];

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
.summary { font-style: italic; margin-bottom: 20px; }
</style></head><body>';

$html .= '<div class="header"><div class="logo">';
if ($logoSrc) $html .= '<img src="'.$logoSrc.'" alt="Logo">';
$html .= '</div><div class="date">Report Date: '.$reportDate.'</div></div>';

$html .= '<h2>SALES REPORT SUMMARY (Custom Period)</h2>';
$html .= '<p>The system has recorded a total of Php'.number_format($totalSales,2).' in sales across '.number_format($totalOrders).' orders '.$timePeriodText.'.</p>';
$html .= '<p>On average, each order contributes Php'.number_format($avgSales,2).' in sales.</p>';
if ($topProduct) {
    $html .= "<p>The top-selling product ".$timePeriodText." is ".htmlspecialchars($topProduct['Product_Name'])." with ".$topProduct['qty']." units sold.</p>";
}
if ($peakPeriod) {
    $html .= "<p>The highest sales were recorded ".$timePeriodText." at ".$peakPeriod['period'].", reaching Php".number_format($peakPeriod['total'],2).".</p>";
}

$html .= '<h3>SALES (Last 10 Orders)</h3><table><tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (Php)</th></tr>';
foreach ($recentSales as $sale) {
    $html .= "<tr><td>{$sale['date']}</td><td>".htmlspecialchars($sale['Customer_Name'])."</td><td>".htmlspecialchars($sale['Product_Name'])."</td><td>{$sale['Quantity']}</td><td>Php ".number_format($sale['Subtotal'],2)."</td></tr>";
}
$html .= '</table>';

$html .= '<h3>TOP 3 PRODUCTS</h3><table><tr><th>Product</th><th>Quantity Sold</th></tr>';
foreach ($topRecentProducts as $prod) {
    $html .= "<tr><td>".htmlspecialchars($prod['Product_Name'])."</td><td>{$prod['qty']} pcs</td></tr>";
}
$html .= '</table>';

$html .= '<div style="page-break-before:always;"></div>';
$html .= '<h2>EXECUTIVE SUMMARY - CUSTOM PERIOD</h2>';
$html .= '<p>Sales performance '.$timePeriodText.' shows a total of <b>Php'.number_format($totalSales,2).'</b> across <b>'.number_format($totalOrders).' orders</b>.</p>';
$html .= '<p>The average order value of Php'.number_format($avgSales,2).' reflects customer purchasing patterns for this period.</p>';
if ($topProduct) {
    $html .= '<p><b>'.htmlspecialchars($topProduct['Product_Name']).'</b> is the leading item for this period with '.$topProduct['qty'].' units sold.';
    if ($peakPeriod) {
        $html .= ' Peak sales occurred at '.$peakPeriod['period'].'.';
    }
    $html .= '</p>';
}
$html .= '<p><b>Key Insight:</b> This report focuses exclusively on the selected custom period ('.$timePeriodText.'), providing targeted performance metrics for specific date range analysis.</p>';

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

$dompdf->stream("sales_report_custom.pdf", ["Attachment" => true]);

$conn->close();
exit;
?>
