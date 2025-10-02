<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
$user_id = $_SESSION['user_id'];
// Notification badge logic
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
// Fetch admin details
$stmt = $conn->prepare("SELECT * FROM User WHERE User_ID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
// Handle profile update
$update_msg = '';
$error_msg = '';
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
        } elseif ($dupEmail === $email) {
            $error_msg = 'Email is already registered!';
        } else {
            $error_msg = 'Username or email already exists!';
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
        $update_msg = 'Profile updated successfully!';
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>My Profile - MSC COOKIES</title>
  <link href="https://fonts.googleapis.com/css?family=Barlow:600,700&display=swap" rel="stylesheet">
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
      font-family: 'Barlow', Arial, sans-serif;
      display: flex;
    }
    .dashboard {
      display: flex;
      width: 100vw;
      min-height: 100vh;
    }
    .sidebar {
      width: 80px;
      background: var(--sidebar-bg);
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 24px 0 16px 0;
      position: relative;
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
    .profile-main {
      flex: 1;
      display: flex;
      flex-direction: row;
      width: 100%;
      min-height: 100vh;
      background: #f4c6c6;
    }
    .profile-content-container {
      background: #f6dddd;
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      height: calc(100vh - 80px);
      min-height: 400px;
      margin-top: 40px;
      margin-bottom: 40px;
      box-shadow: 0 4px 24px rgba(0,0,0,0.10);
      width: 1000px;
      margin-left: auto;
      margin-right: auto;
      /* Move a little to the right for better centering */
      transform: translateX(20px);
    }
    .profile-content {
      flex: none;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 48px 60px;
      min-width: 380px;
    }
    .profile-logo {
      width: 180px;
      height: 180px;
      object-fit: cover;
      border-radius: 16px;
      background: #fff;
      margin-bottom: 18px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .profile-title {
      font-size: 2.1rem;
      font-weight: 600;
      color: #222;
      margin-bottom: 6px;
      letter-spacing: 0.5px;
      text-align: center;
    }
    .profile-info-list {
      margin-bottom: 18px;
    }
    .profile-info-list div {
      margin-bottom: 6px;
    }
    .profile-edit-btn {
      background: #ec3462;
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 12px 36px;
      font-size: 1.1rem;
      font-weight: bold;
      cursor: pointer;
      margin-top: 18px;
      transition: background 0.2s;
    }
    .profile-edit-btn:hover {
      background: #c72b52;
    }
    .profile-success {
      color: #ec3462;
      font-weight: 600;
      margin-bottom: 10px;
      text-align: center;
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
  <div class="sidebar">
    <div class="logo">
      <img src="msclogo.jpg" alt="MSC Cookies Logo">
    </div>
    <div class="nav">
      <div class="nav-icon" title="Home" onclick="window.location.href='staff_dashboard.php'">
        <!-- Home icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12L12 4l9 8"/><path d="M9 21V9h6v12"/></svg>
      </div>
      <div class="nav-icon" title="Visualization">
        <!-- Pie chart icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 2v10h10"/></svg>
      </div>
      <div class="nav-icon" title="Notifications" onclick="window.location.href='staff_notifications.php'" style="position:relative;">
        <!-- Bell icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($hasNotifications): ?>
          <span class="notification-badge"></span>
        <?php endif; ?>
      </div>
      <div class="nav-icon" title="Sales" onclick="window.location.href='staff_saleshistory.php'">
        <!-- Line graph icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="4 14 8 10 12 14 16 10 20 14"/><line x1="4" y1="20" x2="20" y2="20"/></svg>
      </div>
      <div class="nav-icon" title="Settings" onclick="window.location.href='staff_settings.php'">
        <!-- Gear icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </div>
      <div class="nav-icon" title="People">
        <!-- Person icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 0 1 13 0"/></svg>
      </div>
    </div>
    <button class="logout-btn" id="logoutBtn" title="Logout">
      <!-- Logout icon -->
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7"/><path d="M3 21V3"/></svg>
    </button>
  </div>
  <div class="profile-main">
    <div class="profile-content-container">
      <div class="profile-content">
        <img class="profile-logo" src="<?= htmlspecialchars($user['Profile_Picture'] ? $user['Profile_Picture'] : 'msclogo.jpg') ?>" alt="Profile Picture">
        <div class="profile-title">My Profile</div>
        <div class="profile-info-list">
          <div><strong>Username:</strong> <?= htmlspecialchars($user['Username']) ?></div>
          <div><strong>Name:</strong> <?= htmlspecialchars($user['FName'] . ' ' . $user['LName']) ?></div>
          <div><strong>Email:</strong> <?= htmlspecialchars($user['Email']) ?></div>
        </div>
        <button class="profile-edit-btn" onclick="openEditModal()">Edit</button>
        <?php if ($update_msg): ?>
          <div class="profile-success" style="margin-top:10px;">Profile updated successfully!</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<!-- Edit Modal -->
<div class="modal-overlay" id="editProfileModalOverlay">
  <div class="modal">
    <div class="modal-title">Edit Profile</div>
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
        <button class="modal-btn confirm" type="submit" name="save">Save Changes</button>
        <button class="modal-btn cancel" type="button" onclick="closeEditProfileModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>
<!-- Logout Modal -->
<div class="modal-overlay" id="logoutModalOverlay">
    <div class="modal">
        <div class="modal-title">Are you sure you want to log out?</div>
        <div class="modal-btns">
            <button class="modal-btn confirm" id="confirmLogout">Log Out</button>
            <button class="modal-btn cancel" id="cancelLogout">Cancel</button>
        </div>
    </div>
</div>
<script>
    // Modal logic for edit
    function openEditModal() {
      document.getElementById('editProfileModalOverlay').style.display = 'flex';
    }
    function closeEditProfileModal() {
      document.getElementById('editProfileModalOverlay').style.display = 'none';
    }
    // Profile picture preview
    function previewProfilePic(event) {
      const reader = new FileReader();
      reader.onload = function(){
        document.getElementById('profilePicPreview').src = reader.result;
      };
      reader.readAsDataURL(event.target.files[0]);
    }
    // Logout modal logic (matches other pages)
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModalOverlay = document.getElementById('logoutModalOverlay');
    const confirmLogout = document.getElementById('confirmLogout');
    const cancelLogout = document.getElementById('cancelLogout');
    logoutBtn.addEventListener('click', function() {
        logoutModalOverlay.style.display = 'flex';
    });
    cancelLogout.addEventListener('click', function() {
        logoutModalOverlay.style.display = 'none';
    });
    confirmLogout.addEventListener('click', function() {
        window.location.href = 'logout.php';
    });
</script>
</body>
</html> 