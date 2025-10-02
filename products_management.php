<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");

// Ensure imgproduct directory exists
$imgDir = 'imgproduct';
if (!is_dir($imgDir)) {
    mkdir($imgDir, 0777, true);
}

// Add at the top after DB connection
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);

// Add product
if (isset($_POST['add'])) {
    $code = $_POST['product_code'];
    $name = $_POST['product_name'];
    $price = $_POST['product_price'];
    $category = $_POST['product_category'];
    $subcategory = $_POST['product_subcategory'];
    $imgName = null;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['product_image']['tmp_name'];
        $imgName = uniqid('prod_') . '_' . basename($_FILES['product_image']['name']);
        move_uploaded_file($tmpName, "$imgDir/$imgName");
    }
    $stmt = $conn->prepare("INSERT INTO Product (Product_Code, Product_Name, Product_Price, Product_Picture, Category, Subcategory) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdsss", $code, $name, $price, $imgName, $category, $subcategory);
    $stmt->execute();
    echo "<script>alert('Product added successfully.'); window.location='products_management.php';</script>";
    exit;
}

// Update product
if (isset($_POST['update'])) {
    $id = $_POST['edit_id'];
    $code = $_POST['edit_code'];
    $name = $_POST['edit_name'];
    $price = $_POST['edit_price'];
    $category = $_POST['edit_category'];
    $subcategory = $_POST['edit_subcategory'];
    $imgName = $_POST['current_image'];
    if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['edit_image']['tmp_name'];
        $imgName = uniqid('prod_') . '_' . basename($_FILES['edit_image']['name']);
        move_uploaded_file($tmpName, "$imgDir/$imgName");
    }
    $stmt = $conn->prepare("UPDATE Product SET Product_Code = ?, Product_Name = ?, Product_Price = ?, Product_Picture = ?, Category = ?, Subcategory = ? WHERE Product_ID = ?");
    $stmt->bind_param("ssdsssi", $code, $name, $price, $imgName, $category, $subcategory, $id);
    $stmt->execute();
    echo "<script>alert('Product updated successfully.'); window.location='products_management.php';</script>";
    exit;
}

// Delete product
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM Product WHERE Product_ID = $id");
    echo "<script>alert('Product deleted.'); window.location='products_management.php';</script>";
    exit;
}

// Fetch all products
// Archive product (soft delete)
if (isset($_GET['archive'])) {
    $id = (int)$_GET['archive'];
    $conn->query("UPDATE Product SET Status = 'Archive' WHERE Product_ID = $id");
    echo "<script>alert('Product archived.'); window.location='products_management.php';</script>";
    exit;
}

// Restore product from archive
if (isset($_GET['restore'])) {
    $id = (int)$_GET['restore'];
    $conn->query("UPDATE Product SET Status = 'Active' WHERE Product_ID = $id");
    echo "<script>alert('Product restored.'); window.location='products_management.php';</script>";
    exit;
}

// Fetch products (Active only for main list)
$products = $conn->query("SELECT * FROM Product WHERE Status = 'Active' ORDER BY Product_ID DESC");
// Fetch archived products for modal
$archivedProducts = $conn->query("SELECT * FROM Product WHERE Status = 'Archive' ORDER BY Product_ID DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Product Management - MSC Cookies</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root {
      --sidebar-bg: #ff7e94;
      --sidebar-active: #fff;
      --sidebar-icon: #fff;
      --sidebar-hover: #ffb3c1;
      --main-bg: #f4c6c6;
      --card-bg: #fff;
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
      display: flex;
    }
    /* Floating Add Product Button */
    .fab-add-product {
      position: fixed;
      right: 24px;
      bottom: 24px;
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: var(--primary);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      box-shadow: 0 6px 16px rgba(0,0,0,0.2);
      cursor: pointer;
      z-index: 2100;
      border: none;
      transition: background 0.2s, transform 0.05s;
    }
    .fab-add-product:hover { background: #c72b52; }
    .fab-add-product:active { transform: translateY(1px); }

    /* Add Product Modal */
    .addproductmodal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.35);
      z-index: 2200;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .addproductmodal {
      background: #fff;
      border-radius: 12px;
      padding: 28px 24px 22px 24px;
      box-shadow: 0 6px 24px rgba(0,0,0,0.18);
      width: 95vw;
      max-width: 720px;
    }
    .addproductmodal-title {
      font-size: 20px;
      font-weight: bold;
      margin: 0 0 18px 0;
      color: var(--text-dark);
    }
    .addproductmodal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 16px;
    }
    .btn-secondary {
      background: #fff;
      color: var(--primary);
      border: 2px solid var(--primary);
      border-radius: 6px;
      padding: 10px 18px;
      font-weight: bold;
      cursor: pointer;
    }
    .btn-secondary:hover { background: #ffe6ee; }

    /* Search and Filter Bar */
    .filters {
      display: flex;
      gap: 12px;
      align-items: center;
      background: #fff;
      padding: 12px 14px;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      margin: 8px auto 12px auto;
      flex-wrap: wrap;
      width: 100%;
    }
    .filters input[type="text"], .filters select {
      padding: 10px 12px;
      border: 1px solid #ddd;
      border-radius: 6px;
      background: #fafafa;
      font-size: 14px;
    }
    .filters .clear-btn {
      background: #fff;
      color: var(--primary);
      border: 2px solid var(--primary);
      border-radius: 6px;
      padding: 8px 12px;
      font-weight: bold;
      cursor: pointer;
    }
    .filters .clear-btn:hover { background: #ffe6ee; }
    .dashboard {
      display: flex;
      width: 100vw;
      min-height: 100vh;
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
    .logout-btn {
      width: 40px;
      height: 40px;
      background: var(--sidebar-hover);
      color: var(--primary);
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
      background: var(--primary);
      color: #fff;
    }
    .main-content {
      flex: 1;
      padding: 40px 32px;
      max-width: 1200px;
      margin: 0 auto;
      margin-left: 80px;
      width: 100%;
    }
    h2 {
      color: var(--primary);
      margin-top: 0;
    }
    form {
      background: #fff;
      padding: 24px 20px;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      margin-bottom: 32px;
      max-width: 400px;
    }
    form label {
      color: var(--text-dark);
      font-weight: 600;
    }
    form input[type="text"],
    form input[type="number"] {
      width: 100%;
      padding: 10px;
      margin-bottom: 14px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 15px;
      background: #fafafa;
      box-sizing: border-box;
    }
    form button[type="submit"] {
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 4px;
      padding: 10px 24px;
      font-size: 15px;
      font-weight: bold;
      cursor: pointer;
      transition: background 0.2s;
    }
    form button[type="submit"]:hover {
      background: #c72b52;
    }
    table {
      width: 100%;
      margin: 20px auto 0 auto;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
      overflow: hidden;
    }
    table, th, td {
      border: 1px solid #eee;
      border-collapse: collapse;
      padding: 10px;
    }
    th {
      background: var(--sidebar-bg);
      color: #fff;
      font-weight: bold;
    }
    td {
      color: var(--text-dark);
      font-size: 15px;
      text-align: center;
    }
    tr:nth-child(even) {
      background: #fff6fa;
    }
    .action-btn {
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 4px;
      padding: 6px 14px;
      font-size: 14px;
      cursor: pointer;
      margin-right: 6px;
      transition: background 0.2s;
    }
    .action-btn:hover {
      background: #c72b52;
    }
    .delete-link {
      color: #ec3462;
      text-decoration: none;
      font-weight: bold;
      margin-left: 6px;
      transition: color 0.2s;
    }
    .delete-link:hover {
      color: #a81d3a;
      text-decoration: underline;
    }
    /* Modal styles (copied from admin_dashboard.php) */
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
      .main-content {
        padding: 16px 4px;
      }
      form {
        max-width: 98vw;
      }
    }
    .product-img-thumb {
      width: 48px;
      height: 48px;
      object-fit: cover;
      border-radius: 8px;
      background: #eee;
    }
    /* Edit Modal styles */
    .edit-modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.35);
      z-index: 2000;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .edit-modal {
      background: #fff;
      border-radius: 10px;
      padding: 32px 28px 24px 28px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.18);
      text-align: center;
      min-width: 320px;
      max-width: 95vw;
      position: relative;
    }
    .edit-modal-close {
      position: absolute;
      top: 12px;
      right: 16px;
      background: none;
      border: none;
      font-size: 22px;
      color: #ec3462;
      cursor: pointer;
    }
    .edit-img-preview-wrap {
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 18px 0 18px 0;
      width: 100%;
    }
    #edit_img_preview {
      display: block;
      max-width: 120px;
      max-height: 120px;
      border-radius: 10px;
      background: #eee;
      margin: 0 auto;
    }
    .product-form-landscape {
      display: flex;
      flex-direction: column;
      gap: 0;
      max-width: 700px;
      margin: 0 auto;
      padding: 24px 32px 18px 32px;
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .form-row {
      display: flex;
      flex-direction: row;
      gap: 48px;
      width: 100%;
      margin-bottom: 0;
    }
    .form-col {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .form-col label {
      margin-bottom: 4px;
      font-weight: 600;
      color: #333;
    }
    .form-col input[type="text"],
    .form-col input[type="number"],
    .form-col input[type="file"] {
      width: 100%;
      padding: 10px 12px;
      border-radius: 6px;
      border: 1px solid #ddd;
      font-size: 15px;
      background: #fafafa;
      box-sizing: border-box;
    }
    .form-actions {
      display: flex;
      justify-content: center;
      align-items: center;
      margin-top: 28px;
      gap: 24px;
    }
    .edit-modal {
      min-width: 520px;
      max-width: 800px;
      padding: 40px 40px 32px 40px;
    }
    .edit-img-preview-wrap {
      margin-top: 12px;
      margin-bottom: 0;
    }
    @media (max-width: 900px) {
      .product-form-landscape {
        max-width: 98vw;
        padding: 12px 4vw 12px 4vw;
      }
      .edit-modal {
        min-width: 0;
        max-width: 98vw;
        padding: 18px 2vw 18px 2vw;
      }
    }
    @media (max-width: 700px) {
      .form-row {
        flex-direction: column;
        gap: 0;
      }
      .product-form-landscape {
        padding: 8px 2vw 8px 2vw;
      }
    }
  </style>
</head>
<body onload="showCookieLoader()">
<div class="dashboard">
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

      <div class="nav-icon active" title="Add Product" onclick="window.location.href='products_management.php'">
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
    <h2>Products</h2>
    <div class="filters">
      <input type="text" id="searchInput" placeholder="Search by code or name...">
      <select id="categoryFilter">
        <option value="">All Categories</option>
      </select>
      <select id="subcategoryFilter">
        <option value="">All Subcategories</option>
      </select>
      <button class="clear-btn" type="button" id="clearFilters">Clear</button>
      <button class="clear-btn" type="button" id="openArchived">Archived Products</button>
    </div>
    <h2>Product List</h2>
    <table>
      <tr>
        <th>Image</th>
        <th>Code</th>
        <th>Name</th>
        <th>Price (â‚±)</th>
        <th>Category</th>
        <th>Subcategory</th>
        <th>Action</th>
      </tr>
     <?php while ($row = $products->fetch_assoc()): ?>
  <tr>
    <td><?php if ($row['Product_Picture']): ?>
        <img class="product-img-thumb" src="imgproduct/<?= htmlspecialchars($row['Product_Picture']) ?>" alt="Product Image">
      <?php else: ?>
        <span style="color:#aaa;">No image</span>
      <?php endif; ?></td>
    <td>
      <?= htmlspecialchars($row['Product_Code']) ?>
    </td>
    <td><?= htmlspecialchars($row['Product_Name']) ?></td>
    <td><?= number_format($row['Product_Price'], 2) ?></td>
    <td><?= htmlspecialchars($row['Category']) ?></td>
    <td><?= htmlspecialchars($row['Subcategory']) ?></td>
    <td>
      <?php
        $jsRow = json_encode([
          'id' => $row['Product_ID'],
          'code' => $row['Product_Code'],
          'name' => $row['Product_Name'],
          'price' => $row['Product_Price'],
          'img' => $row['Product_Picture'],
          'category' => $row['Category'],
          'subcategory' => $row['Subcategory']
        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
      ?>
      <button class="action-btn" title="Edit Product Info" onclick='openModal(<?= $jsRow ?>)'><svg class="w-[20px] h-[20px] text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z"/>
</svg>
</button>
      <a class="delete-link" title="Archive" href="?archive=<?= $row['Product_ID'] ?>" onclick="return confirm('Archive this product?')"><svg class="w-[20px] h-[20px] text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
  <path fill-rule="evenodd" d="M20 10H4v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8ZM9 13v-1h6v1a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1Z" clip-rule="evenodd"/>
  <path d="M2 6a2 2 0 0 1 2-2h16a2 2 0 1 1 0 4H4a2 2 0 0 1-2-2Z"/>
</svg>
</a>
    </td>
  </tr>
<?php endwhile; ?>
    </table>
    <!-- Floating Add Product Button -->
    <button class="fab-add-product" id="openAddProduct" title="Add Product">+</button>
    <!-- Add Product Modal (addproductmodal) -->
    <div id="addproductmodalOverlay" class="addproductmodal-overlay">
      <div class="addproductmodal" role="dialog" aria-modal="true" aria-labelledby="addProductTitle">
        <div class="addproductmodal-title" id="addProductTitle">Add Product</div>
        <form method="post" enctype="multipart/form-data" class="product-form-landscape">
          <div class="form-row">
            <div class="form-col">
              <label>Product Code:</label>
              <input type="text" name="product_code" required>
              <label>Product Name:</label>
              <input type="text" name="product_name" required>
              <label>Product Price (â‚±):</label>
              <input type="number" name="product_price" step="0.01" required>
            </div>
            <div class="form-col">
              <label>Category:</label>
              <input type="text" name="product_category" required>
              <label>Subcategory:</label>
              <input type="text" name="product_subcategory" required>
              <label>Product Image:</label>
              <input type="file" name="product_image" accept="image/*">
                  </div>
          </div>
          <div class="addproductmodal-actions">
            <button type="button" class="btn-secondary" id="cancelAddProduct">Cancel</button>
            <button type="submit" name="add">Add Product</button>
          </div>
        </form>
            </div>
    </div>
    <!-- Archived Products Modal -->
    <div id="archivedModalOverlay" class="edit-modal-overlay">
      <div class="edit-modal" style="max-width: 900px;">
        <button class="edit-modal-close" onclick="closeArchivedModal()" title="Close">&times;</button>
        <div class="editmodal-title">Archived Products</div>
        <table>
          <tr>
            <th>Image</th>
            <th>Code</th>
            <th>Name</th>
            <th>Price (â‚±)</th>
            <th>Category</th>
            <th>Subcategory</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
          <?php while ($ar = $archivedProducts->fetch_assoc()): ?>
          <tr>
            <td>
              <?php if ($ar['Product_Picture']): ?>
                <img class="product-img-thumb" src="imgproduct/<?= htmlspecialchars($ar['Product_Picture']) ?>" alt="Product Image">
              <?php else: ?>
                <span style="color:#aaa;">No image</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($ar['Product_Code']) ?></td>
            <td><?= htmlspecialchars($ar['Product_Name']) ?></td>
            <td><?= number_format($ar['Product_Price'], 2) ?></td>
            <td><?= htmlspecialchars($ar['Category']) ?></td>
            <td><?= htmlspecialchars($ar['Subcategory']) ?></td>
            <td><?= htmlspecialchars($ar['Status']) ?></td>
            <td>
              <a class="delete-link" title="Restore" href="?restore=<?= $ar['Product_ID'] ?>" onclick="return confirm('Restore this product to Active?')">Restore</a>
            </td>
          </tr>
          <?php endwhile; ?>
        </table>
            </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="editModalOverlay" class="edit-modal-overlay">
      <div class="edit-modal">
        <button class="edit-modal-close" onclick="closeModal()" title="Close">&times;</button>
        <form method="post" enctype="multipart/form-data" class="product-form-landscape">
          <div class="form-row">
            <div class="form-col">
              <input type="hidden" name="edit_id" id="edit_id">
              <input type="hidden" name="current_image" id="current_image">
              <label>Product Code:</label>
              <input type="text" name="edit_code" id="edit_code" required>
              <label>Product Name:</label>
              <input type="text" name="edit_name" id="edit_name" required>
              <label>Product Price (â‚±):</label>
              <input type="number" name="edit_price" step="0.01" id="edit_price" required>
            </div>
            <div class="form-col">
              <label>Category:</label>
              <input type="text" name="edit_category" id="edit_category" required>
              <label>Subcategory:</label>
              <input type="text" name="edit_subcategory" id="edit_subcategory" required>
              <label>Product Image:</label>
              <input type="file" name="edit_image" accept="image/*">
                  </div>
          </div>
          <div class="form-actions">
            <button type="submit" name="update">Update Product</button>
            <button type="button" class="modal-btn cancel" onclick="closeModal()">Cancel</button>
          </div>
        </form>
            </div>
    </div>
  
      </div>
</div>
<!-- Logout Modal (copied from admin_dashboard.php) -->
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
function openModal(product) {
  document.getElementById('edit_id').value = product.id;
  document.getElementById('edit_code').value = product.code;
  document.getElementById('edit_name').value = product.name;
  document.getElementById('edit_price').value = product.price;
  document.getElementById('current_image').value = product.img;
  document.getElementById('edit_category').value = product.category;
  document.getElementById('edit_subcategory').value = product.subcategory;
  document.getElementById('editModalOverlay').style.display = 'flex';
}
  function closeModal() {
    document.getElementById('editModalOverlay').style.display = 'none';
  }
  // Close modal when clicking outside
  window.addEventListener('click', function(event) {
    const overlay = document.getElementById('editModalOverlay');
    if (event.target === overlay) {
      closeModal();
    }
  });
  // Logout modal logic (copied from admin_dashboard.php)
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
<script>
// Search and Filter logic
(function(){
  const searchInput = document.getElementById('searchInput');
  const categoryFilter = document.getElementById('categoryFilter');
  const subcategoryFilter = document.getElementById('subcategoryFilter');
  const clearBtn = document.getElementById('clearFilters');
  const openArchivedBtn = document.getElementById('openArchived');
  const archivedOverlay = document.getElementById('archivedModalOverlay');
  const table = document.querySelector('table');
  const rows = Array.from(table.querySelectorAll('tr')).slice(1);

  const categories = new Set();
  const subcategories = new Set();
  rows.forEach(r => {
    const cells = r.querySelectorAll('td');
    if (cells.length >= 6) {
      categories.add(cells[4].textContent.trim());
      subcategories.add(cells[5].textContent.trim());
    }
  });
  for (const c of Array.from(categories).filter(Boolean).sort()) {
    const opt = document.createElement('option'); opt.value = c; opt.textContent = c; categoryFilter.appendChild(opt);
  }
  for (const s of Array.from(subcategories).filter(Boolean).sort()) {
    const opt = document.createElement('option'); opt.value = s; opt.textContent = s; subcategoryFilter.appendChild(opt);
  }

  function applyFilters() {
    const q = (searchInput.value || '').toLowerCase();
    const cat = categoryFilter.value || '';
    const sub = subcategoryFilter.value || '';
    rows.forEach(r => {
      const cells = r.querySelectorAll('td');
      if (cells.length < 6) return;
      const code = (cells[1].textContent || '').toLowerCase();
      const name = (cells[2].textContent || '').toLowerCase();
      const category = cells[4].textContent.trim();
      const subcategory = cells[5].textContent.trim();
      const matchesSearch = !q || code.includes(q) || name.includes(q);
      const matchesCat = !cat || category === cat;
      const matchesSub = !sub || subcategory === sub;
      r.style.display = (matchesSearch && matchesCat && matchesSub) ? '' : 'none';
    });
  }

  searchInput.addEventListener('input', applyFilters);
  categoryFilter.addEventListener('change', applyFilters);
  subcategoryFilter.addEventListener('change', applyFilters);
  clearBtn.addEventListener('click', function(){
    searchInput.value = '';
    categoryFilter.value = '';
    subcategoryFilter.value = '';
    applyFilters();
  });

  // Open archived modal
  openArchivedBtn.addEventListener('click', function(){
    archivedOverlay.style.display = 'flex';
  });
})();

// Add Product modal behavior
(function(){
  const overlay = document.getElementById('addproductmodalOverlay');
  const openBtn = document.getElementById('openAddProduct');
  const cancelBtn = document.getElementById('cancelAddProduct');
  openBtn.addEventListener('click', () => { overlay.style.display = 'flex'; });
  cancelBtn.addEventListener('click', () => { overlay.style.display = 'none'; });
  window.addEventListener('click', function(e){ if (e.target === overlay) overlay.style.display = 'none'; });
})();

function closeArchivedModal(){
  document.getElementById('archivedModalOverlay').style.display = 'none';
}
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




