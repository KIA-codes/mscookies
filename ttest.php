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
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Descriptive Dashboard</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .dashboard-container {
      position: relative;
      width: 100%;
      overflow: hidden;
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 10px;
      
    }

    .tab-bar {
      display: flex;
      justify-content: center;
      gap: 20px;
      padding: 10px;
      border-bottom: 1px solid #ddd;
      background: #FF7E94;
    }

    .tab {
      padding: 6px 15px;
      cursor: pointer;
      border-bottom: 2px solid transparent;
    }
    .tab.active {
      border-bottom: 2px solid #007bff;
      color: #007bff;
      font-weight: bold;
    }

    .slider {
      display: flex;
      transition: transform 0.5s ease;
      width: 400%; /* 4 slides */
    }

    .slide {
      width: 100%;
      flex-shrink: 0;
      padding: 20px;
    }

    .slide-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: white;
      border: 1px solid #ccc;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      cursor: pointer;
      z-index: 10;
    }
    .slide-btn.left { left: 10px; }
    .slide-btn.right { right: 10px; }
  </style>
</head>
<body>

<!-- === Tab Bar (auto updates) === -->
<div class="tab-bar">
  <div class="tab active">Descriptive</div>
  <div class="tab">Predictive</div>
  <div class="tab">Regression</div>
  <div class="tab">Other</div>
</div>

<div class="dashboard-container">
  <!-- Slide Controls -->
  <button class="slide-btn left">←</button>
  <button class="slide-btn right">→</button>

  <!-- Slider Wrapper -->
  <div class="slider">
    <div class="slide" id="descriptive">
      <h2>Descriptive Dashboard</h2>
      <canvas id="descriptiveChart"></canvas>
    </div>

    <div class="slide" id="predictive">
      <h2>Predictive Dashboard</h2>
      <canvas id="forecastChart"></canvas>
    </div>

    <div class="slide" id="regression">
      <h2>Regression Dashboard</h2>
      <canvas id="regressionChart"></canvas>
    </div>

    <div class="slide" id="other">
      <h2>Other Dashboard</h2>
      <p>More content here…</p>
    </div>
  </div>
</div>

<script>
const slider = document.querySelector(".slider");
const tabs = document.querySelectorAll(".tab");
const slides = document.querySelectorAll(".slide");
let currentIndex = 0;

function updateSlide() {
  slider.style.transform = `translateX(-${currentIndex * 100}%)`;
  tabs.forEach((tab, i) => {
    tab.classList.toggle("active", i === currentIndex);
  });
}

// Buttons
document.querySelector(".slide-btn.left").addEventListener("click", () => {
  currentIndex = (currentIndex - 1 + slides.length) % slides.length;
  updateSlide();
});
document.querySelector(".slide-btn.right").addEventListener("click", () => {
  currentIndex = (currentIndex + 1) % slides.length;
  updateSlide();
});

// Tabs click
tabs.forEach((tab, i) => {
  tab.addEventListener("click", () => {
    currentIndex = i;
    updateSlide();
  });
});

// Init
updateSlide();
</script>

<!-- ===== Your Chart.js scripts below (unchanged) ===== -->


</body>
</html>
