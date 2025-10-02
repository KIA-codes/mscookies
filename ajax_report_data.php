<?php
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = new mysqli("localhost", "root", "", "mscookies");

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

// Check if the user is admin
$user_id = $_SESSION['user_id'];
$userResult = $conn->query("SELECT UserType FROM user WHERE User_ID = $user_id");
if (!$userResult) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed: ' . $conn->error]);
    exit;
}
$userRow = $userResult->fetch_assoc();
if (!$userRow || $userRow['UserType'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Get time period filter
$timeFilter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$customDate = isset($_GET['custom_date']) ? $_GET['custom_date'] : '';
$dateType = isset($_GET['date_type']) ? $_GET['date_type'] : '';

// Build WHERE clause based on time filter
$whereClause = "";
switch($timeFilter) {
    case 'today':
        $whereClause = "WHERE DATE(Sales_Date) = CURDATE()";
        break;
    case 'week':
        $whereClause = "WHERE YEARWEEK(Sales_Date) = YEARWEEK(CURDATE())";
        break;
    case 'month':
        $whereClause = "WHERE YEAR(Sales_Date) = YEAR(CURDATE()) AND MONTH(Sales_Date) = MONTH(CURDATE())";
        break;
    case 'year':
        $whereClause = "WHERE YEAR(Sales_Date) = YEAR(CURDATE())";
        break;
    case 'custom':
        if (!empty($customDate)) {
            switch($dateType) {
                case 'full':
                    // Full date: YYYY-MM-DD
                    $whereClause = "WHERE DATE(Sales_Date) = '$customDate'";
                    break;
                case 'month':
                    // Month and year: YYYY-MM
                    $whereClause = "WHERE YEAR(Sales_Date) = " . substr($customDate, 0, 4) . " AND MONTH(Sales_Date) = " . substr($customDate, 5, 2);
                    break;
                case 'year':
                    // Year only: YYYY
                    $whereClause = "WHERE YEAR(Sales_Date) = '$customDate'";
                    break;
                default:
                    // Default to full date for backward compatibility
                    $whereClause = "WHERE DATE(Sales_Date) = '$customDate'";
            }
        }
        break;
    default:
        $whereClause = "";
}

// Totals
$totalSalesQuery = $conn->query("
    SELECT SUM(Subtotal) as totalSales, COUNT(DISTINCT Order_Code) as totalOrders 
    FROM sales $whereClause
");
if (!$totalSalesQuery) {
    http_response_code(500);
    echo json_encode(['error' => 'Total sales query failed: ' . $conn->error]);
    exit;
}
$totals = $totalSalesQuery->fetch_assoc();
$totalSales = $totals['totalSales'] ?? 0;
$totalOrders = $totals['totalOrders'] ?? 0;

// Average sales per order
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

// Best-selling product (filtered)
$topProductQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 1
");
if (!$topProductQuery) {
    http_response_code(500);
    echo json_encode(['error' => 'Top product query failed: ' . $conn->error]);
    exit;
}
$topProduct = $topProductQuery->fetch_assoc();

// Peak sales period (filtered)
$peakQuery = "";
switch($timeFilter) {
    case 'today':
        $peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%H:00') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
        break;
    case 'week':
        $peakQuery = "SELECT DATE_FORMAT(Sales_Date, '%W') as period, SUM(Subtotal) as total FROM sales $whereClause GROUP BY period ORDER BY total DESC LIMIT 1";
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
$peakResult = $conn->query($peakQuery);
if (!$peakResult) {
    http_response_code(500);
    echo json_encode(['error' => 'Peak period query failed: ' . $conn->error]);
    exit;
}
$peakPeriod = $peakResult->fetch_assoc();

// All sales (filtered) - for scrollable table
$allSalesQuery = $conn->query("
    SELECT DATE_FORMAT(s.Sales_Date, '%b %d, %Y') as date, 
           s.Customer_Name, 
           s.Subtotal, 
           p.Product_Name, 
           s.Quantity
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    ORDER BY s.Sales_Date DESC
");
if (!$allSalesQuery) {
    http_response_code(500);
    echo json_encode(['error' => 'All sales query failed: ' . $conn->error]);
    exit;
}
$allSales = $allSalesQuery->fetch_all(MYSQLI_ASSOC);

// Top products (filtered)
$topProductsQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    $whereClause
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 3
");
if (!$topProductsQuery) {
    http_response_code(500);
    echo json_encode(['error' => 'Top products query failed: ' . $conn->error]);
    exit;
}
$topProducts = $topProductsQuery->fetch_all(MYSQLI_ASSOC);

// Build report sentences
$timePeriodText = "";
switch($timeFilter) {
    case 'today':
        $timePeriodText = "today";
        break;
    case 'week':
        $timePeriodText = "this week";
        break;
    case 'month':
        $timePeriodText = "this month";
        break;
    case 'year':
        $timePeriodText = "this year";
        break;
    case 'custom':
        if (!empty($customDate)) {
            switch($dateType) {
                case 'full':
                    $timePeriodText = "on " . date('M d, Y', strtotime($customDate));
                    break;
                case 'month':
                    $timePeriodText = "in " . date('F Y', strtotime($customDate . '-01'));
                    break;
                case 'year':
                    $timePeriodText = "in " . $customDate;
                    break;
                default:
                    $timePeriodText = "on " . date('M d, Y', strtotime($customDate));
            }
        } else {
            $timePeriodText = "for the selected period";
        }
        break;
    default:
        $timePeriodText = "overall";
}

$report = [];
$report[] = "The system has recorded a total of ₱" . number_format($totalSales, 2) . " in sales across " . number_format($totalOrders) . " orders " . $timePeriodText . ".";
$report[] = "On average, each order contributes ₱" . number_format($avgSales, 2) . " in sales.";
if ($topProduct) {
    $report[] = "The top-selling product " . $timePeriodText . " is " . htmlspecialchars($topProduct['Product_Name']) . " with " . $topProduct['qty'] . " units sold.";
}
if ($peakPeriod) {
    $report[] = "The highest sales were recorded " . $timePeriodText . " at " . $peakPeriod['period'] . ", reaching ₱" . number_format($peakPeriod['total'], 2) . ".";
}
$report[] = "Sales trends " . $timePeriodText . " are shown below, including the top 3 products.";

// Return JSON response
header('Content-Type: application/json');
try {
    $response = [
        'report' => $report,
        'allSales' => $allSales,
        'topProducts' => $topProducts,
        'totalSales' => $totalSales,
        'totalOrders' => $totalOrders,
        'avgSales' => $avgSales,
        'timePeriodText' => $timePeriodText
    ];
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON encoding failed: ' . $e->getMessage()]);
}
?>
