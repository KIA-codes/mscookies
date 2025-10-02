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

// Check for notifications for the badge
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);

// Fetch current user's profile picture
$userProfileQuery = $conn->prepare("SELECT Profile_Picture FROM User WHERE User_ID = ?");
$userProfileQuery->bind_param("i", $user_id);
$userProfileQuery->execute();
$userProfileResult = $userProfileQuery->get_result();
$userProfile = $userProfileResult->fetch_assoc();
$userProfileQuery->close();

// Set profile picture path - use user's profile picture if available, otherwise use default
$profilePicture = $userProfile['Profile_Picture'] ? $userProfile['Profile_Picture'] : 'msclogo.jpg';

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
            window.location.href = 'staff_dashboard.php';
        </script>";
        // Refresh user data
        $stmt = $conn->prepare("SELECT * FROM User WHERE User_ID = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
function getToday() {
    return date('l, F j, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard - MSC Cookies</title>
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
             background:#F98CA3;
        }
        body {
            min-height: 100vh;
            background: var(--main-bg);
            font-family: 'Arial', sans-serif;
            overflow-x:hidden;
        }
        .dashboard {
            display: flex;
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
            width: 60px;
            height: 60px;
            object-fit: contain;
        }
        .nav {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 70px;
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
            margin-left: 2px;
            margin-right: 330px;/* Account for fixed sidebar */
            margin-bottom: 0px;
        }
        .center
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
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: #fff;
            margin-bottom: 14px;
            object-fit: cover;
            border: 3px solid #fff;
        }
        .product-name {
            font-weight: bold;
            font-size:20px;
            margin-bottom: 4px;
            color: #fff;
            text-align: center;
        }
        .product-price {
            color: #311814;
            font-weight: bold;
            font-size:20px;
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

     .top-bar {
  position: fixed;
  top: 10px;
  right: 580px;
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
 
  cursor: pointer;
  transition: transform 0.3s ease;
  

 
}

.logooo:hover {
  transform: scale(1.1);
}

.option-btn {
  position: absolute;
  top:45px;
  width: 45px;
  height: 45px;
  border-radius: 50%;
  background: #fff0f1ff;
  margin-right: 5px;
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
    </style>
</head>
<body>
<div class="dashboard">
  
    <div class="main-content">
        <div class="main-area">
            <div class="topbar">
                <div class="topbar-title">Welcome, <?= htmlspecialchars($user['FName'] . ' ' . $user['LName']) ?></div>
                
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
                        <div class="product-card" onclick="addToOrder('<?= htmlspecialchars($row['Product_Name'], ENT_QUOTES) ?>', <?= $row['Product_Price'] ?>, <?= $row['Product_ID'] ?>)" style="cursor: pointer;">
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
                <button class="order-btn" onclick="saveOrder()">Save Order Info</button>
            </div>
            </div>
        </div>
    </div>
</div>
<!-- Logout Modal -->

<!-- Order Success Modal -->
<div class="modal-overlay" id="orderSuccessOverlay" style="display:none;align-items:center;justify-content:center;">
    <div class="modal" style="max-width:400px;">
        <div style="display:flex;justify-content:center;margin-bottom:24px;">
            <svg width="70" height="70" viewBox="0 0 70 70"><circle cx="35" cy="35" r="35" fill="#4BB543"/><polyline points="20,37 32,50 50,25" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="modal-title" id="orderSuccessText" style="margin-bottom:18px;font-size:20px;font-weight:bold;text-align:center;">ORDER NO. 20250001 HAS BEEN SAVED</div>
        <button class="order-btn" id="goBackHomeBtn" style="margin-top:0;">GO BACK TO HOME</button>
    </div>
</div>
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
    
    // Modal logic for order save
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

    document.getElementById('goBackHomeBtn').onclick = function() {
        document.getElementById('orderSuccessOverlay').style.display = 'none';
        // Optionally redirect or reset order here
        // window.location.href = 'home.php';
    };
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

<div class="top-bar">
  <div class="logooo-menu" id="menuContainer">
     <img src="nobackgroundlogo1.png" width="250" height="100" alt="Profile Picture" class="logooo" id="logoooBtn" >
     <div class="option-btn settings" title="view profile" onclick="openProfileModal('profileModal')"><svg class="w-6 h-6 text-gray-800 dark:text-white" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="26" height="26" fill="none" viewBox="0 0 24 24">
  <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 9h3m-3 3h3m-3 3h3m-6 1c-.306-.613-.933-1-1.618-1H7.618c-.685 0-1.312.387-1.618 1M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm7 5a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>
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

  if (logoutBtn) {
    logoutBtn.addEventListener('click', function() {
      modalOverlay.style.display = 'flex';
    });
  }
  cancelLogout.addEventListener('click', function() {
    modalOverlay.style.display = 'none';
  });
  confirmLogout.addEventListener('click', function() {
    window.location.href = 'logout.php';
  });
    document.getElementById('modalOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
      modalOverlay.style.display = 'none';
    }
  });
</script>
</body>
</html> 
