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
if (!$userRow || $userRow['UserType'] !== 'staff') {
    header('Location: index.php');
    exit;
}

// Fetch only the current staff member's sales, grouped by Order_Code
$sales = $conn->query("SELECT s.Order_Code, s.Customer_Name, s.Payment_Method, s.User_ID, u.FName, u.LName, GROUP_CONCAT(CONCAT(s.Quantity, ' ', p.Product_Name) SEPARATOR ', ') as Products, SUM(s.Quantity * s.Unit_Price) as TotalAmount FROM sales s JOIN product p ON s.Product_ID = p.Product_ID LEFT JOIN user u ON s.User_ID = u.User_ID WHERE s.User_ID = $user_id GROUP BY s.Order_Code, s.Customer_Name, s.Payment_Method, s.User_ID ORDER BY s.Order_Code DESC");

// Handle delete action (staff can only delete their own orders)
if (isset($_GET['delete'])) {
    $orderCode = $conn->real_escape_string($_GET['delete']);
    // Ensure staff can only delete their own orders
    $conn->query("DELETE FROM sales WHERE Order_Code = '$orderCode' AND User_ID = $user_id");
    header('Location: staff_saleshistory.php');
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
  <title>Staff Sales History</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      background: #dedede;
      margin: 0;
      padding: 0;
      font-family: 'Arial', sans-serif;
    }
    .container {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      width: 100vw;
      height: 100vh;
      margin: 0;
      background: #f7bfc3;
      border-radius: 0;
      min-height: 100vh;
      display: flex;
      box-shadow: none;
      overflow: hidden;
    }
    .sidebar {
      width: 80px;
      background: #ff7e94;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 0 16px 0;
      border-radius: 8px 0 0 8px;
    }
    .sidebar .logo {
      width: 56px;
      height: 56px;
      margin-bottom: 32px;
      border-radius: 50%;
      background: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }
    .sidebar .logo img {
      width: 60px;
      height: 60px;
      object-fit: contain;
    }
    .nav {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 24px;
      align-items: center;
    }
    .nav-icon {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
      position: relative;
    }
    .nav-icon.active, .nav-icon:hover {
      background: #fff;
      color: #ec3462;
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
    .main-content {
      flex: 1;
      padding: 36px 0 0 0;
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
      justify-content: flex-end;
      align-items: center;
      padding: 0 32px 0 0;
      color: #111;
      font-size: 15px;
      margin-bottom: 12px;
    }
    .title {
      font-size: 26px;
      font-weight: bold;
      color: #222;
      margin-left: 48px;
      margin-bottom: 24px;
      margin-top: 0;
    }
    .sales-history-table {
      width: 80%;
      margin-left: 48px;
      margin-top: 0;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.03);
      padding: 24px 24px 12px 24px;
      max-width: 1200px;
    }
    .sales-history-table table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
    }
    .sales-history-table th, .sales-history-table td {
      padding: 10px 8px;
      text-align: left;
      font-size: 15px;
    }
    .sales-history-table th {
      background: #ff7e94;
      color: #fff;
      font-weight: bold;
      border-radius: 6px 6px 0 0;
    }
    .sales-history-table tr:not(:last-child) td {
      border-bottom: 1px solid #f7bfc3;
    }
    .sales-history-table td {
      color: #333;
      vertical-align: top;
    }
    .sales-history-table .menu-cell {
      text-align: right;
      position: relative;
      width: 40px;
    }
    .menu-btn {
      background: none;
      border: none;
      font-size: 22px;
      color: #888;
      cursor: pointer;
      padding: 0 8px;
      border-radius: 4px;
      transition: background 0.15s;
    }
    .menu-btn:hover {
      background: #ffe6ee;
      color: #ec3462;
    }
    .action-modal {
      position: absolute;
      top: 28px;
      right: 0;
      background: #fff;
      border: 1px solid #eee;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      border-radius: 6px;
      z-index: 10;
      min-width: 80px;
      padding: 4px 0;
      display: none;
    }
    .action-modal button {
      width: 100%;
      background: none;
      border: none;
      color: #111;
      font-size: 15px;
      padding: 4px 0;
      cursor: pointer;
      border-radius: 0px;
      transition: background 0.15s;
    }
    .action-modal button:hover {
      background: #ffe6ee;
    }
    .search-filter-row {
      display: flex;
      align-items: center;
      margin-bottom: 18px;
      gap: 16px;
    }
    .search-box {
      flex: 1;
      padding: 8px 12px;
      border-radius: 6px;
      border: 1px solid #ff7e94;
      font-size: 15px;
      outline: none;
      background: #fff;
      color: #333;
    }
    .filter-btn {
      background: #fff;
      border: 1px solid #ff7e94;
      color: #ff7e94;
      border-radius: 6px;
      padding: 8px 16px;
      font-size: 15px;
      cursor: pointer;
      font-weight: bold;
      display: flex;
      align-items: center;
      gap: 6px;
      transition: background 0.15s, color 0.15s;
    }
    .filter-btn:hover {
      background: #ff7e94;
      color: #fff;
    }
    .filter-option:hover {
      background: #f0f0f0;
    }
    .no-data {
      text-align: center;
      padding: 40px 20px;
      color: #666;
      font-style: italic;
    }
    /* Modal styles */
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
    @media (max-width: 900px) {
      .container {
        flex-direction: column;
        width: 100vw;
        min-width: 0;
        margin: 0;
        border-radius: 0;
      }
      .main-content {
        padding: 18px 0 0 0;
      }
      .title, .sales-history-table {
        margin-left: 12px;
      }
      .topbar {
        padding-right: 12px;
      }
      .sales-history-table {
        width: 98%;
        max-width: 100vw;
      }
    }
    .fixed-buttonss {
  position: fixed;
  bottom: 20px;
  right: 40px;
  display: flex;
  align-items: center;
  padding: 12px 20px;
  background-color: #f77a8f;
  color: white;
  border: none;
  border-radius: 8px;
  font-weight: bold;
  cursor: pointer;
  box-shadow: 0 4px 8px rgba(0,0,0,0.2);
  z-index: 999;
  overflow: hidden;
  white-space: nowrap;
  transition: background-color 0.3s ease;
}

.fixed-buttonss:hover {
  background-color: #e7647a;
}

.fixed-buttonss .label {
  margin-left: 8px;
  max-width: 0;
  opacity: 0;
  transform: translateX(-10px);
  transition: 
    opacity 0.3s ease,
    max-width 0.3s ease,
    transform 0.3s ease;
  display: inline-block;
  overflow: hidden;
  white-space: nowrap;
}

.fixed-buttonss:hover .label {
  opacity: 1;
  max-width: 100px;
  transform: translateX(0);
}
    .main-btn {
      font-size: 18px;
      padding: 12px 24px;
      background-color: #f77a8f;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
      transition: background-color 0.3s, transform 0.2s;
    }

    .main-btn:hover {
      background-color: #e7647a;
      transform: scale(1.05);
    }

    /* Modal Overlay */
    .modalss {
   display: none; /* Keep all modals hidden until triggered */
      position: fixed;
      z-index: 999;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      animation: fadeIn 0.3s ease;
    }

    /* Modal Box */
    .modalss-content {
      background: white;
      padding: 30px 25px;
      margin: 10% auto;
      width: 90%;
      max-width: 400px;
      border-radius: 12px;
      text-align: center;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.25);
      animation: popIn 0.3s ease forwards;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes popIn {
      from {
        transform: scale(0.9);
        opacity: 0;
      }
      to {
        transform: scale(1);
        opacity: 1;
      }
    }

    h3 {
      margin-top: 0;
      font-size: 24px;
      color: #333;
    }

    .modalss-buttons {
      margin-top: 20px;
    }

    .modalss-buttons button {
      padding: 10px 20px;
      margin: 10px 8px 0 8px;
      font-size: 16px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      background-color: #f77a8f;
      color: white;
      transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .modalss-buttons button:hover {
      background-color: #e7647a;
      transform: scale(1.05);
    }

    /* Optional close button */
    .closed-buttonss {
      background-color: #ccc;
      color: #333;
    }

    .closed-buttonss:hover {
      background-color: #aaa;
    }
      .imp {
      padding: 8px 16px;
      background-color: #28a745;
      color: white;
      border: none;
      border-radius: 4px;
      font-weight: bold;
      cursor: pointer;
    }
    .imp:disabled {
      background-color: #ccc;
      cursor: not-allowed;
    }
    #importForm, #importBtn {
  position: relative;
  z-index: 9999;
}
#importBtn:hover {
  outline: 3px solid red;
}
  
  </style>
</head>
<body>
<div class="container">
  <div class="sidebar">
    <div class="logo">
      <img src="msclogo.jpg" alt="MSC Cookies Logo">
    </div>
    <div class="nav">
      <div class="nav-icon" title="Home" onclick="window.location.href='staff_dashboard.php'">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12L12 4l9 8"/><path d="M9 21V9h6v12"/></svg>
      </div>
      <div class="nav-icon" title="Visualization">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 2v10h10"/></svg>
      </div>
      <div class="nav-icon" title="Notifications" onclick="window.location.href='staff_notifications.php'">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      </div>
      <div class="nav-icon active" title="Sales">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="4 14 8 10 12 14 16 10 20 14"/><line x1="4" y1="20" x2="20" y2="20"/></svg>
      </div>
      <div class="nav-icon" title="Settings" onclick="window.location.href='staff_settings.php'">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </div>
      <div class="nav-icon" title="People" onclick="window.location.href='staff_profile.php'">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 0 1 13 0"/></svg>
      </div>
    </div>
    <button class="logout-btn" id="logoutBtn" title="Logout">
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7"/><path d="M3 21V3"/></svg>
    </button>
  </div>
  <div class="main-content">
    <div class="topbar">
      <div style="padding-right: 48px;">
        <?php echo formatDay(date('Y-m-d')); ?>
      </div>
    </div>
    <div class="title">My Sales History</div>
    <div class="sales-history-table">
      <div class="search-filter-row">
        <input class="search-box" id="searchBox" type="text" placeholder="Search my transactions...">
        <div style="position:relative;">
          <button class="filter-btn" id="filterBtn" type="button">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            Filter Order
          </button>
          <div id="filterMenu" style="display:none;position:absolute;top:38px;right:0;background:#fff;border:1px solid #eee;box-shadow:0 2px 8px rgba(0,0,0,0.08);border-radius:6px;z-index:20;min-width:140px;">
            <div class="filter-option" data-col="0" style="padding:8px 16px;cursor:pointer;">Order ID</div>
            <div class="filter-option" data-col="1" style="padding:8px 16px;cursor:pointer;">Customer Name</div>
            <div class="filter-option" data-col="3" style="padding:8px 16px;cursor:pointer;">Products</div>
            <div class="filter-option" data-col="4" style="padding:8px 16px;cursor:pointer;">Total Amount</div>
            <div class="filter-option" data-col="5" style="padding:8px 16px;cursor:pointer;">Payment Type</div>
          </div>
        </div>
      </div>
      <table id="salesTable">
        <thead>
          <tr>
            <th>ORDER ID</th>
            <th>CUSTOMER NAME</th>
            <th>PRODUCT/S ORDERED</th>
            <th>TOTAL AMOUNT</th>
            <th>PAYMENT TYPE</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php 
        $rownum = 0; 
        if ($sales->num_rows > 0):
            while ($row = $sales->fetch_assoc()): 
                $rownum++; 
        ?>
        <tr>
          <td><?= htmlspecialchars($row['Order_Code']) ?></td>
          <td><?= htmlspecialchars($row['Customer_Name']) ?></td>
          <td><?= htmlspecialchars($row['Products']) ?></td>
          <td><?= formatPeso($row['TotalAmount']) ?></td>
          <td><?= htmlspecialchars($row['Payment_Method']) ?></td>
          <td class="menu-cell">
            <button class="menu-btn" onclick="showActionMenu(event, <?= $rownum ?>)">&#8942;</button>
            <div class="action-modal" id="action-menu-<?= $rownum ?>">
              <form method="get" style="margin:0;">
                <input type="hidden" name="delete" value="<?= htmlspecialchars($row['Order_Code']) ?>">
                <button type="submit" onclick="return confirm('Are you sure you want to delete this order?')">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php 
            endwhile; 
        else:
        ?>
        <tr>
          <td colspan="6" class="no-data">No sales transactions found. Start making sales to see your history here!</td>
        </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <button class="fixed-buttonss"  onclick="openModal('modal1')">
  <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12V7.914a1 1 0 0 1 .293-.707l3.914-3.914A1 1 0 0 1 9.914 3H18a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-4m5-13v4a1 1 0 0 1-1 1H5m0 6h9m0 0-2-2m2 2-2 2"/>
</svg>
<span class="label" >Import Data</span>
</button>

<!-- First Modal -->
<div id="modal1" class="modalss">
  <div class="modalss-content">
    <h3>Choose an Option</h3>
    <div class="modalss-buttons">
      <button onclick="openModal('modal2'); closeModal('modal1')"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path fill-rule="evenodd" d="M9 2.221V7H4.221a2 2 0 0 1 .365-.5L8.5 2.586A2 2 0 0 1 9 2.22ZM11 2v5a2 2 0 0 1-2 2H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2 2 2 0 0 0 2 2h12a2 2 0 0 0 2-2 2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2V4a2 2 0 0 0-2-2h-7Zm1.018 8.828a2.34 2.34 0 0 0-2.373 2.13v.008a2.32 2.32 0 0 0 2.06 2.497l.535.059a.993.993 0 0 0 .136.006.272.272 0 0 1 .263.367l-.008.02a.377.377 0 0 1-.018.044.49.49 0 0 1-.078.02 1.689 1.689 0 0 1-.297.021h-1.13a1 1 0 1 0 0 2h1.13c.417 0 .892-.05 1.324-.279.47-.248.78-.648.953-1.134a2.272 2.272 0 0 0-2.115-3.06l-.478-.052a.32.32 0 0 1-.285-.341.34.34 0 0 1 .344-.306l.94.02a1 1 0 1 0 .043-2l-.943-.02h-.003Zm7.933 1.482a1 1 0 1 0-1.902-.62l-.57 1.747-.522-1.726a1 1 0 0 0-1.914.578l1.443 4.773a1 1 0 0 0 1.908.021l1.557-4.773Zm-13.762.88a.647.647 0 0 1 .458-.19h1.018a1 1 0 1 0 0-2H6.647A2.647 2.647 0 0 0 4 13.647v1.706A2.647 2.647 0 0 0 6.647 18h1.018a1 1 0 1 0 0-2H6.647A.647.647 0 0 1 6 15.353v-1.706c0-.172.068-.336.19-.457Z" clip-rule="evenodd"/>
</svg> .csv file (cleaned data)
</button>
<a href="upload.php">
  <button><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path fill-rule="evenodd" d="M9 7V2.221a2 2 0 0 0-.5.365L4.586 6.5a2 2 0 0 0-.365.5H9Zm2 0V2h7a2 2 0 0 1 2 2v9.293l-2-2a1 1 0 0 0-1.414 1.414l.293.293h-6.586a1 1 0 1 0 0 2h6.586l-.293.293A1 1 0 0 0 18 16.707l2-2V20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V9h5a2 2 0 0 0 2-2Z" clip-rule="evenodd"/>
</svg> excel file from JotForm
</button>
</a>
      
      <br>
      <br>
      <button class="closed-buttonss" onclick="closeModal('modal1')">Close</button>
    </div>
  </div>
</div>

<!-- Second Modal -->
<div id="modal2" class="modalss">
  <div class="modalss-content">
    <h2>Upload Sales Data (Cleaned and Preprocessed Data)</h2> 
    <form action="import.php" method="post" enctype="multipart/form-data">
    <input type="file" name="csv_file" accept=".csv" required><br><br>
    <button type="submit" name="import">Import</button>
    <div class="modalss-buttons">
      <button class="closed-buttonss" onclick="closeModal('modal2')">Close</button>
    </div>
  </div>
</div>

<!-- Third Modal -->
<div id="modal3" class="modalss">
  <div class="modalss-content">
    <h3>Upload Sales Data from JotForm</h3>
  <form action="jotform.php" method="post" enctype="multipart/form-data">
    <input type="file" name="csv_file" accept=".csv" required><br><br>
    <button type="submit" name="import">Import</button>
</form>
    <div class="modal1-buttons">
      <button class="closed-buttonss" onclick="closeModal('modal3')">Close</button>
    </div>
  </div>
</div>

<script>
  function openModal(id) {
    document.getElementById(id).style.display = "block";
  }

  function closeModal(id) {
    document.getElementById(id).style.display = "none";
  }
</script>
</div>

<!-- Logout Modal -->
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
  // Logout modal logic
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
  
  // Show/hide action modal
  let lastMenu = null;
  function showActionMenu(e, id) {
    e.stopPropagation();
    if (lastMenu && lastMenu !== id) {
      const prev = document.getElementById('action-menu-' + lastMenu);
      if (prev) prev.style.display = 'none';
    }
    const menu = document.getElementById('action-menu-' + id);
    if (menu) {
      menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
      lastMenu = id;
    }
  }
  
  document.addEventListener('click', function() {
    if (lastMenu) {
      const menu = document.getElementById('action-menu-' + lastMenu);
      if (menu) menu.style.display = 'none';
      lastMenu = null;
    }
    if (filterMenuOpen) {
      filterMenu.style.display = 'none';
      filterMenuOpen = false;
    }
  });

  // --- Search and Filter Logic ---
  const searchBox = document.getElementById('searchBox');
  const salesTable = document.getElementById('salesTable');
  searchBox.addEventListener('input', function() {
    const val = this.value.toLowerCase();
    const rows = salesTable.querySelectorAll('tbody tr');
    rows.forEach(row => {
      let text = row.textContent.toLowerCase();
      row.style.display = text.includes(val) ? '' : 'none';
    });
  });

  // Filter (Sort) Logic
  const filterBtn = document.getElementById('filterBtn');
  const filterMenu = document.getElementById('filterMenu');
  let filterMenuOpen = false;
  filterBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    filterMenu.style.display = filterMenuOpen ? 'none' : 'block';
    filterMenuOpen = !filterMenuOpen;
  });
  
  filterMenu.querySelectorAll('.filter-option').forEach(opt => {
    opt.addEventListener('click', function(e) {
      const colIdx = parseInt(this.getAttribute('data-col'));
      sortTableByColumn(salesTable, colIdx);
      filterMenu.style.display = 'none';
      filterMenuOpen = false;
    });
  });
  
  function sortTableByColumn(table, colIdx) {
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const isNumeric = colIdx === 0 || colIdx === 3; // Order ID and Total Amount are numeric
    rows.sort((a, b) => {
      let ta = a.children[colIdx].textContent.trim();
      let tb = b.children[colIdx].textContent.trim();
      if (isNumeric) {
        ta = ta.replace(/[^\d.]/g, '');
        tb = tb.replace(/[^\d.]/g, '');
        return Number(ta) - Number(tb);
      } else {
        return ta.localeCompare(tb);
      }
    });
    rows.forEach(row => tbody.appendChild(row));
  }
</script>
</body>
</html> 