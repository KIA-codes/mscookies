<?php


session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
// Check if the user is staff
$user_id = $_SESSION['user_id'];
$userResult = $conn->query("SELECT UserType FROM User WHERE User_ID = $user_id");
$userRow = $userResult ? $userResult->fetch_assoc() : null;
if (!$userRow || $userRow['UserType'] !== 'admin') {
    header('Location: index.php');
    exit;
}
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
//}


$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
// Basic stats
$userProfileQuery = $conn->prepare("SELECT Profile_Picture FROM User WHERE User_ID = ?");
$userProfileQuery->bind_param("i", $user_id);
$userProfileQuery->execute();
$userProfileResult = $userProfileQuery->get_result();
$userProfile = $userProfileResult->fetch_assoc();
$userProfileQuery->close();

// Set profile picture path - use user's profile picture if available, otherwise use default
$profilePicture = $userProfile['Profile_Picture'] ? $userProfile['Profile_Picture'] : 'newlogo.png';

$stats = $conn->query("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(Quantity) AS total_items,
        SUM(Subtotal) AS total_sales 
    FROM sales
")->fetch_assoc();

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

// Recent sales (for table widget)
$recent = $conn->query("
    SELECT s.Sales_Date, s.Customer_Name, p.Product_Name, s.Quantity, s.Subtotal 
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    ORDER BY s.Sales_Date DESC 
    LIMIT 10
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
// Totals
$totalSalesQuery = $conn->query("
    SELECT SUM(Subtotal) as totalSales, COUNT(DISTINCT Order_Code) as totalOrders 
    FROM sales
");
$totals = $totalSalesQuery->fetch_assoc();
$totalSales = $totals['totalSales'] ?? 0;
$totalOrders = $totals['totalOrders'] ?? 0;

// Average sales per order
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

// Best-selling product (all time)
$topProductQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 1
");
$topProduct = $topProductQuery->fetch_assoc();

// Peak sales month
$peakMonthQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%Y-%M') as month, SUM(Subtotal) as total
    FROM sales
    GROUP BY month
    ORDER BY total DESC
    LIMIT 1
");
$peakMonth = $peakMonthQuery->fetch_assoc();

// Recent sales (last 5 orders)
$recentSalesQuery = $conn->query("
    SELECT DATE_FORMAT(s.Sales_Date, '%b %d, %Y') as date, 
           s.Customer_Name, 
           s.Subtotal, 
           p.Product_Name, 
           s.Quantity
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    ORDER BY s.Sales_Date DESC
    LIMIT 5
");
$recentSales = $recentSalesQuery->fetch_all(MYSQLI_ASSOC);

// Top 3 products from recent sales
$topRecentProductsQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM (
        SELECT * FROM sales ORDER BY Sales_Date DESC LIMIT 5
    ) s
    JOIN product p ON s.Product_ID = p.Product_ID
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 3
");
$topRecentProducts = $topRecentProductsQuery->fetch_all(MYSQLI_ASSOC);

// === BUILD REPORT SENTENCES ===
$report = [];
$report[] = "The system has recorded a total of ₱" . number_format($totalSales, 2) . " in sales across " . number_format($totalOrders) . " orders.";
$report[] = "On average, each order contributes ₱" . number_format($avgSales, 2) . " in sales.";
if ($topProduct) {
    $report[] = "The top-selling product overall is " . htmlspecialchars($topProduct['Product_Name']) . " with " . $topProduct['qty'] . " units sold.";
}
if ($peakMonth) {
    $report[] = "The highest sales were recorded in " . $peakMonth['month'] . ", reaching ₱" . number_format($peakMonth['total'], 2) . ".";
}
$report[] = "Recent sales trends are shown below, including the top 3 products in the most recent transactions.";


$stmt = $conn->prepare("SELECT * FROM User WHERE User_ID = ?");
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
    $stmt = $conn->prepare("SELECT COUNT(*) FROM User WHERE (Username = ? OR Email = ?) AND User_ID != ?");
    $stmt->bind_param("ssi", $username, $email, $user_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    if ($count > 0) {
        // Check which one is duplicate
        $stmt = $conn->prepare("SELECT Username, Email FROM User WHERE (Username = ? OR Email = ?) AND User_ID != ? LIMIT 1");
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
        $stmt = $conn->prepare("UPDATE User SET FName=?, LName=?, Email=?, Username=?, Profile_Picture=? WHERE User_ID=?");
        $stmt->bind_param("sssssi", $fname, $lname, $email, $username, $profilePic, $user_id);
        $stmt->execute();
        $stmt->close();
        $message =" <script>
            alert('Profile updated successfully');
            window.location.href = 'descriptive_dashboard.php';
        </script>";
        // Refresh user data
        $stmt = $conn->prepare("SELECT * FROM User WHERE User_ID = ?");
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
   flex-direction: row;
       padding: 0;
            gap: 0;
            margin-left: 80px; 

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
      max-width: 2000px;
      display: block;
      margin: 40px auto;
      margin-top: 10px;
      background: white;
      border-radius: 8px;
      padding: 20px;
    }
    
    /* Ensure forecast chart is properly contained */
    #forecastChart {
      max-width: 100%;
      height: auto;
      display: block;
      margin: 0 auto;
    }
    table {
      width: 90%;
      margin: 20px auto;
      border-collapse: collapse;
    }
    th, td {
      padding: 10px;
      border: 1px solid #ccc;
      text-align: center;
    }
    th {
      background: #f98ca3;
      color: white;
    }
    td{
      background-color: white;
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
  z-index: 5;
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
    
    .tab.active.visible {
        background: #ffcccdff;
        border-bottom: 2px solid #ffcccdff;
        box-shadow: 0 -1px 5px rgba(0,0,0,0.1);
    }
    
    .tab.active:not(.visible) {
        background: #FF7E94;
        border-bottom: 2px solid #FF7E94;
        box-shadow: none;
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
        position: relative;
    }

    .dashboards.active {
        display: block;
        animation: fadeSlide 0.4s ease forwards;
    }

    .dashboards:not(.active) {
        display: none !important;
        visibility: hidden;
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
        position: relative;
        overflow: hidden;
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
       background: #f98ca3;
  border: none;
  padding: 12px 18px;
  border-radius: 6px;
  color: white;
  font-weight: bold;
  cursor: pointer;
  margin: 20px;
  position: relative; /* 🔑 Required for z-index to work */
  z-index: 999;      /* Bring it above everything */
}
    .reportmodal {
      display: none; position: fixed; z-index: 2000;
      left: 100; top: 0;  width: 100vw; height: 100vh;
 
   background-color: transparent;
  backdrop-filter: blur(6px);       /* apply blur effect */
  -webkit-backdrop-filter: blur(6px); 
      justify-content: center; align-items: center;
      font-family: Arial;
      font-size: small;
      
    }
    .reportmodal-content {
      background: white; padding: 25px; border-radius: 12px;
      width: 750px; max-width: 95%;
      animation: fadeIn 0.3s ease;
      position:relative;
      top: auto;
      z-index: 2001;
       
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
    }
    .metrics-container { max-width: 1000px; margin: 20px auto; }
    .metrics-table { width: 100%; border-collapse: collapse; display: none; }
    .metrics-table th, .metrics-table td {
      border: 1px solid #ccc; padding: 8px; text-align: center;
    }
    .metrics-table th { background: #f4f4f4; }
    .tooltip { border-bottom: 1px dotted black; cursor: help; }
     .metrics-container {
     display: flex;
  justify-content: center;  /* center horizontally */
  gap: 20px;                /* space between cards */
  margin-top: 20px;
  flex-wrap: wrap;         
      position: fixed;
      bottom:0;
      left:225px;
    }
    .metric-card {
     background: white;
  border-radius: 12px;
  padding: 15px 25px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.15);
  text-align: center;
  min-width: 120px;
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
      color: pink;
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
  </style>
</head>
<body onload="showCookieLoader()">

<div class="dashboard">
  <!-- Sidebar -->
  <div class="sidebar">
    <div class="logo">
      <img src="newlogo.png" alt="MSC Cookies Logo">
    </div>
    <div class="nav">
      <div class="nav-icon active" title="Visualization" onclick="window.location.href='descriptive_dashboard.php'">
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

      <div class="nav-icon" title="Generate reports" onclick="window.location.href='generate_reports.php'">
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
          <?php if ($hasNotifications): ?>
            <span class="notification-badge"></span>
          <?php endif; ?>
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
    </div>
  </div>
<!-- Main -->
  <div class="main-content">
    <div class="tab-bar">
    <div class="tab active visible" data-target="descriptive" style="color:#5C4033;">Analysis</div>
    <div class="tab active" data-target="predictive"  style="color:#5C4033;">Forecast</div>
    
</div>

<!-- Predictive Dashboards -->
<div id="predictive" class="dashboards active">
    <div class="slideshow">
        <div class="slides" id="predictiveSlides">
            <div class="slide" style="background:#f4c6c6;">
                <h2 style="margin-top: 10px; color:#5C4033" align="center" ><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.6 16.733c.234.269.548.456.895.534a1.4 1.4 0 0 0 1.75-.762c.172-.615-.446-1.287-1.242-1.481-.796-.194-1.41-.861-1.241-1.481a1.4 1.4 0 0 1 1.75-.762c.343.077.654.26.888.524m-1.358 4.017v.617m0-5.939v.725M4 15v4m3-6v6M6 8.5 10.5 5 14 7.5 18 4m0 0h-3.5M18 4v3m2 8a5 5 0 1 1-10 0 5 5 0 0 1 10 0Z"/>
</svg>
Future Sales Analysis – MSCookies
<svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6.025A7.5 7.5 0 1 0 17.975 14H10V6.025Z"/>
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 3c-.169 0-.334.014-.5.025V11h7.975c.011-.166.025-.331.025-.5A7.5 7.5 0 0 0 13.5 3Z"/>
</svg>

</h2>
                <div style="max-width: fit-content;max-height:fit-content;position: fixed;" >
                       <canvas id="forecastChart" width="1350" height="450"></canvas>
                </div>
            <div class="metrics-container">
    <div class="metric-card"  title="Lower is better. Shows typical forecast error in sales units.">
      <div class="metric-title">MAE</div>
      <div class="metric-value" id="mae"></div>
      <div class="metric-explanation">Lower is better. Shows typical forecast error in sales units.</div>
    </div>
    <div class="metric-card" title="Lower is better. Sensitive to large mistakes.">
      <div class="metric-title">RMSE</div>
      <div class="metric-value" id="rmse"></div>
      <div class="metric-explanation">Lower is better. Sensitive to large mistakes.</div>
    </div>
    <div class="metric-card" title="Average error as a % of actual sales.">
      <div class="metric-title">MAPE</div>
      <div class="metric-value" id="mape"></div>
      <div class="metric-explanation">Average error as a % of actual sales.</div>
    </div>
    <div class="metric-card" title="Closer to 100% means a better model.">
      <div class="metric-title">Accuracy</div>
      <div class="metric-value" id="accuracy"></div>
      <div class="metric-explanation">Closer to 100% means a better model.</div>
    </div>
    <div class="metric-card" title="Ranges from 0 to 1. Higher means better fit.">
      <div class="metric-title">R²</div>
      <div class="metric-value" id="r2"></div>
      <div class="metric-explanation">Ranges from 0 to 1. Higher means better fit.</div>
    </div>
  </div>
              </div>
           
 
<script>
  // ===== CHART DATA =====
  const labels = forecastData.map(d => d.DATE);

  // Convert safely to numbers
  const actual = forecastData.map(d => d.Actual_Sales !== null ? Number(d.Actual_Sales) : null);
  const hybrid = forecastData.map(d => d.Hybrid_Forecast !== null ? Number(d.Hybrid_Forecast) : null);
  const future = forecastData.map(d => d.Future_Forecast !== null ? Number(d.Future_Forecast) : null);

  console.log("Forecast Data:", forecastData);
  console.log("Future Forecast Data:", future);

  
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
          fill: false,
          spanGaps: true
        },
        {
          label: "Hybrid Forecast",
          data: hybrid,
          borderColor: "pink",
          pointBackgroundColor: "pink",
          borderWidth: 2,
          pointRadius: 3,
          fill: false,
          spanGaps: true
        },
        {
          label: "Future Forecast",
          data: future,
          borderColor: "blue",           
          borderDash: [8, 4],            
          pointBackgroundColor: "blue",  
          borderWidth: 3,                
          pointRadius: 5,                
          fill: false,
          spanGaps: true
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
        x: { 
          title: { display: true, text: "Date" },
          ticks: { autoSkip: true, maxTicksLimit: 12 }
        },
        y: { 
          title: { display: true, text: "Sales" }, 
          beginAtZero: true 
        }
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
            
        </div><!--
        <button class="prev" onclick="moveSlide('predictiveSlides', -1)"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 19-7-7 7-7"/>
</svg>
</button>
        <button class="next" onclick="moveSlide('predictiveSlides', 1)"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
</svg>
</button>-->
    </div>
</div>
 


<!-- Descriptive Dashboards -->
<div id="descriptive" class="dashboards active">
    <div class="slideshow">
        <div class="slides" id="descriptiveSlides">
            <div class="slide" style="background:#f4c6c6; color:#5C4033;">
                <h2 style="margin-top: 10px; color:#5C4033" align="center" ><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.6 16.733c.234.269.548.456.895.534a1.4 1.4 0 0 0 1.75-.762c.172-.615-.446-1.287-1.242-1.481-.796-.194-1.41-.861-1.241-1.481a1.4 1.4 0 0 1 1.75-.762c.343.077.654.26.888.524m-1.358 4.017v.617m0-5.939v.725M4 15v4m3-6v6M6 8.5 10.5 5 14 7.5 18 4m0 0h-3.5M18 4v3m2 8a5 5 0 1 1-10 0 5 5 0 0 1 10 0Z"/>
</svg>
 Sales Performance Analysis - MSCookies
<svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6.025A7.5 7.5 0 1 0 17.975 14H10V6.025Z"/>
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 3c-.169 0-.334.014-.5.025V11h7.975c.011-.166.025-.331.025-.5A7.5 7.5 0 0 0 13.5 3Z"/>
</svg>

</h2>
              <div style="width:auto; height:auto;position:absolute; left: 70px;">
  <div class="chart" >
                 <label style="margin-bottom: 0;padding-bottom:0%;"><strong>Filter by Year:</strong></label>
    <select id="yearFilter" onchange="updateChart()">
      <option value="all">All</option>
      <option value="2021">2021</option>
      <option value="2022">2022</option>
       <option value="2023">2023</option>
      <option value="2024">2024</option>
      <option value="2025">2025</option>
    </select>
     
              </div>
   <canvas id="salesChart" width="750" height="550"></canvas>
                </div>
    <!-- Cards -->
     
      <div class="cardsss1" style="position:absolute; left: 835px; top: 110px;">
    <div class="cardsss-icon" style="color:green;">
     <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="55" height="55" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M8 7V6a1 1 0 0 1 1-1h11a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-1M3 18v-7a1 1 0 0 1 1-1h11a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Zm8-3.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/>
</svg>

    </div>
    <div class="cardsss-content">
      <h2><strong>Total Sales</strong></h2>
      <p>₱<?= number_format($stats['total_sales'], 2) ?></p>
    </div>
  </div>
 <div class="cardsss" style="position:absolute; left: 1100px;top: 110px;">
    <div class="cardsss-icon">
     <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="55" height="55" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 4h1.5L9 16m0 0h8m-8 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm8 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm-8.5-3h9.25L19 7H7.312"/>
</svg>


    </div>
    <div class="cardsss-content">
      <h2><strong>Total Orders</strong></h2>
      <p><?= $stats['total_orders'] ?></p>
    </div>
  </div>
<div class="top-productsss">
  <h3> Top Products</h3>
  <form method="GET" onchange="this.submit()">
      <label for="month">Filter:</label>
      <select name="month" id="month">
          <option value="all" <?= $selectedMonth === "all" ? "selected" : "" ?>>All</option>
          <?php while ($m = $monthsResult->fetch_assoc()): ?>
              <option value="<?= $m['month'] ?>" <?= $selectedMonth === $m['month'] ? "selected" : "" ?>>
                  <?= $m['month'] ?>
              </option>
          <?php endwhile; ?>
      </select>
  </form>
  <canvas id="topProductsChart"></canvas>
</div>
 
    

    <!-- Top 3 Products -->
    
       


            </div>
              
           
</div><!--
<button class="prev" onclick="moveSlide('descriptiveSlides', -1)"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 19-7-7 7-7"/>
</svg>
</button>
        <button class="next" onclick="moveSlide('descriptiveSlides', 1)"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/>
</svg>
</button>-->
</div>
            
           
        </div>
        
    </div>
</div>



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
    // Tab switching
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            // Remove visible class from all tabs
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('visible'));
            
            // Add visible class to clicked tab
            tab.classList.add('visible');

            // Hide all dashboards and remove active class
            document.querySelectorAll('.dashboards').forEach(d => {
                d.classList.remove('active');
                d.style.display = 'none';
                d.style.visibility = 'hidden';
            });
            
            // Show the target dashboard
            const target = tab.getAttribute('data-target');
            const targetDashboard = document.getElementById(target);
            if (targetDashboard) {
                targetDashboard.classList.add('active');
                targetDashboard.style.display = 'block';
                targetDashboard.style.visibility = 'visible';
            }
        });
    });

    // Initialize tabs on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Ensure only the visible tab content is shown
        document.querySelectorAll('.dashboards').forEach(d => {
            const isVisible = d.id === 'descriptive'; // descriptive is the default visible one
            if (isVisible) {
                d.classList.add('active');
                d.style.display = 'block';
                d.style.visibility = 'visible';
            } else {
                d.classList.remove('active');
                d.style.display = 'none';
                d.style.visibility = 'hidden';
            }
        });
    });

    // Slide indices for both carousels
    let slideIndexes = {
        predictiveSlides: 0,
        descriptiveSlides: 0
    };

    function moveSlide(slideshowId, direction) {
        const slidesContainer = document.getElementById(slideshowId);
        const slides = slidesContainer.children.length;
        slideIndexes[slideshowId] += direction;

        if (slideIndexes[slideshowId] < 0) slideIndexes[slideshowId] = slides - 1;
        if (slideIndexes[slideshowId] >= slides) slideIndexes[slideshowId] = 0;

        slidesContainer.style.transform = `translateX(-${slideIndexes[slideshowId] * 100}%)`;
    }
</script>

<script>
  function toggleProfileMenu() {
  const menu = document.getElementById('profile-menu');
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}
const ctx1 = document.getElementById('topProductsChart').getContext('2d');
const topProductsChart = new Chart(ctx1, {
    type: 'pie',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            data: <?= json_encode($values) ?>,
            backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#8BC34A','#FF9800'],
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.label || '';
                        let value = context.raw || 0;
                        return label + ': ' + value + " pcs";
                    }
                }
            }
        }
    }
});
</script>
<script>
const allLabels = <?= json_encode($chart_labels) ?>;
const allValues = <?= json_encode($chart_values) ?>;
let chart;

function updateChart() {
  const selectedYear = document.getElementById('yearFilter').value;
  const filteredLabels = [];
  const filteredValues = [];

  allLabels.forEach((label, i) => {
    const year = label.substring(0, 4);
    if (selectedYear === 'all' || year === selectedYear) {
      filteredLabels.push(label);
      filteredValues.push(allValues[i]);
    }
  });

  chart.data.labels = filteredLabels;
  chart.data.datasets[0].data = filteredValues;
  chart.update();
}

const ctx2 = document.getElementById('salesChart').getContext('2d');
chart = new Chart(ctx2, {
  type: 'line',
  data: {
    labels: allLabels,
    datasets: [{
      label: 'Monthly Sales (₱)',
      data: allValues,
      borderColor: '#f98ca3',
      backgroundColor: 'rgba(249, 140, 163, 0.1)',
      borderWidth: 3,
      pointBackgroundColor: '#ec3462',
      pointBorderColor: '#fff',
      pointBorderWidth: 2,
      pointRadius: 6,
      pointHoverRadius: 8,
      fill: true,
      tension: 0.4
    }]
  },
  options: {
    responsive: false,
    plugins: {
      tooltip: {
        callbacks: {
          label: ctx => `₱${ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2 })}`
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: val => '₱' + val.toLocaleString()
        }
      }
    }
  }
});
</script>


      <div class="top-bar">
  <div class="logooo-menu" id="menuContainer">
     <img src="<?= htmlspecialchars($profilePicture) ?>" alt="Profile Picture" class="logooo" id="logoooBtn" >
     <div class="option-btn settings" title="view profile" onclick="openProfileModal('profileModal')"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
</svg>
</div>
     <div class="option-btn settings" title="staff/admin management" onclick="window.location.href='staff_management.php'"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" viewBox="0 0 24 24">
  <path fill-rule="evenodd" d="M9 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm-2 9a4 4 0 0 0-4 4v1a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-1a4 4 0 0 0-4-4H7Zm8-1a1 1 0 0 1 1-1h1v-1a1 1 0 1 1 2 0v1h1a1 1 0 1 1 0 2h-1v1a1 1 0 1 1-2 0v-1h-1a1 1 0 0 1-1-1Z" clip-rule="evenodd"/>
</svg>
</div>
    
<div class="option-btn settings" id="logoutBtn" title="Log out"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="currentColor" class="bi bi-box-arrow-right" viewBox="0 0 16 16">
  <path  d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/>
  <path   d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/>
</svg>
</div>
    
  </div>
</div>



<script>
const logoooBtn = document.getElementById("logoooBtn");
const menuContainer = document.getElementById("menuContainer");
const optionButtons = document.querySelectorAll(".option-btn");

logoooBtn.addEventListener("click", () => {
  menuContainer.classList.toggle("active");

  if (menuContainer.classList.contains("active")) {
    const gap = 55; // space between buttons horizontally
    optionButtons.forEach((btn, index) => {
      const x = (index + 1) * gap;
      btn.style.transform = `translate(-${x}px, -50%) scale(1)`; // slide left
    });
  } else {
    optionButtons.forEach(btn => {
      btn.style.transform = "translateY(-50%) scale(0)";
    });
  }
});
</script>
 
<div id="reportModal" class="reportmodal">
  <div class="reportmodal-content">
    <div class="reportmodal-header">
      <a href="export_report.php" target="_blank" class="download-btn" title="Download Report">
        <svg class="w-[20px] h-[20px] text-gray-800" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" 
             width="24" height="24" fill="none" viewBox="0 0 24 24">
          <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
        </svg>
      </a>
      <span class="closebtn" onclick="closereportModal()">&times;</span>
      
    </div>
    <h2>📊 Sales Report Summary</h2>
    <div class="report-text">
      <?php foreach ($report as $line): ?>
        <p><?= $line ?></p>
      <?php endforeach; ?>
    </div>

    <h3>🛒 Recent Sales (Last 5 Orders)</h3>
    <table>
      <tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (₱)</th></tr>
      <?php foreach ($recentSales as $sale): ?>
        <tr>
          <td><?= $sale['date'] ?></td>
          <td><?= htmlspecialchars($sale['Customer_Name']) ?></td>
          <td><?= htmlspecialchars($sale['Product_Name']) ?></td>
          <td><?= $sale['Quantity'] ?></td>
          <td>₱<?= number_format($sale['Subtotal'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h3>🏆 Top 3 Products (From Recent Sales)</h3>
    <table>
      <tr><th>Product</th><th>Quantity Sold</th></tr>
      <?php foreach ($topRecentProducts as $prod): ?>
        <tr>
          <td><?= htmlspecialchars($prod['Product_Name']) ?></td>
          <td><?= $prod['qty'] ?> pcs</td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<script>
function openreportModal() {
  document.getElementById("reportModal").style.display = "flex";
}
function closereportModal() {
  document.getElementById("reportModal").style.display = "none";
}
window.onclick = function(event) {
  if (event.target === document.getElementById("reportModal")) {
    closereportModal();
  }
}
</script>


<div id="profileModal" class="profilemodal">
  
  <div class="profilemodal-content">
    <div class="profilemodal-left">
       <img class="profile-logo" src="<?= htmlspecialchars($user['Profile_Picture'] ? $user['Profile_Picture'] : 'msclogo.jpg') ?>" alt="Profile Picture">
    </div>
    <div class="profilemodal-right">
      <h3><?= htmlspecialchars($user['FName'] . ' ' . $user['LName']) ?></h3>
      <p><strong>Username:</strong> <?= htmlspecialchars($user['Username']) ?></p>
      <p><strong>Email:</strong><?= htmlspecialchars($user['Email']) ?></p>
      
      <div class="profilemodal-actions">
       <button class="profileedit-btn" onclick="switchToEdit()">Edit</button> 
       <button class="profileclose-btn" onclick="closeProfileModal('profileModal')">Close</button>
        
      </div>
    </div>
  </div>
</div>


<?php echo $message; ?>
<div class="editModal-overlay" id="editProfileModalOverlay">
  
  <div class="editmodal">
    <div class="editmodal-title">Edit Profile</div>
    <?php if (!empty($error_msg)): ?>
      <div class="profile-error" style="color:#ec3462;font-weight:bold;margin-bottom:16px;">
        <?= htmlspecialchars($error_msg) ?>
      </div>
      <script>
        // Show modal if error
        document.addEventListener('DOMContentLoaded', function() {
          openEditModal();
        });
      </script>
    <?php endif; ?>
    <form class="profile-form" method="post" enctype="multipart/form-data">
      <div class="profile-form-pic">
        <img src="<?= htmlspecialchars($user['Profile_Picture'] ? $user['Profile_Picture'] : 'msclogo.jpg') ?>" alt="Profile Picture Preview" id="profilePicPreview">
        <input type="file" name="profile_pic" accept="image/*" onchange="previewProfilePic(event)">
      </div>
      <div class="profile-form-row">
        <div class="profile-form-group">
          <label class="profile-form-label">First Name</label>
          <input class="profile-form-input" type="text" name="fname" value="<?= htmlspecialchars($user['FName']) ?>" required>
        </div>
        <div class="profile-form-group">
          <label class="profile-form-label">Last Name</label>
          <input class="profile-form-input" type="text" name="lname" value="<?= htmlspecialchars($user['LName']) ?>" required>
        </div>
      </div>
      <div class="profile-form-row">
        <div class="profile-form-group">
          <label class="profile-form-label">Username</label>
          <input class="profile-form-input" type="text" name="username" value="<?= htmlspecialchars($user['Username']) ?>" required>
        </div>
        <div class="profile-form-group">
          <label class="profile-form-label">Email</label>
          <input class="profile-form-input" type="email" name="email" value="<?= htmlspecialchars($user['Email']) ?>" required>
        </div>
      </div>
      <div style="display:flex;gap:18px;justify-content:center;margin-top:18px;">
        <button class="editmodal-btn editconfirm" type="submit" name="save">Save Changes</button>
        <button class="editmodal-btn editcancel" type="button" onclick="closeProfileModal('editProfileModalOverlay')">Cancel</button>
      </div>
    </form>
  </div>
</div>



<script>
function switchToEdit() {
  closeProfileModal('profileModal');
  openProfileModal('editProfileModalOverlay');
}

function openProfileModal(id) {
  document.getElementById(id).style.display = 'flex';
}

function closeProfileModal(id) {
  document.getElementById(id).style.display = 'none';
}

// Close if click outside
window.onclick = function(event) {
  const modal = document.getElementById("profileModal");
  if (event.target === modal) {
    closeProfileModal('profileModal');
  }
}

function previewProfilePic(event) {
      const reader = new FileReader();
      reader.onload = function(){
        document.getElementById('profilePicPreview').src = reader.result;
      };
      reader.readAsDataURL(event.target.files[0]);
    }
</script>






<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <div class="modal-title">Are you sure you want to log out?</div>
        <div class="modal-btns">
            <button class="modal-btn confirm" id="confirmLogout">Log Out</button>
            <button class="modal-btn cancel" id="cancelLogout">Cancel</button>
        </div>
    </div>
</div>
<script>
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
</body>
</html>
<?php include 'loadingscreen.html'; ?>








