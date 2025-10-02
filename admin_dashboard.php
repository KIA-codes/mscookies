<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");

// Filters
$selected_category = $_GET['category'] ?? 'All';
$selected_subcategory = $_GET['subcategory'] ?? 'All';
$search = $_GET['search'] ?? '';

// Fetch categories & subcategories
$categories = $conn->query("SELECT DISTINCT Category FROM Product WHERE Category IS NOT NULL ORDER BY Category");

// Fetch subcategories based on selected category
if ($selected_category !== 'All') {
    $subcategories = $conn->query("SELECT DISTINCT Subcategory FROM Product WHERE Category = '" . $conn->real_escape_string($selected_category) . "' AND Subcategory IS NOT NULL ORDER BY Subcategory");
} else {
    $subcategories = $conn->query("SELECT DISTINCT Subcategory FROM Product WHERE Subcategory IS NOT NULL ORDER BY Subcategory");
}

// Filter query
$filter = "WHERE 1";
if ($selected_category !== 'All') {
    $filter .= " AND Category = '" . $conn->real_escape_string($selected_category) . "'";
}
if ($selected_subcategory !== 'All') {
    $filter .= " AND Subcategory = '" . $conn->real_escape_string($selected_subcategory) . "'";
}
if (!empty($search)) {
    $filter .= " AND Product_Name LIKE '%" . $conn->real_escape_string($search) . "%'";
}

$products = $conn->query("SELECT * FROM Product $filter ORDER BY Product_ID DESC");

// Check if there are any notifications for the badge
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);

function getToday() {
    return date('l, F j, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - MSC Cookies</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }
        body {
            min-height: 100vh;
            background: var(--main-bg);
            font-family: 'Arial', sans-serif;
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
            z-index: 100;
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
            width: 48px;
            height: 48px;
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
            display: flex;
            flex-direction: row;
            padding: 0;
            gap: 0;
            margin-left: 80px; /* Account for fixed sidebar */
        }
        .main-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 0 0 0 0;
            max-width: calc(100vw - 80px - 350px); /* Account for sidebar and order section */
        }
        .topbar {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            background: transparent;
            padding: 24px 36px 0 36px;
            border-bottom: none;
            position: relative;
        }
        .topbar-title {
            font-size: 22px;
            font-weight: bold;
            color: #e28743;
            margin-bottom: 2px;
        }
        .topbar-date {
            color: #222;
            font-size: 15px;
            margin-bottom: 0;
            margin-left: 2px;
        }
        .topbar-search {
            margin-top: 12px;
            width: 100%;
            max-width: 400px;
            display: flex;
            align-items: center;
            background: #fff;
            border-radius: 24px;
            padding: 0 16px;
        }
        .topbar-search input {
            border: none;
            background: transparent;
            font-size: 15px;
            padding: 10px 8px;
            width: 100%;
            outline: none;
        }
        .topbar-search svg {
            color: #888;
        }
        .filters {
            background: transparent;
            padding: 0 36px;
            border-bottom: none;
        }
        .filter-tabs {
            display: flex;
            gap: 16px;
            margin-top: 18px;
            font-size: 16px;
            font-weight: bold;
            align-items: center;
        }
        .filter-tabs select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 16px;
            background: #fff;
            color: #333;
            cursor: pointer;
            min-width: 150px;
        }
        .subtabs {
            display: flex;
            gap: 16px;
            margin-top: 10px;
            font-size: 15px;
            color: #888;
            align-items: center;
        }
        .subtabs select {
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 16px;
            background: #fff;
            color: #333;
            cursor: pointer;
            min-width: 150px;
        }
        .products-section {
            flex: 1;
            padding: 32px 36px 0 36px;
            overflow-y: auto;
            max-height: calc(100vh - 200px); /* Account for topbar and filters */
        }
        .products-header {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 16px;
            color: var(--text-dark);
        }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 32px;
        }
        .product-card {
            background: var(--card-bg);
            border-radius: 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 24px 12px 18px 12px;
            display: flex;
            flex-direction: column;
            align-items: center;
            aspect-ratio: 1 / 1;
            min-width: 0;
        }
        .product-img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #fff;
            margin-bottom: 14px;
            object-fit: cover;
            border: 3px solid #fff;
        }
        .product-name {
            font-weight: bold;
            margin-bottom: 4px;
            color: #fff;
            text-align: center;
        }
        .product-price {
            color: #fff;
            font-weight: bold;
            margin-bottom: 2px;
            text-align: center;
        }
        .order-section {
            width: 350px;
            height: 100vh;
            background: var(--order-bg);
            border-radius: 16px;
            margin: 0;
            padding: 0 0 0 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            right: 0;
            top: 0;
            color: #fff;
            z-index: 50;
            box-shadow: 0 4px 24px rgba(0,0,0,0.10);
            border: 2px solid #fff2;
        }
        .order-header {
            background: var(--order-header);
            color: #7a263a;
            border-radius: 16px 16px 0 0;
            padding: 18px 24px 8px 24px;
            font-size: 17px;
            font-weight: bold;
            letter-spacing: 0.5px;
        }
        .order-list {
            padding: 0 24px 0 24px;
            flex: 1;
            overflow-y: auto;
            max-height: 340px;
        }
        .order-table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 15px;
            font-weight: bold;
            color: #7a263a;
            padding: 12px 0 8px 0;
            border-bottom: 1px solid #fff5;
        }
        .order-table-header span {
            flex: 1;
            text-align: left;
        }
        .order-table-header .qty {
            flex: 0 0 50px;
            text-align: center;
        }
        .order-table-header .price {
            flex: 0 0 70px;
            text-align: right;
        }
        .order-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 10px 0 6px 0;
            border-bottom: 1px solid #fff2;
        }
        .order-item-img {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 10px;
            border: 2px solid #fff;
        }
        .order-item-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .order-item-name {
            font-weight: bold;
            font-size: 14px;
            color: #fff;
            margin-bottom: 2px;
        }
        .order-item-sub {
            font-size: 12px;
            color: #ffe6ee;
            margin-bottom: 0;
        }
        .order-item-qty {
            width: 38px;
            text-align: center;
            border: 1px solid #fff;
            border-radius: 6px;
            margin: 0 8px;
            background: #fff;
            color: #ec3462;
            font-weight: bold;
            font-size: 15px;
            padding: 4px 0;
        }
        .order-item-price {
            color: #fff;
            font-weight: bold;
            font-size: 15px;
            min-width: 60px;
            text-align: right;
        }
        .order-remove {
            color: #fff;
            cursor: pointer;
            font-size: 18px;
            margin-left: 8px;
            transition: color 0.2s;
        }
        .order-remove:hover {
            color: #ec3462;
        }
        .order-summary {
            padding: 18px 24px 18px 24px;
            border-top: 1px solid #fff3;
            background: var(--order-bg);
        }
        .order-summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 15px;
            color: #fff;
        }
        .order-summary-row.total {
            font-weight: bold;
            font-size: 17px;
        }
        .order-payment {
            margin-top: 10px;
            font-size: 15px;
            color: #fff;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .payment-btn {
            padding: 7px 18px;
            border-radius: 6px;
            border: 1.5px solid #fff5;
            font-size: 15px;
            background: #fff;
            color: #ec3462;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, border 0.2s;
        }
        .payment-btn.active, .payment-btn:focus {
            background: #ec3462;
            color: #fff;
            border: 2px solid #ec3462;
        }
        .order-btn {
            background: #ec3462;
            color: #fff;
            border: none;
            border-radius: 8px;
            width: 100%;
            padding: 14px;
            font-size: 17px;
            font-weight: bold;
            margin-top: 18px;
            cursor: pointer;
            transition: background 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .order-btn:hover {
            background: #c72b52;
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
        @media (max-width: 1100px) {
            .products-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 900px) {
            .main-content {
                flex-direction: column;
                gap: 0;
            }
            .order-section {
                margin: 32px auto 0 auto;
                max-width: 98vw;
            }
            .products-section {
                padding: 0 8px;
            }
            .products-grid {
                grid-template-columns: repeat(1, 1fr);
            }
        }
            svg {
      width: 70;
      height: 70;
      display: block;
      margin: 15px auto;
      transform-origin: center;
    }

    polyline {
      stroke-dasharray: 100;
      stroke-dashoffset: 100;
      transition: stroke-dashoffset 0.6s ease-out 0.2s;
    }

    .draw polyline {
      stroke-dashoffset: 0;
    }

    .bounce {
      animation: bounceIn 0.6s ease-out forwards;
    }

    @keyframes bounceIn {
      0% {
        transform: scale(0.5);
        opacity: 0;
      }
      60% {
        transform: scale(1.2);
        opacity: 1;
      }
      80% {
        transform: scale(0.9);
      }
      100% {
        transform: scale(1);
      }
    }
      .thicker-icon {
    transform: scale(1.1); /* slightly larger */
  }
    </style>
</head>
<body onload="showCookieLoader()">
<div class="dashboard">
    <div class="sidebar">
        <div class="logo">
            <img src="msclogo.jpg" alt="MSC Cookies Logo">
        </div>
        <div class="nav">
           
            <div style="position: relative;">
  <div class="nav-icon" title="Visualization" onclick="toggleDropdown()">
    <!-- Pie chart icon -->
  <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4.5V19a1 1 0 0 0 1 1h15M7 14l4-4 4 4 5-5m0 0h-3.207M20 9v3.207"/>
</svg>

  </div>

  <!-- Dropdown menu -->
  <div id="vizDropdown" style="
    display: none;
    position: absolute;
    top: 40px;
    left: 50%;
    transform: translateX(-50%);
    background: white;
    border-radius: 6px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    overflow: hidden;
    z-index: 999;
    min-width: 140px;
    font-family: Arial, sans-serif;
  ">
    <button onclick="chooseViz('predictive')" style="all:unset; display:block; padding:10px 16px; width:100%; text-align:left; cursor:pointer;">📈 Predictive</button>
    <button onclick="chooseViz('descriptive')" style="all:unset; display:block; padding:10px 16px; width:100%; text-align:left; cursor:pointer;">📊 Descriptive</button>
  </div>
</div>
 <div class="nav-icon active" title="Home">
                <!-- Home icon -->
  <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.65692 9.41494h.01M7.27103 13h.01m7.67737 1.9156h.01M10.9999 17h.01m3.178-10.90671c-.8316.38094-1.8475.22903-2.5322-.45571-.3652-.36522-.5789-.82462-.6409-1.30001-.0574-.44-.0189-.98879.1833-1.39423-1.99351.20001-3.93304 1.06362-5.46025 2.59083-3.51472 3.51472-3.51472 9.21323 0 12.72793 3.51471 3.5147 9.21315 3.5147 12.72795 0 1.5601-1.5602 2.4278-3.5507 2.6028-5.5894-.2108.008-.6725.0223-.8328.0157-.635.0644-1.2926-.1466-1.779-.633-.3566-.3566-.5651-.8051-.6257-1.2692-.0561-.4293.0145-.87193.2117-1.26755-.1159.20735-.2619.40237-.4381.57865-1.0283 1.0282-2.6953 1.0282-3.7235 0-1.0282-1.02824-1.0282-2.69531 0-3.72352.0977-.09777.2013-.18625.3095-.26543"/>
</svg>


            </div>
            <div class="nav-icon" title="Notifications" onclick="window.location.href='notifications.php'" style="position:relative;">
                <!-- Bell icon -->
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($hasNotifications): ?>
                  <span class="notification-badge"></span>
                <?php endif; ?>
            </div>
            <div class="nav-icon" title="Sales" onclick="window.location.href='sales_history.php'">
                <!-- Line graph icon -->
             <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-list-check thicker-icon" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M5 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3.854 2.146a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 3.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 7.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0"/>
</svg>

            </div>
            <div class="nav-icon" title="Settings" onclick="window.location.href='settings.php'">
                <!-- Gear icon -->
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            </div>
            <div class="nav-icon" title="People" onclick="window.location.href='profile.php'">
                <!-- Person icon -->
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 0 1 13 0"/></svg>
            </div>
            <div class="nav-icon" title="Staff Management" onclick="window.location.href='staff_management.php'">
                <!-- Group icon -->
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="7" cy="8" r="3"/><circle cx="17" cy="8" r="3"/><circle cx="12" cy="17" r="3"/><path d="M2 21v-2a4 4 0 0 1 4-4h2m8 0h2a4 4 0 0 1 4 4v2"/></svg>
            </div>
            <div class="nav-icon" title="Add Product" onclick="window.location.href='products_management.php'">
                <!-- Add icon SVG -->
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></svg>
            </div>
        </div>
        <button class="logout-btn" id="logoutBtn" title="Logout">
            <!-- Logout icon -->
           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-box-arrow-right thicker-icon" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M10 12.5a.5.5 0 0 1-.5.5h-8a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5h8a.5.5 0 0 1 .5.5v2a.5.5 0 0 0 1 0v-2A1.5 1.5 0 0 0 9.5 2h-8A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h8a1.5 1.5 0 0 0 1.5-1.5v-2a.5.5 0 0 0-1 0z"/>
  <path fill-rule="evenodd" d="M15.854 8.354a.5.5 0 0 0 0-.708l-3-3a.5.5 0 0 0-.708.708L14.293 7.5H5.5a.5.5 0 0 0 0 1h8.793l-2.147 2.146a.5.5 0 0 0 .708.708z"/>
</svg>
        </button>
    </div>
    <div class="main-content">
        <div class="main-area">
            <div class="topbar">
                <div class="topbar-title">MSC Cookies</div>
                <div class="topbar-date"><?= getToday() ?></div>
                <div class="topbar-search">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="text" placeholder="Search for cookies and other" value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="filters">
                <div class="filter-tabs">
                    <select name="category" onchange="filterByCategory(this.value)">
                        <option value="All">Product</option>
                        <?php 
                        // Reset the categories result set
                        $categories->data_seek(0);
                        while ($cat = $categories->fetch_assoc()): 
                        ?>
                            <option value="<?= htmlspecialchars($cat['Category']) ?>" <?= $selected_category === $cat['Category'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['Category']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <select name="subcategory" onchange="filterBySubcategory(this.value)">
                        <option value="All">Categories</option>
                        <?php 
                        // Reset the subcategories result set
                        $subcategories->data_seek(0);
                        while ($sub = $subcategories->fetch_assoc()): 
                        ?>
                            <option value="<?= htmlspecialchars($sub['Subcategory']) ?>" <?= $selected_subcategory === $sub['Subcategory'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sub['Subcategory']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="products-section">
                <div class="products-header">
                    <?php if ($selected_category !== 'All'): ?>
                        <?= htmlspecialchars($selected_category) ?>
                        <?php if ($selected_subcategory !== 'All'): ?>
                            - <?= htmlspecialchars($selected_subcategory) ?>
                        <?php endif; ?>
                    <?php else: ?>
                        All Products
                    <?php endif; ?>
                    (<?= $products->num_rows ?> items)
                </div>
                <div class="products-grid">
                    <?php while ($row = $products->fetch_assoc()): ?>
                       <?php
    $safeName = htmlspecialchars($row['Product_Name'], ENT_QUOTES);
?>
<div class="product-card" onclick='addToOrder("<?= $safeName ?>", <?= $row['Product_Price'] ?>, <?= $row['Product_ID'] ?>)' style="cursor: pointer;">
                            <?php if ($row['Product_Picture']): ?>
                                <img class="product-img" src="imgproduct/<?= htmlspecialchars($row['Product_Picture']) ?>" alt="Product Image">
                            <?php else: ?>
                                <div class="product-img" style="display:flex;align-items:center;justify-content:center;color:#aaa;">No image</div>
                            <?php endif; ?>
                            <div class="product-name"><?= htmlspecialchars($row['Product_Name']) ?></div>
                            <div class="product-price">₱ <?= number_format($row['Product_Price'], 2) ?></div>
                        </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        <div class="order-section">
            <div class="order-header">
                <label for="customerName" style="font-size:15px;">Customer Name:</label>
                <input type="text" id="customerName" name="customerName" placeholder="Enter customer name" style="width:100%;padding:7px 10px;border-radius:6px;border:1px solid #fff5;font-size:15px;font-weight:bold;color:#ec3462;background:#fff;margin-top:6px;">
            </div>
            <div class="order-list" id="orderItems">
                <div class="order-table-header">
                    <span>Item Info</span>
                    <span class="qty">Qty</span>
                    <span class="price">Price</span>
                </div>
                <!-- Cart items will be dynamically added here -->
            </div>
            <div style="margin-top:auto;">
            <div class="order-summary">
                <div style="margin-bottom: 18px;">
                    <div class="order-summary-row">
                        <span>Subtotal</span>
                        <span id="subtotal">₱0.00</span>
                    </div>
                    <div class="order-summary-row total">
                        <span>Total Due</span>
                        <span id="total">₱0.00</span>
                    </div>
                    <div class="order-payment">
                        Mode of Payment
                        <button type="button" class="payment-btn active" id="btnCash" onclick="selectPayment('Cash')">Cash</button>
                        <button type="button" class="payment-btn" id="btnGcash" onclick="selectPayment('Gcash')">GCash</button>
                        <input type="hidden" id="paymentMethod" value="Cash">
                    </div>
                </div>
                <button class="order-btn" onclick="saveOrder();drawCheck();">Save Order Info</button>
            </div>
            </div>
        </div>
    </div>
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
<!-- Order Success Modal -->
<div class="modal-overlay" id="orderSuccessOverlay" style="display:none;align-items:center;justify-content:center;">
    <div class="modal" style="max-width:400px;">
        <div style="display:flex;justify-content:center;margin-bottom:24px;">
           <svg id="checkmark" width="70" height="70" viewBox="0 0 70 70">
  <circle cx="35" cy="35" r="35" fill="#4BB543"/>
  <polyline points="20,37 32,50 50,25" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
</svg>

        </div>
        <div class="modal-title" id="orderSuccessText" style="margin-bottom:18px;font-size:20px;font-weight:bold;text-align:center;">ORDER NO. 20250001 HAS BEEN SAVED</div>
        <button class="order-btn" id="goBackHomeBtn" style="margin-top:0;">GO BACK TO HOME</button>
    </div>
</div>
<script>
  function drawCheck() {
    const svg = document.getElementById("checkmark");
    svg.classList.add("bounce", "draw");
  }
</script>
<script>
    // Cart management
    const selectedProducts = [];
    // Store product images for cart rendering
    const productImages = {};
    <?php
    // Output a JS object mapping product IDs to image filenames
    $productsForImg = $conn->query("SELECT Product_ID, Product_Picture, Product_Price FROM Product");
    echo "Object.assign(productImages, {";
    while ($row = $productsForImg->fetch_assoc()) {
        $img = $row['Product_Picture'] ? 'imgproduct/' . addslashes($row['Product_Picture']) : '';
        $price = $row['Product_Price'];
        echo "{$row['Product_ID']}: {img: '" . $img . "', price: $price},";
    }
    echo "});\n";
    ?>

    function addToOrder(name, price, id) {
        const existing = selectedProducts.find(p => p.id === id);
        if (existing) {
            existing.quantity += 1;
        } else {
            selectedProducts.push({ id, name, price, quantity: 1 });
        }
        renderOrderItems();
    }

    function changeQuantity(index, delta) {
        selectedProducts[index].quantity += delta;
        if (selectedProducts[index].quantity <= 0) {
            selectedProducts.splice(index, 1);
        }
        renderOrderItems();
    }

    function removeProduct(index) {
        selectedProducts.splice(index, 1);
        renderOrderItems();
    }

    function renderOrderItems() {
        const orderItemsDiv = document.getElementById("orderItems");
        const subtotalEl = document.getElementById("subtotal");
        const totalEl = document.getElementById("total");

        // Keep the table header
        orderItemsDiv.innerHTML = document.querySelector('.order-table-header').outerHTML;
        let total = 0;

        selectedProducts.forEach((item, index) => {
            const row = document.createElement("div");
            row.className = "order-item";
            const imgSrc = productImages[item.id] && productImages[item.id].img ? productImages[item.id].img : '';
            row.innerHTML = `
                <img class="order-item-img" src="${imgSrc}" alt="" onerror="this.style.display='none'">
                <div class="order-item-info">
                    <div class="order-item-name">${item.name}</div>
                    <div class="order-item-sub">₱${item.price.toFixed(2)}</div>
                </div>
                <input class="order-item-qty" type="number" value="${item.quantity}" min="1" onchange="updateQuantity(${index}, this.value)">
                <div class="order-item-price">₱${(item.price * item.quantity).toFixed(2)}</div>
                <span class="order-remove" title="Remove" onclick="removeProduct(${index})"> 🗑 </span>
            `;
            orderItemsDiv.appendChild(row);
            total += item.price * item.quantity;
        });

        subtotalEl.textContent = `₱${total.toFixed(2)}`;
        totalEl.textContent = `₱${total.toFixed(2)}`;
    }

    function updateQuantity(index, newQuantity) {
        const quantity = parseInt(newQuantity);
        if (quantity > 0) {
            selectedProducts[index].quantity = quantity;
        } else {
            selectedProducts.splice(index, 1);
        }
        renderOrderItems();
    }

    function saveOrder() {
        var customerName = document.getElementById('customerName').value.trim();
        if (!customerName) {
            alert('Customer name is required.');
            document.getElementById('customerName').focus();
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'save_order.php', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                var orderCode = xhr.responseText;
                document.getElementById('orderSuccessText').textContent = 'ORDER NO. ' + orderCode + ' HAS BEEN SAVED';
                document.getElementById('orderSuccessOverlay').style.display = 'flex';
                selectedProducts.length = 0;
                renderOrderItems();
            }
        };
        var paymentMethod = document.getElementById('paymentMethod').value;
        var payload = {
            customer_name: customerName,
            payment_method: paymentMethod,
            products: selectedProducts
        };
        xhr.send(JSON.stringify(payload));
    }

    // Payment button toggle logic
    function selectPayment(method) {
        document.getElementById('paymentMethod').value = method;
        document.getElementById('btnCash').classList.toggle('active', method === 'Cash');
        document.getElementById('btnGcash').classList.toggle('active', method === 'Gcash');
    }

    // Filtering functions
    function filterByCategory(category) {
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('category', category);
        currentUrl.searchParams.set('subcategory', 'All'); // Reset subcategory when changing category
        window.location.href = currentUrl.toString();
    }

    function filterBySubcategory(subcategory) {
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.set('subcategory', subcategory);
        window.location.href = currentUrl.toString();
    }

    // Search functionality
    document.querySelector('.topbar-search input').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const searchTerm = this.value.trim();
            const currentUrl = new URL(window.location);
            if (searchTerm) {
                currentUrl.searchParams.set('search', searchTerm);
            } else {
                currentUrl.searchParams.delete('search');
            }
            window.location.href = currentUrl.toString();
        }
    });

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

    document.getElementById('goBackHomeBtn').onclick = function() {
        document.getElementById('orderSuccessOverlay').style.display = 'none';
        // Optionally redirect or reset order here
        // window.location.href = 'home.php';
    };
</script>
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
</body>
</html> 
<?php include 'loadingscreen.html'; ?>