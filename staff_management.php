<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
// Add at the top after DB connection
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
// Add staff
$error = '';
if (isset($_POST['add'])) {
    $fname = $_POST['fname'];
    $lname = $_POST['lname'];
    $email = $_POST['email'];
    $username = $_POST['username'];
    $default_password = 'mscookies123';
    $imgstaffDir = 'imgstaff';
    if (!is_dir($imgstaffDir)) {
        mkdir($imgstaffDir, 0777, true);
    }
    $profilePic = 'msclogo.jpg';
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['profile_pic']['tmp_name'];
        $imgName = uniqid('staff_') . '_' . basename($_FILES['profile_pic']['name']);
        move_uploaded_file($tmpName, "$imgstaffDir/$imgName");
        $profilePic = "$imgstaffDir/$imgName";
    }
    // Check for unique username and email (for all users)
    $stmt = $conn->prepare("SELECT COUNT(*) FROM User WHERE Username = ? OR Email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    if ($count > 0) {
        $error = 'Username or Email already exists!';
    } else {
        $stmt = $conn->prepare("INSERT INTO User (FName, LName, Email, Username, Password, UserType, Profile_Picture) VALUES (?, ?, ?, ?, ?, 'staff', ?)");
        $stmt->bind_param("ssssss", $fname, $lname, $email, $username, $default_password, $profilePic);
        $stmt->execute();
        echo "<script>alert('Staff added successfully.'); window.location='staff_management.php';</script>";
        exit;
    }
}
// Archive staff
if (isset($_GET['archive'])) {
    $id = $_GET['archive'];
    $stmt = $conn->prepare("UPDATE User SET Status = 'archived' WHERE User_ID = ? AND UserType = 'staff'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    echo "<script>alert('Staff archived.'); window.location='staff_management.php';</script>";
    exit;
}
// Restore staff
if (isset($_GET['restore'])) {
    $id = $_GET['restore'];
    $stmt = $conn->prepare("UPDATE User SET Status = 'active' WHERE User_ID = ? AND UserType = 'staff'");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    echo "<script>alert('Staff restored.'); window.location='staff_management.php';</script>";
    exit;
}
// Fetch all active staff
$staff = $conn->query("SELECT * FROM User WHERE UserType = 'staff' AND Status = 'active' ORDER BY User_ID DESC");
// Fetch all archived staff
$archived_staff = $conn->query("SELECT * FROM User WHERE UserType = 'staff' AND Status = 'archived' ORDER BY User_ID DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Staff Management - MSC Cookies</title>
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
    .nav {
      flex: 1;
      margin-top:50px;
      margin-bottom: 50px;
      display: flex;
      flex-direction: column;
      gap: 60px;
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
      padding: 40px 32px;
      max-width: 1200px;
      margin: 0 auto;
      width: 100%;
    }
    h2 {
      color: #ec3462;
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
      color: #222;
      font-weight: 600;
    }
    form input[type="text"],
    form input[type="email"],
    form input[type="password"] {
      width: 100%;
      padding: 10px;
      margin-bottom: 14px;
      border: 1px solid #ddd;
      border-radius: 4px;
      font-size: 15px;
      background: #fafafa;
      color: #222;
      box-sizing: border-box;
    }
    form button[type="submit"] {
      background: #ec3462;
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
      margin-top: 20px;
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
      background: #ff7e94;
      color: #fff;
      font-weight: bold;
    }
    td {
      color: #222;
      font-size: 15px;
      text-align: center;
    }
    tr:nth-child(even) {
      background: #fff6fa;
    }
    .action-btn {
      background: #ec3462;
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
    .archive-link {
      color: #ff7e94;
      text-decoration: none;
      font-weight: bold;
      margin-left: 6px;
      transition: color 0.2s;
      background: none;
      border: none;
      padding: 0;
      display: inline-block;
    }
    .archive-link:hover {
      color: #ec3462;
      text-decoration: underline;
    }
    .restore-link {
      color: #28a745;
      text-decoration: none;
      font-weight: bold;
      margin-left: 6px;
      transition: color 0.2s;
      background: none;
      border: none;
      padding: 0;
      display: inline-block;
    }
    .restore-link:hover {
      color: #1e7e34;
      text-decoration: underline;
    }
    @media (max-width: 900px) {
      .main-content {
        padding: 16px 4px;
      }
      form {
        max-width: 98vw;
      }
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
    /* Floating Buttons */
    .fab-container {
      position: fixed;
      right: 24px;
      bottom: 24px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      z-index: 2100;
    }
    .fab-add-staff {
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
      border: none;
      transition: background 0.2s, transform 0.05s;
    }
    .fab-add-staff:hover { background: #c72b52; }
    .fab-add-staff:active { transform: translateY(1px); }
    .fab-archive {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: #ff7e94;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
      cursor: pointer;
      border: none;
      transition: background 0.2s, transform 0.05s;
    }
    .fab-archive:hover { background: #ec3462; }
    .fab-archive:active { transform: translateY(1px); }

    /* Add Staff Modal */
    .addstaffmodal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.35);
      z-index: 2200;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .addstaffmodal {
      background: #fff;
      border-radius: 12px;
      padding: 20px 20px 18px 20px;
      box-shadow: 0 6px 24px rgba(0,0,0,0.18);
      width: auto;
      max-width: 500px;
    }
    .addstaffmodal-title {
      font-size: 20px;
      font-weight: bold;
      margin: 0 0 18px 0;
      color: var(--text-dark);
    }
    .addstaffmodal-actions {
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
    /* Archive Modal */
    .archivemodal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(0,0,0,0.35);
      z-index: 2300;
      display: none;
      align-items: center;
      justify-content: center;
    }
    .archivemodal {
      background: #fff;
      border-radius: 12px;
      padding: 24px;
      box-shadow: 0 6px 24px rgba(0,0,0,0.18);
      width: 90%;
      max-width: 800px;
      max-height: 80vh;
      overflow-y: auto;
    }
    .archivemodal-title {
      font-size: 20px;
      font-weight: bold;
      margin: 0 0 20px 0;
      color: var(--text-dark);
    }
    .archivemodal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 20px;
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


  <!-- Dropdown menu -->


 
                 <div class="nav-icon" title="Sales" onclick="window.location.href='sales_history.php'">
        <!-- Line graph icon -->
       <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-list-check" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M5 11.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3.854 2.146a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 3.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708L2 7.293l1.146-1.147a.5.5 0 0 1 .708 0m0 4a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0"/>
</svg>

      </div>
           <div class="nav-icon" title="Generate reports" onclick="window.location.href='generate_reports.php'">
        <!-- Line graph icon -->
       <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 8h6m-6 4h6m-6 4h6M6 3v18l2-2 2 2 2-2 2 2 2-2 2 2V3l-2 2-2-2-2 2-2-2-2 2-2-2Z"/>
</svg>


      </div>
      <div class="nav-icon" title="User Logs" onclick="window.location.href='notifications.php'" style="position:relative;">
        <!-- Bell icon -->
  <svg class="w-[20px] h-[20px] text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
</svg>

         <?php if ($hasNotifications): ?>
          <span class="notification-badge"></span>
        <?php endif; ?>
      </div>

        
      
    
      <div class="nav-icon" title="Add Product" onclick="window.location.href='products_management.php'">
        <!-- Add icon -->
       <svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.65692 9.41494h.01M7.27103 13h.01m7.67737 1.9156h.01M10.9999 17h.01m3.178-10.90671c-.8316.38094-1.8475.22903-2.5322-.45571-.3652-.36522-.5789-.82462-.6409-1.30001-.0574-.44-.0189-.98879.1833-1.39423-1.99351.20001-3.93304 1.06362-5.46025 2.59083-3.51472 3.51472-3.51472 9.21323 0 12.72793 3.51471 3.5147 9.21315 3.5147 12.72795 0 1.5601-1.5602 2.4278-3.5507 2.6028-5.5894-.2108.008-.6725.0223-.8328.0157-.635.0644-1.2926-.1466-1.779-.633-.3566-.3566-.5651-.8051-.6257-1.2692-.0561-.4293.0145-.87193.2117-1.26755-.1159.20735-.2619.40237-.4381.57865-1.0283 1.0282-2.6953 1.0282-3.7235 0-1.0282-1.02824-1.0282-2.69531 0-3.72352.0977-.09777.2013-.18625.3095-.26543"/>
</svg>
</div>
      </div>
  </div>
  <div class="main-content">
    <h2>Admin List</h2>
    <table>
      <tr>
        <th>ID</th>
        <th>Image</th>
        <th>Name</th>
        <th>Email</th>
        <th>Username</th>
      </tr>
      <?php 
        $admins = $conn->query("SELECT * FROM User WHERE UserType = 'admin' ORDER BY User_ID DESC");
        while ($a = $admins->fetch_assoc()):
          $aimg = isset($a['Profile_Picture']) && $a['Profile_Picture'] && file_exists($a['Profile_Picture']) ? $a['Profile_Picture'] : 'msclogo.jpg';
      ?>
      <tr>
        <td><?= $a['User_ID'] ?></td>
        <td><img src="<?= htmlspecialchars($aimg) ?>" alt="Profile" style="width:48px;height:48px;object-fit:cover;border-radius:8px;background:#eee;"></td>
        <td><?= htmlspecialchars($a['FName'] . ' ' . $a['LName']) ?></td>
        <td><?= htmlspecialchars($a['Email']) ?></td>
        <td><?= htmlspecialchars($a['Username']) ?></td>
      </tr>
      <?php endwhile; ?>
    </table>
    <h2>Staff List</h2>
    <table>
      <tr>
        <th>ID</th>
        <th>Image</th>
        <th>Name</th>
        <th>Email</th>
        <th>Username</th>
        <th>Action</th>
      </tr>
      <?php while ($row = $staff->fetch_assoc()): ?>
        <tr>
          <td><?= $row['User_ID'] ?></td>
          <td>
            <?php 
              $img = isset($row['Profile_Picture']) && $row['Profile_Picture'] && file_exists($row['Profile_Picture']) ? $row['Profile_Picture'] : 'msclogo.jpg';
            ?>
            <img src="<?= htmlspecialchars($img) ?>" alt="Profile" style="width:48px;height:48px;object-fit:cover;border-radius:8px;background:#eee;">
          </td>
          <td><?= htmlspecialchars($row['FName'] . ' ' . $row['LName']) ?></td>
          <td><?= htmlspecialchars($row['Email']) ?></td>
          <td><?= htmlspecialchars($row['Username']) ?></td>
          <td>
            <a class="archive-link" href="?archive=<?= $row['User_ID'] ?>" onclick="return confirm('Archive this staff?')">ðŸ“¦ Archive</a>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>
    <!-- Floating Buttons -->
    <div class="fab-container">
      <button class="fab-archive" id="openArchiveModal" title="View Archived Staff"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 0 2-2m-2 2-2-2M3 6v1a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1Zm2 2v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8H5Z"/>
</svg>
</button>
      <button class="fab-add-staff" id="openAddStaff" title="Add Staff">+</button>
    </div>
    <!-- Add Staff Modal -->
    <div id="addstaffmodalOverlay" class="addstaffmodal-overlay">
      <div class="addstaffmodal" role="dialog" aria-modal="true" aria-labelledby="addStaffTitle">
        <div class="addstaffmodal-title" id="addStaffTitle">Add Staff</div>
        <?php if (!empty($error)): ?>
          <div style="color: #ec3462; font-weight: bold; margin-bottom: 16px;"> <?= htmlspecialchars($error) ?> </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
          <label>First Name:</label><br>
          <input type="text" name="fname" required><br>
          <label>Last Name:</label><br>
          <input type="text" name="lname" required><br>
          <label>Email:</label><br>
          <input type="email" name="email" required><br>
          <label>Username:</label><br>
          <input type="text" name="username" required><br>
          <label>Profile Picture:</label><br>
          <input type="file" name="profile_pic" accept="image/*"><br>
          <div class="addstaffmodal-actions">
            <button type="button" class="btn-secondary" id="cancelAddStaff">Cancel</button>
            <button type="submit" name="add">Add Staff</button>
          </div>
        </form>
      </div>
    </div>
    <!-- Archive Modal -->
    <div id="archivemodalOverlay" class="archivemodal-overlay">
      <div class="archivemodal" role="dialog" aria-modal="true" aria-labelledby="archiveTitle">
        <div class="archivemodal-title" id="archiveTitle">Archived Staff</div>
        <table>
          <tr>
            <th>ID</th>
            <th>Image</th>
            <th>Name</th>
            <th>Email</th>
            <th>Username</th>
            <th>Action</th>
          </tr>
          <?php while ($archived_row = $archived_staff->fetch_assoc()): ?>
            <tr>
              <td><?= $archived_row['User_ID'] ?></td>
              <td>
                <?php 
                  $archived_img = isset($archived_row['Profile_Picture']) && $archived_row['Profile_Picture'] && file_exists($archived_row['Profile_Picture']) ? $archived_row['Profile_Picture'] : 'msclogo.jpg';
                ?>
                <img src="<?= htmlspecialchars($archived_img) ?>" alt="Profile" style="width:48px;height:48px;object-fit:cover;border-radius:8px;background:#eee;">
              </td>
              <td><?= htmlspecialchars($archived_row['FName'] . ' ' . $archived_row['LName']) ?></td>
              <td><?= htmlspecialchars($archived_row['Email']) ?></td>
              <td><?= htmlspecialchars($archived_row['Username']) ?></td>
              <td>
                <a class="restore-link" href="?restore=<?= $archived_row['User_ID'] ?>" onclick="return confirm('Restore this staff?')">â†©ï¸ Restore</a>
              </td>
            </tr>
          <?php endwhile; ?>
        </table>
        <div class="archivemodal-actions">
          <button type="button" class="btn-secondary" id="cancelArchiveModal">Close</button>
        </div>
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
// Add Staff modal behavior
(function(){
  const overlay = document.getElementById('addstaffmodalOverlay');
  const openBtn = document.getElementById('openAddStaff');
  const cancelBtn = document.getElementById('cancelAddStaff');
  if (openBtn) openBtn.addEventListener('click', () => { overlay.style.display = 'flex'; });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { overlay.style.display = 'none'; });
  window.addEventListener('click', function(e){ if (e.target === overlay) overlay.style.display = 'none'; });
})();

// Archive modal behavior
(function(){
  const overlay = document.getElementById('archivemodalOverlay');
  const openBtn = document.getElementById('openArchiveModal');
  const cancelBtn = document.getElementById('cancelArchiveModal');
  if (openBtn) openBtn.addEventListener('click', () => { overlay.style.display = 'flex'; });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { overlay.style.display = 'none'; });
  window.addEventListener('click', function(e){ if (e.target === overlay) overlay.style.display = 'none'; });
})();
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


