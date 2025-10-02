<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
$user_id = $_SESSION['user_id'];
$userResult = $conn->query("SELECT UserType FROM User WHERE User_ID = $user_id");
$userRow = $userResult ? $userResult->fetch_assoc() : null;
if (!$userRow || $userRow['UserType'] !== 'admin') {
    header('Location: index.php');
    exit;
}

// Get time filter from URL parameter
$timeFilter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'all';
$customYear = isset($_GET['custom_year']) ? $_GET['custom_year'] : '';
$customMonth = isset($_GET['custom_month']) ? $_GET['custom_month'] : '';
$customDate = isset($_GET['custom_date']) ? $_GET['custom_date'] : '';

// Build WHERE clause based on filter
$whereClause = "WHERE 1=1";
$params = [];
$types = "";

switch ($timeFilter) {
    case 'today':
        $whereClause .= " AND DATE(Sales_Date) = CURDATE()";
        break;
    case 'week':
        $whereClause .= " AND YEARWEEK(Sales_Date) = YEARWEEK(CURDATE())";
        break;
    case 'month':
        $whereClause .= " AND YEAR(Sales_Date) = YEAR(CURDATE()) AND MONTH(Sales_Date) = MONTH(CURDATE())";
        break;
    case 'year':
        $whereClause .= " AND YEAR(Sales_Date) = YEAR(CURDATE())";
        break;
    case 'custom_year':
        if (!empty($customYear)) {
            $whereClause .= " AND YEAR(Sales_Date) = ?";
            $params[] = $customYear;
            $types .= "i";
        }
        break;
    case 'custom_month':
        if (!empty($customYear) && !empty($customMonth)) {
            $whereClause .= " AND YEAR(Sales_Date) = ? AND MONTH(Sales_Date) = ?";
            $params[] = $customYear;
            $params[] = $customMonth;
            $types .= "ii";
        }
        break;
    case 'custom_date':
        if (!empty($customDate)) {
            $whereClause .= " AND DATE(Sales_Date) = ?";
            $params[] = $customDate;
            $types .= "s";
        }
        break;
}

// Fetch sales data grouped by Order_Code
$query = "SELECT s.Order_Code, s.Customer_Name, s.Payment_Method, s.Sales_Date, s.User_ID, u.FName, u.LName, 
          GROUP_CONCAT(CONCAT(s.Quantity, ' ', p.Product_Name) SEPARATOR ', ') as Products, 
          SUM(s.Quantity * s.Unit_Price) as TotalAmount 
          FROM sales s 
          JOIN product p ON s.Product_ID = p.Product_ID 
          LEFT JOIN user u ON s.User_ID = u.User_ID 
          $whereClause 
          GROUP BY s.Order_Code, s.Customer_Name, s.Payment_Method, s.Sales_Date, s.User_ID 
          ORDER BY s.Order_Code DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($query);
}

$sales = $result->fetch_all(MYSQLI_ASSOC);

// Handle delete action
if (isset($_GET['delete'])) {
    $orderCode = $conn->real_escape_string($_GET['delete']);
    $conn->query("DELETE FROM sales WHERE Order_Code = '$orderCode'");
    header('Location: sales_history.php');
    exit;
}

function formatPeso($amt) {
    return '₱ ' . number_format($amt, 2);
}

function getSellerName($userId, $fname, $lname) {
    if ($userId == 1) return 'admin';
    return trim($fname . ' ' . $lname);
}

function formatDay($dt) {
    return date('l, F j, Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Sales History - MSC Cookies</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root {
        --sidebar-bg: #ff7e94;
        --main-bg: #f7bfc3;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: Arial, sans-serif;
        background: var(--main-bg);
        overflow-x: hidden;
    }
    
    .container {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100vw;
        height: 100vh;
        margin: 0;
        background: var(--main-bg);
        display: flex;
        overflow: hidden;
    }
    
    .sidebar {
        width: 80px;
        height: 100vh;
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
        padding: 36px 0 0 80px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        width: 100%;
        min-height: 100vh;
        height: 100vh;
        overflow-y: auto;
    }
    
    .topbar {
        width: 100%;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 48px 24px 48px;
    }
    
    .topbar h1 {
        font-size: 32px;
        color: #333;
        margin: 0;
    }
    
    .filter-container {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .filter-container select {
        padding: 8px 12px;
        border: 2px solid #ff7e94;
        border-radius: 6px;
        background: white;
        color: #333;
        font-size: 14px;
        cursor: pointer;
    }

    .filter-container button {
        padding: 8px 16px;
        background-color: #ff7e94;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-weight: 500;
        transition: background 0.3s;
    }

    .filter-container button:hover {
        background-color: #e66b7d;
    }
    
    .content-area {
        width: 100%;
        padding: 0 48px 48px 48px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        background-color: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    th, td {
        padding: 16px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }

    th {
        background-color: #ff7e94;
        color: white;
        font-weight: 600;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    tbody tr:hover {
        background-color: #fef5f7;
    }

    .delete-btn {
      background-color: #dc3545;
      color: white;
      border: none;
      padding: 5px 10px;
      border-radius: 3px;
      cursor: pointer;
      font-size: 12px;
    }

    .delete-btn:hover {
      background-color: #c82333;
    }

    .no-sales {
      text-align: center;
      padding: 40px;
      color: #666;
      font-style: italic;
    }

    .custom-date-modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
    }

    .custom-date-content {
      background-color: white;
      margin: 15% auto;
      padding: 20px;
      border-radius: 8px;
      width: 400px;
      max-width: 90%;
    }

    .custom-date-close {
      color: #aaa;
      float: right;
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
    }

    .custom-date-close:hover {
      color: #000;
    }

    .date-selector-container {
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .date-type-selector {
      display: flex;
      gap: 20px;
    }

    .date-type-selector label {
      display: flex;
      align-items: center;
      gap: 5px;
      cursor: pointer;
    }

    .date-input-group {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .date-input-group label {
      font-weight: bold;
    }

    .date-input-group input, .date-input-group select {
      padding: 8px;
      border: 1px solid #ddd;
      border-radius: 4px;
    }

    .modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 20px;
    }

    .cancel-btn, .apply-date-btn {
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-weight: bold;
    }

    .cancel-btn {
      background: #ccc;
      color: #333;
    }

    .apply-date-btn {
      background: #ff7e94;
      color: white;
    }
    
    .no-sales {
        text-align: center;
        padding: 40px;
        color: #999;
        font-style: italic;
    }
    
    .delete-btn {
        background-color: #dc3545;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        transition: background 0.3s;
    }
    
    .delete-btn:hover {
        background-color: #c82333;
    }
  </style>
</head>
<body>
<div class="container">
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

            <div class="nav-icon active" title="Sales" onclick="window.location.href='sales_history.php'">
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

    <div class="main-content">
        <div class="topbar">
            <h1>Sales History</h1>
            <div class="filter-container">
                <select onchange="filterByTime(this.value)">
                    <option value="all" <?= $timeFilter == 'all' ? 'selected' : '' ?>>All Time</option>
                    <option value="today" <?= $timeFilter == 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="week" <?= $timeFilter == 'week' ? 'selected' : '' ?>>This Week</option>
                    <option value="month" <?= $timeFilter == 'month' ? 'selected' : '' ?>>This Month</option>
                    <option value="year" <?= $timeFilter == 'year' ? 'selected' : '' ?>>This Year</option>
                </select>
                <button onclick="openCustomDateModal()">Custom Date Range</button>
            </div>
        </div>

        <div class="content-area">
            <table>
            <thead>
                <tr>
                    <th>Order Code</th>
                    <th>Customer Name</th>
                    <th>Products</th>
                    <th>Total Amount</th>
                    <th>Payment Method</th>
                    <th>Date</th>
                    <th>Seller</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                    <tr>
                        <td colspan="8" class="no-sales">No sales records found for the selected period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($sales as $sale): ?>
                        <tr>
                            <td><?= htmlspecialchars($sale['Order_Code']) ?></td>
                            <td><?= htmlspecialchars($sale['Customer_Name']) ?></td>
                            <td><?= htmlspecialchars($sale['Products']) ?></td>
                            <td><?= formatPeso($sale['TotalAmount']) ?></td>
                            <td><?= htmlspecialchars($sale['Payment_Method']) ?></td>
                            <td><?= formatDay($sale['Sales_Date']) ?></td>
                            <td><?= getSellerName($sale['User_ID'], $sale['FName'], $sale['LName']) ?></td>
                            <td>
                                <button class="delete-btn" onclick="deleteSale('<?= $sale['Order_Code'] ?>')">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Custom Date Modal -->
<div id="customDateModal" class="custom-date-modal">
    <div class="custom-date-content">
        <span class="custom-date-close" onclick="closeCustomDateModal()">&times;</span>
        <h3>Select Custom Date Range</h3>
        <div class="date-selector-container">
            <div class="date-type-selector">
                <label><input type="radio" name="dateType" value="year" checked> Year</label>
                <label><input type="radio" name="dateType" value="month"> Month</label>
                <label><input type="radio" name="dateType" value="date"> Specific Date</label>
            </div>
            
            <div class="date-input-group">
                <label>Year:</label>
                <select id="customYear">
                    <?php for ($year = date('Y'); $year >= 2020; $year--): ?>
                        <option value="<?= $year ?>"><?= $year ?></option>
                    <?php endfor; ?>
                </select>
                
                <label id="monthLabel" style="display:none;">Month:</label>
                <select id="customMonth" style="display:none;">
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
                
                <label id="dateLabel" style="display:none;">Date:</label>
                <input type="date" id="customDate" style="display:none;">
            </div>
        </div>
        
        <div class="modal-actions">
            <button class="cancel-btn" onclick="closeCustomDateModal()">Cancel</button>
            <button class="apply-date-btn" onclick="applyCustomDate()">Apply</button>
        </div>
    </div>
</div>

<script>
function filterByTime(filter) {
    window.location.href = 'sales_history.php?time_filter=' + filter;
}

function deleteSale(orderCode) {
    if (confirm('Are you sure you want to delete this sale record?')) {
        window.location.href = 'sales_history.php?delete=' + orderCode;
    }
}

function openCustomDateModal() {
    document.getElementById('customDateModal').style.display = 'block';
}

function closeCustomDateModal() {
    document.getElementById('customDateModal').style.display = 'none';
}

function applyCustomDate() {
    const dateType = document.querySelector('input[name="dateType"]:checked').value;
    const year = document.getElementById('customYear').value;
    const month = document.getElementById('customMonth').value;
    const date = document.getElementById('customDate').value;
    
    let url = 'sales_history.php?time_filter=custom_' + dateType;
    
    if (dateType === 'year') {
        url += '&custom_year=' + year;
    } else if (dateType === 'month') {
        url += '&custom_year=' + year + '&custom_month=' + month;
    } else if (dateType === 'date') {
        url += '&custom_date=' + date;
    }
    
    window.location.href = url;
}

// Handle date type radio button changes
document.querySelectorAll('input[name="dateType"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const monthLabel = document.getElementById('monthLabel');
        const monthSelect = document.getElementById('customMonth');
        const dateLabel = document.getElementById('dateLabel');
        const dateInput = document.getElementById('customDate');
        
        if (this.value === 'month') {
            monthLabel.style.display = 'block';
            monthSelect.style.display = 'block';
            dateLabel.style.display = 'none';
            dateInput.style.display = 'none';
        } else if (this.value === 'date') {
            monthLabel.style.display = 'none';
            monthSelect.style.display = 'none';
            dateLabel.style.display = 'block';
            dateInput.style.display = 'block';
        } else {
            monthLabel.style.display = 'none';
            monthSelect.style.display = 'none';
            dateLabel.style.display = 'none';
            dateInput.style.display = 'none';
        }
    });
});
</script>

</body>
</html>
