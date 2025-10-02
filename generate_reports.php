<?php


session_start();


if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
// Check if the user is staff
$user_id = $_SESSION['user_id'];
$userResult = $conn->query("SELECT UserType FROM user WHERE User_ID = $user_id");
$userRow = $userResult ? $userResult->fetch_assoc() : null;
if (!$userRow || $userRow['UserType'] !== 'admin') {
    header('Location: index.php');
    exit;
}
$python = '"C:\\Program Files\\Python310\\python.exe"';
$script = "C:\\xampp\\htdocs\\roben\\arima_gradientondb.py";

$output = shell_exec("$python $script 2>&1");
$data = json_decode($output, true);

   echo "<script>var forecastData = " . json_encode($data['forecast']) . ";
  var metricsData = " . json_encode($data['metrics']) . ";</script>";
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


$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM login_tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
// Basic stats
$userProfileQuery = $conn->prepare("SELECT Profile_Picture FROM user WHERE User_ID = ?");
$userProfileQuery->bind_param("i", $user_id);
$userProfileQuery->execute();
$userProfileResult = $userProfileQuery->get_result();
$userProfile = $userProfileResult->fetch_assoc();
$userProfileQuery->close();

// Set profile picture path - use user's profile picture if available, otherwise use default
$profilePicture = $userProfile['Profile_Picture'] ? $userProfile['Profile_Picture'] : 'newlogo.png';

$stats = $conn->query("
    SELECT 
        COUNT(DISTINCT Order_Code) AS total_orders,
        SUM(Quantity) AS total_items,
        SUM(Subtotal) AS total_sales 
    FROM sales
")->fetch_assoc();

// Top products (overall)
$top_products = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) AS total 
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    GROUP BY p.Product_Name 
    ORDER BY total DESC 
    LIMIT 3
");

// Monthly totals
$monthly = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%Y-%m') AS month, SUM(Subtotal) AS total 
    FROM sales 
    GROUP BY month 
    ORDER BY month
");

// All sales (for main dashboard - always show all, not filtered)
$recent = $conn->query("
    SELECT s.Sales_Date, s.Customer_Name, p.Product_Name, s.Quantity, s.Subtotal 
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    ORDER BY s.Sales_Date DESC
");

// === CHART DATA ===
$chart_labels = [];
$chart_values = [];
while ($row = $monthly->fetch_assoc()) {
    $chart_labels[] = $row['month'];
    $chart_values[] = $row['total'];
}

// === TOP PRODUCTS FILTER (BY MONTH) ===
$monthsQuery = "SELECT DISTINCT DATE_FORMAT(Sales_Date, '%Y-%m') as month FROM sales ORDER BY month";
$monthsResult = $conn->query($monthsQuery);

$selectedMonth = isset($_GET['month']) ? $_GET['month'] : "all";

if ($selectedMonth === "all") {
    $sql = "SELECT p.Product_Name, SUM(s.Quantity) AS total_quantity
            FROM sales s 
            JOIN product p ON s.Product_ID = p.Product_ID
            GROUP BY p.Product_Name 
            ORDER BY total_quantity DESC 
            LIMIT 5";
} else {
    $sql = "SELECT p.Product_Name, SUM(s.Quantity) AS total_quantity
            FROM sales s 
            JOIN product p ON s.Product_ID = p.Product_ID
            WHERE DATE_FORMAT(s.Sales_Date, '%Y-%m') = '$selectedMonth'
            GROUP BY p.Product_Name 
            ORDER BY total_quantity DESC 
            LIMIT 5";
}
$result = $conn->query($sql);

$labels = [];
$values = [];
while ($row = $result->fetch_assoc()) {
    $labels[] = $row['Product_Name'];
    $values[] = $row['total_quantity'];
}

// === LINEAR REGRESSION DATA ===
$sql = "SELECT s.Quantity, s.Subtotal, s.Unit_Price 
        FROM sales s
        JOIN product p ON s.Product_ID = p.Product_ID";
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

// === REPORT DATA ===
// Get time period filter
$timeFilter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$customDate = isset($_GET['custom_date']) ? $_GET['custom_date'] : '';

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
            $whereClause = "WHERE DATE(Sales_Date) = '$customDate'";
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
$peakPeriod = $peakResult ? $peakResult->fetch_assoc() : null;

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
$topProducts = $topProductsQuery->fetch_all(MYSQLI_ASSOC);

// === BUILD REPORT SENTENCES ===
$report = [];
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
        $timePeriodText = "on " . date('M d, Y', strtotime($customDate));
        break;
    default:
        $timePeriodText = "overall";
}

$report[] = "The system has recorded a total of â‚±" . number_format($totalSales, 2) . " in sales across " . number_format($totalOrders) . " orders " . $timePeriodText . ".";
$report[] = "On average, each order contributes â‚±" . number_format($avgSales, 2) . " in sales.";
if ($topProduct) {
    $report[] = "The top-selling product " . $timePeriodText . " is " . htmlspecialchars($topProduct['Product_Name']) . " with " . $topProduct['qty'] . " units sold.";
}
if ($peakPeriod) {
    $report[] = "The highest sales were recorded " . $timePeriodText . " at " . $peakPeriod['period'] . ", reaching â‚±" . number_format($peakPeriod['total'], 2) . ".";
}
$report[] = "Sales trends " . $timePeriodText . " are shown below, including the top 3 products.";





$stmt = $conn->prepare("SELECT * FROM user WHERE User_ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
// Handle profile update
$update_msg = '';
$error_msg = '';
$message = ''; 
if (isset($_POST['save'])) {
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $email = $_POST['email'];
    $username = $_POST['username'];
    $profilePic = $user['Profile_Picture'];
    // Check for unique username and email (exclude current user)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM user WHERE (Username = ? OR Email = ?) AND User_ID != ?");
    $stmt->bind_param("ssi", $username, $email, $user_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    if ($count > 0) {
        // Check which one is duplicate
        $stmt = $conn->prepare("SELECT Username, Email FROM user WHERE (Username = ? OR Email = ?) AND User_ID != ? LIMIT 1");
        $stmt->bind_param("ssi", $username, $email, $user_id);
        $stmt->execute();
        $stmt->bind_result($dupUsername, $dupEmail);
        $stmt->fetch();
        $stmt->close();
        if ($dupUsername === $username) {
            $error_msg = 'Username is already taken!';
            $message =" <script>
            alert('Username is already taken!');
            
        </script>";
        } elseif ($dupEmail === $email) {
            $error_msg = 'Email is already registered!';
            $message =" <script>
            alert('Email is already registered!');
           
        </script>";
        } else {
            $error_msg = 'Username or email already exists!';
            $message =" <script>
            alert('Username or email already exists!');
           
        </script>";
        }
    } else {
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $imgstaffDir = 'imgstaff';
            if (!is_dir($imgstaffDir)) {
                mkdir($imgstaffDir, 0777, true);
            }
            $tmpName = $_FILES['profile_pic']['tmp_name'];
            $imgName = uniqid('staff_') . '_' . basename($_FILES['profile_pic']['name']);
            move_uploaded_file($tmpName, "$imgstaffDir/$imgName");
            $profilePic = "$imgstaffDir/$imgName";
        }
        $stmt = $conn->prepare("UPDATE user SET FName=?, LName=?, Email=?, Username=?, Profile_Picture=? WHERE User_ID=?");
        $stmt->bind_param("sssssi", $fname, $lname, $email, $username, $profilePic, $user_id);
        $stmt->execute();
        $stmt->close();
        $message =" <script>
            alert('Profile updated successfully');
            window.location.href = 'descriptive_dashboard.php';
        </script>";
        // Refresh user data
        $stmt = $conn->prepare("SELECT * FROM user WHERE User_ID = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
  <title>Descriptive Analytics - MSC Cookies</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <style>
    :root {
       --sidebar-bg: #ff7e94;
            --sidebar-active: #fff;
            --sidebar-icon: #fff;
            --sidebar-hover: #ffb3c1;
            --main-bg: #f2cbcc;
            --card-bg: #ee8488;
            --order-bg: #ee8488;
            --order-header: #ee8488;
            --primary: #ec3462;
            --text-dark: #222;
            --text-light: #fff;
    }
      .logout-btn {
            width: 40px;
            height: 40px;
            background: #ffb3c1;
            color: #ec3462;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            margin-top: 24px;
            font-size: 20px;
            transition: background 0.2s;
        }
        .logout-btn:hover {
            background: #ec3462;
            color: #fff;
        }
    body, html {
      margin: 0;
      padding: 0;
      height: 100%;
      background: var(--main-bg);
      font-family: Arial, sans-serif;
     
    }
     body {
            min-height: 100vh;
            background: var(--main-bg);
            font-family: 'Arial', sans-serif;
 overflow-x: hidden; 
        }
          .dashboard {
            display: flex;
            min-height: 100vh;
            position: relative;
        }
        .sidebar {
            width: 80px;
            height: 95vh;
            background: var(--sidebar-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 24px 0 16px 0;
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: width 0.3s ease;
            overflow: hidden;
        }
        .sidebar:hover {
            width: 250px;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        .sidebar .logo {
            width: 56px;
            height: 56px;
            margin-bottom: 32px;
            border-radius: 50%;
            background: #F98CA3;
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
            margin-top:50px;
            margin-bottom: 50px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            align-items: stretch;
            width: 100%;
            padding: 0 8px;
        }
        .nav-icon {
            width: 100%;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            color: #fff;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            padding: 0 16px;
            margin: 0 4px;
        }
        .nav-icon-content {
            display: flex;
            align-items: center;
            width: 100%;
        }
        .nav-icon svg {
            min-width: 24px;
            width: 24px;
            height: 24px;
            flex-shrink: 0;
        }
        .nav-text {
            margin-left: 16px;
            font-size: 16px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            transform: translateX(-10px);
            transition: all 0.3s ease;
        }
        .sidebar:hover .nav-text {
            opacity: 1;
            transform: translateX(0);
        }
        .nav-icon.active, .nav-icon:hover {
            background: #fff;
            color: #ec3462;
        }
    .main-content {
      flex: 1;
      padding: 40px 32px;
      margin-left: 80px;
      background: var(--main-bg);
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: flex-start;
    }
    .content-container {
      background: #fff;
      border-radius: 12px;
      padding: 32px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.08);
      margin-bottom: 24px;
      max-width: 1000px;
      width: 100%;
    }
    .sales-section {
      margin-bottom: 32px;
    }
    .card {
      
      width: 300px;
      background: #ffe3e3;
  padding: 10px;
      margin: 15px;
      border-radius: 8px;
      text-align: center;
    }
    canvas {
      max-width: 800px;
      display: block;
      margin: 40px auto;
      margin-top: 10px;
      background: white;
      border-radius: 8px;
      padding: 20px;
    }
    table {
      width: 100%;
      margin: 20px auto;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      overflow: hidden;
      border-collapse: collapse;
    }
    
    .table-container {
      max-height: 400px;
      overflow-y: auto;
      border: 1px solid #ddd;
      border-radius: 6px;
      margin: 20px auto;
    }
    
    .table-container table {
      margin: 0;
      border-collapse: collapse;
    }
    
    .table-container th,
    .table-container td {
      padding: 8px 12px;
      border: 1px solid #ddd;
      text-align: left;
    }
    
    .table-container th {
      background: #f5f5f5;
      position: sticky;
      top: 0;
      z-index: 1;
    }
    th, td {
      padding: 12px;
      border: 1px solid #eee;
      text-align: center;
    }
    th {
      background: var(--sidebar-bg);
      color: white;
      font-weight: bold;
    }
    td {
      background-color: white;
      color: var(--text-dark);
      font-size: 15px;
    }
    tr:nth-child(even) {
      background: #fff6fa;
    }
    .dropdown {
      position: relative;
    }
    .dropdown-content {
      display: none;
      position: absolute;
      top: 36px;
      left: -60px;
      background: white;
      border-radius: 6px;
      overflow: hidden;
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
      font-size: 14px;
    }
    .dropdown-content button {
      all: unset;
      display: block;
      padding: 10px 16px;
      width: 100%;
      cursor: pointer;
      color: black;
    }
    .dropdown:hover .dropdown-content {
      display: block;
    }
    .chart{
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 5px 1px;

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
    .top-bar {
  position: fixed;
  top: 10px;
  right: 20px;
}

#overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s ease;
  z-index: 1000;
}

#overlay.active {
  opacity: 1;
  pointer-events: all;
}
.logooo-menu {
  position: relative;
  width: 60px;
  height: 60px; 
  z-index: 999;
}

.logooo {
  width: 60px;
  height: 60px;
  cursor: pointer;
  border-radius: 50%;
  background: #ffffffff;
  border: 2px solid transparent;
  box-shadow: 0 2px 8px rgba(0,0,0,0.2);
  transition: transform 0.3s ease;
  

 
}

.logooo:hover {
  transform: scale(1.1);
}

.option-btn {
  position: absolute;
  top:30px;
  right:7px;
  width: 45px;
  height: 45px;
  border-radius: 50%;
  background: #fff0f1ff;
  margin-right: 10px;
  color: #ec3462;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  transform: translateY(-50%) scale(0);
  opacity: 0;
  transition: transform 0.5s ease;  
  z-index: 999;
}

  .option-btn.show {
    opacity: 1;
  }

.option-btn:hover {
  transition: 0.5s;
  background: #ec3462;
  color:white;
}


.logooo-menu.active .option-btn {
  opacity: 1;
  scale: 1;
}
    /* Tab bar */
    .tab-bar {
        display: flex;
        align-items: center;
        background: #FF7E94;
        padding: 5px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        border-bottom: 1px solid #ccc;
    }

    .tab {
     background: #FF7E94;
        padding: 8px 20px;
        margin-right: 4px;
        border-radius: 8px 8px 0 0;
        cursor: pointer;
        display: flex;
        align-items: center;
        transition: background 0.25s ease, transform 0.2s ease;
    }

    .tab.active {
        background: #ffcccdff;
        border-bottom: 2px solid #ffcccdff;
        box-shadow: 0 -1px 5px rgba(0,0,0,0.1);
    }

    .tab:hover {
        background: #EC3462;
        transform: translateY(-1px);
          border-bottom: 2px solid #EC3462;
    }

    /* Dashboards containers */
    .dashboards {
        display: none;
        padding: 0;
        background: white;
        height: calc(100vh - 50px);
        overflow: hidden;
    }

    .dashboards.active {
        display: block;
        animation: fadeSlide 0.4s ease forwards;
    }

    @keyframes fadeSlide {
        from { opacity: 0; transform: translateY(15px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Slideshow container */
    .slideshow {
        position: relative;
        width: 100%;
        height: 100%;
        overflow: hidden;
    }

    /* Slides wrapper */
    .slides {
        display: flex;
        transition: transform 0.5s ease-in-out;
        height: 100%;
    }

    /* Individual slide */
    .slide {
        min-width: 100%;
        height: 100%;
        padding: 20px;
        box-sizing: border-box;
    }

    /* Navigation arrows */
    .prev, .next {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: #F98CA3;
        color: white;
        padding: 10px 14px;
        cursor: pointer;
        border: none;
        border-radius: 50%;
        font-size: 18px;
        z-index: 2;
        
    }

    .prev { left: 10px; }
    .next { right: 10px; }

     .cardsss {
      display: grid;
      grid-template-columns: 100px auto; /* Icon + Content */
      align-items: center;
      gap: 0.5px;
      background-color: #ffe6e6;
      padding: 20px 20px;
      border-radius: 12px;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
      max-width: 250px;
    }
     .cardsss1 {
      display: grid;
      grid-template-columns: 100px auto; /* Icon + Content */
      align-items: center;
      gap: 0.5px;
      background-color: #a6f6a6ff;
      padding: 20px 20px;
      border-radius: 12px;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
      max-width: 250px;
    }

    .cardsss-icon {
      font-size: 70px; /* GIANT ICON */
      color: #ff4d4d;
      text-align: center;
      margin:0.05px;
    }

    .cardsss-content {
      text-align: center;
    }

    .cardsss-content h2 {
      margin: 0;
      font-size: 1.2rem;
    }

    .cardsss-content p {
      margin: 5px 0 0;
      font-size: 1.3rem;
     
    }
        .tablesss {
      width: 50%;
      margin: 20px auto;
      border-collapse: collapse;
    }
    .thsss, .tdsss {
      padding: 10px;
      border: 1px solid #ccc;
      text-align: center;
    }
    .thsss {
      background: #f98ca3;
      color: white;
    }
    .tdsss {
      background-color: white;
    }
      .top-productsss {
        position: absolute;
        left:900px;
        top: 260px;
  background: #fff;
  border-radius: 12px;
  padding: 15px 20px;  /* tighter padding */
  width: 320px;        /* smaller width */
  box-shadow: 0px 2px 8px rgba(0,0,0,0.1);
  height: 375px;
}

.top-productsss h3 {
  margin: 0 0 10px 0;  /* reduce gap under heading */
  font-size: 16px;
}

.top-productsss form {
  margin-bottom: 10px; /* reduce space under dropdown */
}

.top-productsss select {
  padding: 3px 6px; /* smaller dropdown */
  font-size: 14px;
}

.top-productsss canvas {
  width: 100% !important;
  height: auto !important;
  max-height: 450px;   /* shrink chart height */
}
.profile-menu {
  display: none;
  position: absolute;
  left: 120px;
  top: 10px;
  transform: translateX(-50%);
  background:transparent;
  border: 1px solid transparent;
  border-radius: 8px;
  padding: 10px;

}
.profile-menu button {
  display: block;
  width: 100%;
  padding: 12px;
  margin: 8px 0;
  background: #f98ca3;
  border: none;
  color: white;
  border-radius: 5px;
  cursor: pointer;
}
.profile-menu button:hover {
  background: #e6788f;
}
  .report-btn {
       background: var(--primary);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  color: white;
  font-weight: bold;
  cursor: pointer;
  margin: 20px auto;
  display: block;
  position: relative;
  z-index: 999;
  transition: background 0.2s, transform 0.05s;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.report-btn:hover {
  background: #c72b52;
  transform: translateY(-1px);
}
.report-btn:active {
  transform: translateY(0);
}
    .reportmodal {
      display: none;
      position: fixed;
      z-index: 5000;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(0, 0, 0, 0.4);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
    }
    
    .reportmodal-content {
      background: #fff;
      margin: 2% auto;
      padding: 25px;
      border-radius: 12px;
      width: 90%;
      max-width: 1200px;
      height: 90vh;
      box-shadow: 0px 6px 15px rgba(236, 52, 98, 0.2);
      position: relative;
      z-index: 6000;
      display: flex;
      flex-direction: column;
      border: 2px solid var(--sidebar-hover);
    }
    
    /* Tab Navigation */
    .report-tabs {
      display: flex;
      gap: 4px;
      margin-bottom: 20px;
      border-bottom: 3px solid var(--main-bg);
      padding-bottom: 12px;
      background: var(--main-bg);
      padding: 15px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .tab-btn {
      padding: 12px 20px;
      border: none;
      background: var(--sidebar-hover);
      color: var(--text-dark);
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.3s ease;
      font-size: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
      position: relative;
      overflow: hidden;
      border: 2px solid transparent;
    }
    
    .tab-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }
    
    .tab-btn:hover::before {
      left: 100%;
    }
    
    .tab-btn.active {
      background: var(--primary);
      color: var(--text-light);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(236, 52, 98, 0.4);
      border: 2px solid var(--primary);
    }
    
    .tab-btn:hover {
      background: var(--sidebar-bg);
      color: var(--text-light);
      transform: translateY(-1px);
      box-shadow: 0 3px 8px rgba(0,0,0,0.3);
      border: 2px solid var(--sidebar-bg);
    }
    
    
    .date-selector-container {
      display: flex;
      flex-direction: column;
      gap: 15px;
    }
    
    .date-type-selector {
      display: flex;
      gap: 20px;
      justify-content: center;
      margin-bottom: 15px;
    }
    
    .date-type-selector label {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 15px;
      background: white;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .date-type-selector label:hover {
      background: var(--primary);
      color: var(--text-light);
      transform: translateY(-2px);
    }
    
    .date-type-selector input[type="radio"] {
      margin: 0;
    }
    
    .date-type-selector input[type="radio"]:checked + span {
      font-weight: bold;
    }
    
    .date-type-selector label:has(input:checked) {
      background: var(--primary);
      color: var(--text-light);
      box-shadow: 0 4px 8px rgba(236, 52, 98, 0.3);
    }
    
    .date-inputs {
      display: flex;
      justify-content: center;
    }
    
    .date-input-group {
      display: flex;
      flex-direction: column;
      gap: 10px;
      align-items: center;
      padding: 15px;
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .date-input-group label {
      font-weight: 600;
      color: #333;
      margin-bottom: 5px;
    }
    
    .date-input-group input,
    .date-input-group select {
      padding: 10px;
      border: 2px solid #ddd;
      border-radius: 6px;
      font-size: 14px;
      transition: border-color 0.3s ease;
    }
    
    .date-input-group input:focus,
    .date-input-group select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(236, 52, 98, 0.1);
    }
    
    .apply-date-btn {
      padding: 12px 24px;
      background: var(--primary);
      color: var(--text-light);
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      font-size: 14px;
      transition: all 0.3s ease;
      box-shadow: 0 2px 8px rgba(236, 52, 98, 0.3);
    }
    
    .apply-date-btn:hover {
      background: var(--sidebar-bg);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(236, 52, 98, 0.4);
    }
    
    /* Custom Date Modal */
    .custom-date-modal {
      display: none;
      position: fixed;
      z-index: 10000;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
    }
    
    .custom-date-modal-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: white;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
      max-width: 500px;
      width: 90%;
      max-height: 80vh;
      overflow-y: auto;
      animation: modalSlideIn 0.3s ease-out;
    }
    
    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translate(-50%, -60%);
      }
      to {
        opacity: 1;
        transform: translate(-50%, -50%);
      }
    }
    
    .custom-date-modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px 25px;
      border-bottom: 2px solid var(--main-bg);
      background: var(--primary);
      color: var(--text-light);
      border-radius: 16px 16px 0 0;
    }
    
    .custom-date-modal-header h3 {
      margin: 0;
      font-size: 18px;
      font-weight: 600;
    }
    
    .custom-date-close {
      font-size: 24px;
      cursor: pointer;
      color: white;
      transition: color 0.3s ease;
    }
    
    .custom-date-close:hover {
      color: var(--sidebar-hover);
    }
    
    .modal-actions {
      display: flex;
      gap: 15px;
      border-top: 1px solid var(--main-bg);
    }
    
    .cancel-btn {
      padding: 10px 20px;
      background: var(--sidebar-hover);
      color: var(--text-dark);
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-weight: 500;
      transition: all 0.3s ease;
      border: 2px solid var(--sidebar-hover);
    }
    
    .cancel-btn:hover {
      background: var(--sidebar-bg);
      color: var(--text-light);
      transform: translateY(-1px);
      border: 2px solid var(--sidebar-bg);
    }
    
    /* Report Content */
    .report-content {
      flex: 1;
      position: relative;
      overflow: hidden;
    }
    
    .nav-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: #ec3462;
      color: white;
      border: none;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      font-size: 18px;
      cursor: pointer;
      z-index: 10;
      transition: background 0.3s ease;
    }
    
    .nav-btn:hover {
      background: #c72b52;
    }
    
    .prev-btn {
      left: 10px;
    }
    
    .next-btn {
      right: 10px;
    }
    
    /* Report Slides */
    .report-slides {
      display: flex;
      height: 100%;
      transition: transform 0.5s ease;
    }
    
    .report-slide {
      min-width: 100%;
      height: 100%;
      padding: 20px;
      box-sizing: border-box;
      overflow-y: auto;
    }
    
    /* Table Container for Scrollable Tables */
    .table-container {
      max-height: 400px;
      overflow-y: auto;
      border: 1px solid #ddd;
      border-radius: 6px;
    }
    
    .table-container table {
      margin: 0;
      border-collapse: collapse;
    }
    
    .table-container th,
    .table-container td {
      padding: 8px 12px;
      border: 1px solid #ddd;
      text-align:center;
    }
    
    .table-container th {
      background: #ffb3c1;
      position: sticky;
      top: 0;
      z-index: 1;
      
    }
    
    /* Loading indicator styles */
    #loadingIndicator {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255, 255, 255, 0.9);
      z-index: 1000;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    /* Report content positioning */
    .report-content {
      position: relative;
      min-height: 500px;
    }
    
    @keyframes fadeIn { from {opacity: 0; transform: scale(0.9);} to {opacity: 1; transform: scale(1);} }
    .closebtn {
  font-size: 24px;
  cursor: pointer;
  color: #333;
}
    .close:hover { color: black; }
    .report-text { text-align: left; font-size: 15px; line-height: 1.6; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background: #f98ca3; color: white; }
 .download-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 6px;
  background: transparent;
  border-radius: 6px;
  transition: 0.3s;
  color:#333;
}
.download-btn:hover {
  color: #d96a87;
}

/* Slide download button */
.slide-download-btn {
  position: fixed;
  bottom: 20px;
  right: 20px;
  background: #ec3462;
  color: white;
  border: none;
  padding: 12px 20px;
  border-radius: 8px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: bold;
  box-shadow: 0 4px 12px rgba(236, 52, 98, 0.3);
  transition: all 0.3s ease;
  z-index: 10;
}
.slide-download-btn:hover {
  background: #c72b52;
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(236, 52, 98, 0.4);
}
.slide-download-btn svg {
  width: 18px;
  height: 18px;
}
.reportmodal-header {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
}

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
        .profilemodal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0; top: 0;
  width: 100%; height: 100%;
  background: rgba(0,0,0,0.7);
  justify-content: center;
  align-items: center;
}

.profilemodal-content {
  background: white;
  padding: 20px;
  border-radius: 12px;
  width: 500px;
  max-width: 90%;
  display: flex;
  gap: 20px;
}

  .profilemodal-left {
  flex: 1;
}

.profilemodal-left img {
  width: 100%;
  max-width: 150px;
  border-radius: 50%;
}

.profilemodal-right {
  flex: 2;
}
    .profilemodal-right h3 { margin: 0 0 10px; }
    .profilemodal-right p { margin: 6px 0; color: #444; }

    @keyframes fadeIn { from {opacity:0;} to {opacity:1;} }
    @keyframes slideDown { from {transform:translateY(-50px); opacity:0;} to {transform:translateY(0); opacity:1;} }

    .profilemodal-actions {
  margin-top: 20px;
  display: flex;
  gap: 10px;
}

.profileclose-btn, .profileedit-btn {
  padding: 8px 15px;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}

.profileclose-btn {
  background: #f77a8f;
  color: white;
}

.profileedit-btn {
  background: #4caf50;
  color: white;
}

    .open-btn {
      margin: 20px;
      padding: 10px 20px;
      background: #f77a8f;
      color: #fff;
      border: none;
      border-radius: 6px;
      cursor: pointer;
    }

      .editModal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.35);
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .editmodal {
      background: #fff;
      border-radius: 10px;
      padding: 36px 32px 28px 32px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.18);
      text-align: center;
      min-width: 320px;
      max-width: 90vw;
    }
    .editmodal-title {
      font-size: 20px;
      font-weight: bold;
      margin-bottom: 32px;
      color: #222;
    }
    .editmodal-btns {
      display: flex;
      gap: 18px;
      justify-content: center;
    }
    .editmodal-btn {
      padding: 12px 36px;
      border-radius: 4px;
      font-size: 16px;
      font-weight: bold;
      cursor: pointer;
      border: 2px solid transparent;
      transition: background 0.2s, color 0.2s, border 0.2s;
    }
    .editmodal-btn.editconfirm {
      background: #ec3462;
      color: #fff;
      border: 2px solid #ec3462;
    }
    .editmodal-btn.confirm:hover {
      background: #c72b52;
      border-color: #c72b52;
    }
    .editmodal-btn.editcancel {
      background: #fff;
      color: #ec3462;
      border: 2px solid #ec3462;
    }
    .editmodal-btn.editcancel:hover {
      background: #ffe6ee;
    }
    .profile-form {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 18px;
      margin-top: 0;
    }
    .profile-form-row {
      display: flex;
      gap: 24px;
      width: 100%;
      justify-content: center;
      margin-bottom: 10px;
    }
    .profile-form-group {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
      margin-bottom: 0;
      min-width: 180px;
    }
    .profile-form-label {
      font-size: 1rem;
      color: #444;
      font-weight: 500;
      margin-bottom: 2px;
    }
    .profile-form-input {
      font-size: 1.1rem;
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid #ccc;
      width: 220px;
      font-family: 'Barlow', Arial, sans-serif;
      background: #fff;
      color: #222;
      box-sizing: border-box;
    }
    .profile-form-pic {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
      width: 100%;
    }
    .profile-form-pic img {
      width: 120px;
      height: 120px;
      max-width: 120px;
      max-height: 120px;
      object-fit: cover;
      border-radius: 12px;
      background: #eee;
      margin-bottom: 6px;
      display: block;
      margin-left: auto;
      margin-right: auto;
    }
    @media (max-width: 900px) {
      .main-content {
        padding: 16px 8px;
        margin-left: 80px;
      }
      .content-container {
        padding: 16px;
        margin: 8px;
        max-width: 95%;
      }
      table {
        font-size: 14px;
      }
      th, td {
        padding: 8px 4px;
      }
    }
    @media (max-width: 700px) {
      .profile-form-row {
        flex-direction: column;
        gap: 10px;
        align-items: center;
      }
      .profile-form-group {
        min-width: 0;
        width: 100%;
      }
      .profile-form-input {
        width: 100%;
      }
      .main-content {
        margin-left: 0;
        padding: 8px;
      }
      .content-container {
        padding: 12px;
        margin: 4px;
        max-width: 98%;
      }
    }
  </style>
</head>
<body>
<div class="dashboard">
  <!-- Sidebar -->
  <div class="sidebar">
    <div class="logo">
      <img src="newlogo.png" alt="MSC Cookies Logo">
    </div>
    <div class="nav">
      <div class="nav-icon" title="Visualization" onclick="window.location.href='descriptive_dashboard.php'">
        <div class="nav-icon-content">
          <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4.5V19a1 1 0 0 0 1 1h15M7 14l4-4 4 4 5-5m0 0h-3.207M20 9v3.207"/>
          </svg>
          <span class="nav-text">Analytics</span>
        </div>
      </div>

      <div class="nav-icon" title="Sales" onclick="window.location.href='sales_history.php'">
        <div class="nav-icon-content">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-list-check" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M5 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3.854 2.146a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 3.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 7.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0"/>
          </svg>
          <span class="nav-text">Sales History</span>
        </div>
      </div>

      <div class="nav-icon active" title="Generate reports" onclick="window.location.href='generate_reports.php'">
        <div class="nav-icon-content">
          <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 8h6m-6 4h6m-6 4h6M6 3v18l2-2 2 2 2-2 2 2 2-2 2 2V3l-2 2-2-2-2 2-2-2-2 2-2-2Z"/>
          </svg>
          <span class="nav-text">Generate Reports</span>
        </div>
      </div>

      <div class="nav-icon" title="User Logs" onclick="window.location.href='notifications.php'" style="position:relative;">
        <div class="nav-icon-content">
          <svg class="w-[20px] h-[20px] text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
          </svg>
          <span class="nav-text">User Logs</span>
        </div>
      </div>

      <div class="nav-icon" title="Add Product" onclick="window.location.href='products_management.php'">
        <div class="nav-icon-content">
          <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.65692 9.41494h.01M7.27103 13h.01m7.67737 1.9156h.01M10.9999 17h.01m3.178-10.90671c-.8316.38094-1.8475.22903-2.5322-.45571-.3652-.36522-.5789-.82462-.6409-1.30001-.0574-.44-.0189-.98879.1833-1.39423-1.99351.20001-3.93304 1.06362-5.46025 2.59083-3.51472 3.51472-3.51472 9.21323 0 12.72793 3.51471 3.5147 9.21315 3.5147 12.72795 0 1.5601-1.5602 2.4278-3.5507 2.6028-5.5894-.2108.008-.6725.0223-.8328.0157-.635.0644-1.2926-.1466-1.779-.633-.3566-.3566-.5651-.8051-.6257-1.2692-.0561-.4293.0145-.87193.2117-1.26755-.1159.20735-.2619.40237-.4381.57865-1.0283 1.0282-2.6953 1.0282-3.7235 0-1.0282-1.02824-1.0282-2.69531 0-3.72352.0977-.09777.2013-.18625.3095-.26543"/>
          </svg>
          <span class="nav-text">Product Management</span>
        </div>
      </div>

      <div class="nav-icon" title="Staff Management" onclick="window.location.href='staff_management.php'">
        <div class="nav-icon-content">
          <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
            <path fill-rule="evenodd" d="M9 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm-2 9a4 4 0 0 0-4 4v1a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-1a4 4 0 0 0-4-4H7Zm8-1a1 1 0 0 1 1-1h1v-1a1 1 0 1 1 2 0v1h1a1 1 0 1 1 0 2h-1v1a1 1 0 1 1-2 0v-1h-1a1 1 0 0 1-1-1Z" clip-rule="evenodd"/>
          </svg>
          <span class="nav-text">Staff Management</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Main -->
  <div class="main-content"><div class="content-container">
      <h2 style="color: var(--primary); margin-bottom: 24px; text-align: center;">ðŸ“Š Sales Analytics Dashboard</h2>
      
      <div class="sales-section">
        <h3 style="color: var(--text-dark); margin-bottom: 16px;">ðŸ§¾ All Sales</h3>
        <div class="table-container">
          <table>
            <thead>
              <tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Subtotal (â‚±)</th></tr>
            </thead>
            <tbody>
              <?php while($r = $recent->fetch_assoc()): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($r['Sales_Date'])) ?></td>
                <td><?= htmlspecialchars($r['Customer_Name']) ?></td>
                <td><?= htmlspecialchars($r['Product_Name']) ?></td>
                <td><?= $r['Quantity'] ?></td>
                <td>â‚±<?= number_format($r['Subtotal'], 2) ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
              </div>
      </div>
      
      <button class="report-btn" onclick="openreportModal()">ðŸ“‹ Generate Comprehensive Report</button>
          </div>
  </div>
    </div>
   <div id="reportModal" class="reportmodal">
  <div class="reportmodal-content">
    <div class="reportmodal-header">
      <a href="export_report.php" target="_blank" class="download-btn" id="downloadReportBtn" title="Download Report">
        <svg class="w-[20px] h-[20px] text-gray-800" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" 
             width="24" height="24" fill="none" viewBox="0 0 24 24">
          <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
        </svg>
      </a>
      <span class="closebtn" onclick="closereportModal()">&times;</span>
    </div>
    
    <!-- Tab Navigation -->
    <div class="report-tabs">
      <button class="tab-btn active" onclick="switchTab('all')">All Time</button>
      <button class="tab-btn" onclick="switchTab('today')">Today</button>
      <button class="tab-btn" onclick="switchTab('week')">This Week</button>
      <button class="tab-btn" onclick="switchTab('month')">This Month</button>
      <button class="tab-btn" onclick="switchTab('year')">This Year</button>
      <button class="tab-btn" onclick="switchTab('custom')">Custom Date</button>
    </div>
    
    
    <!-- Report Content with Navigation -->
    <div class="report-content">
      <button class="nav-btn prev-btn" onclick="navigateReport(-1)">â®</button>
      <button class="nav-btn next-btn" onclick="navigateReport(1)">â¯</button>
      
      <div class="report-slides">
        <!-- Slide 1: Summary -->
        <div class="report-slide active">
          <h2 align="center" style="color:#462F1F;">Sales Report Summary</h2>
          <div class="report-text">
            <?php foreach ($report as $line): ?>
              <p><?= $line ?></p>
            <?php endforeach; ?>
                </div>
        </div>
        
        <!-- Slide 2: All Sales -->
        <div class="report-slide">
          <h3 align="center" style="color:#462F1F;">All Sales</h3>
          <div class="table-container">
            <table>
              <tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (â‚±)</th></tr>
              <?php foreach ($allSales as $sale): ?>
                <tr>
                  <td><?= $sale['date'] ?></td>
                  <td><?= htmlspecialchars($sale['Customer_Name']) ?></td>
                  <td><?= htmlspecialchars($sale['Product_Name']) ?></td>
                  <td><?= $sale['Quantity'] ?></td>
                  <td>â‚±<?= number_format($sale['Subtotal'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
                </div>
        </div>
        
        <!-- Slide 3: Top Products -->
        <div class="report-slide">
          <h3 align="center" style="color:#462F1F;">Top 3 Products</h3>
          <table>
            <tr><th>Product</th><th>Quantity Sold</th></tr>
            <?php foreach ($topProducts as $prod): ?>
              <tr>
                <td><?= htmlspecialchars($prod['Product_Name']) ?></td>
                <td><?= $prod['qty'] ?> pcs</td>
              </tr>
            <?php endforeach; ?>
          </table>
         
        </div>
        
        <!-- Slide 4: Verification -->
        <div class="report-slide">
          <h3 align="center" style="color:#462F1F;">Verification â€” Previous 5 Months (Actual vs Hybrid Forecast)</h3>
          <?php if (!empty($verification_data)): ?>
            <table>
              <thead>
                <tr>
                  <th>Month</th>
                  <th>Actual (â‚±)</th>
                  <th>Hybrid Forecast (â‚±)</th>
                  <th>Error (%)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($verification_data as $row): 
                    $actual = isset($row['Actual_Sales']) ? floatval($row['Actual_Sales']) : null;
                    $pred  = isset($row['Hybrid_Forecast']) ? floatval($row['Hybrid_Forecast']) : null;
                    $errPct = (is_numeric($actual) && $actual != 0 && is_numeric($pred)) ? (abs($actual - $pred)/$actual)*100 : null;
                ?>
                  <tr>
                    <td><?= htmlspecialchars($row['DATE'] ?? ($row['date'] ?? '')) ?></td>
                    <td>â‚±<?= is_numeric($actual) ? number_format($actual,2) : '-' ?></td>
                    <td>â‚±<?= is_numeric($pred) ? number_format($pred,2) : '-' ?></td>
                    <td><?= is_numeric($errPct) ? number_format($errPct,2) . '%' : '-' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p><em>No verification/backtest rows available to display.</em></p>
          <?php endif; ?>
       
        </div>
        
        <!-- Slide 5: Prediction Accuracy -->
        <div class="report-slide">
          <h3 align="center" style="color:#462F1F;">Prediction Accuracy</h3>
          <?php if (!empty($metrics)): ?>
            <table>
              <tbody>
                <tr><th>Metric</th><th>Value</th></tr>
                <tr><td>MAE</td><td><?= isset($metrics['MAE']) ? number_format($metrics['MAE'],2) : '-' ?></td></tr>
                <tr><td>RMSE</td><td><?= isset($metrics['RMSE']) ? number_format($metrics['RMSE'],2) : '-' ?></td></tr>
                <tr><td>MAPE</td><td><?= isset($metrics['MAPE']) ? number_format($metrics['MAPE'],2) . '%' : '-' ?></td></tr>
                <tr><td>Accuracy</td><td><?= isset($metrics['Accuracy']) ? number_format($metrics['Accuracy'],2) . '%' : '-' ?></td></tr>
                <tr><td>RÂ²</td><td><?= isset($metrics['R2']) ? number_format($metrics['R2'],4) : '-' ?></td></tr>
              </tbody>
            </table>
          <?php else: ?>
            <p><em>No metrics available.</em></p>
          <?php endif; ?>
          
        </div>
        
        <!-- Slide 6: Future Forecast -->
        <div class="report-slide">
          <h3 align="center" style="color:#462F1F;">Future Forecast (12 months)</h3>
          <?php if (!empty($future_table)): ?>
            <table>
              <thead>
                <tr>
                  <th>Month</th>
                  <th>Forecast (â‚±)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($future_table as $f): 
                    $month = $f['DATE'] ?? ($f['date'] ?? '');
                    $val = $f['Future_Forecast'] ?? ($f['Future_Forecast'] ?? null);
                ?>
                  <tr>
                    <td><?= htmlspecialchars($month) ?></td>
                    <td>â‚±<?= is_numeric($val) ? number_format(floatval($val),2) : '-' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p><em>No future forecast available.</em></p>
          <?php endif; ?>
              </div>
      </div>
    
      </div>
  </div>
</div>

<!-- Custom Date Modal -->
<div id="customDateModal" class="custom-date-modal">
  <div class="custom-date-modal-content">
    <div class="custom-date-modal-header">
      <h3>Select Custom Date Range</h3>
      <span class="custom-date-close" onclick="closeCustomDateModal()">&times;</span>
    </div>
    
    <div class="date-selector-container">
      <div class="date-type-selector">
        <label>
          <input type="radio" name="dateType" value="full" checked onchange="toggleDateInputs()">
          <span>Full Date</span>
        </label>
        <label>
          <input type="radio" name="dateType" value="month" onchange="toggleDateInputs()">
          <span>Month & Year</span>
        </label>
        <label>
          <input type="radio" name="dateType" value="year" onchange="toggleDateInputs()">
          <span>Year Only</span>
        </label>
      </div>
      
      <div class="date-inputs">
        <!-- Full Date Input -->
        <div id="fullDateInput" class="date-input-group">
          <label>Select Date:</label>
          <input type="date" id="customDatePicker" value="<?= date('Y-m-d') ?>">
        </div>
        
        <!-- Month & Year Input -->
        <div id="monthYearInput" class="date-input-group" style="display: none;">
          <label>Select Month & Year:</label>
          <select id="customMonthPicker">
            <option value="01" <?= date('m') == '01' ? 'selected' : '' ?>>January</option>
            <option value="02" <?= date('m') == '02' ? 'selected' : '' ?>>February</option>
            <option value="03" <?= date('m') == '03' ? 'selected' : '' ?>>March</option>
            <option value="04" <?= date('m') == '04' ? 'selected' : '' ?>>April</option>
            <option value="05" <?= date('m') == '05' ? 'selected' : '' ?>>May</option>
            <option value="06" <?= date('m') == '06' ? 'selected' : '' ?>>June</option>
            <option value="07" <?= date('m') == '07' ? 'selected' : '' ?>>July</option>
            <option value="08" <?= date('m') == '08' ? 'selected' : '' ?>>August</option>
            <option value="09" <?= date('m') == '09' ? 'selected' : '' ?>>September</option>
            <option value="10" <?= date('m') == '10' ? 'selected' : '' ?>>October</option>
            <option value="11" <?= date('m') == '11' ? 'selected' : '' ?>>November</option>
            <option value="12" <?= date('m') == '12' ? 'selected' : '' ?>>December</option>
          </select>
          <input type="number" id="customYearPicker" min="2020" max="2030" value="<?= date('Y') ?>">
        </div>
        
        <!-- Year Only Input -->
        <div id="yearOnlyInput" class="date-input-group" style="display: none;">
          <label>Select Year:</label>
          <input type="number" id="customYearOnlyPicker" min="2020" max="2030" value="<?= date('Y') ?>">
              </div>
      </div>
      
      <div class="modal-actions">
        <button onclick="closeCustomDateModal()" class="cancel-btn">Cancel</button>
        <button onclick="applyCustomDate()" class="apply-date-btn">Apply Date Filter</button>
            </div>
    </div>
  
      </div>
</div>

  
  </div>
</div>
</body>
</html>


