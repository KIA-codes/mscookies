<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}

$conn = new mysqli("localhost", "root", "", "mscookies");

// Check if the user is staff (same as staff_dashboard.php)
$user_id = $_SESSION['user_id'];
$userResult = $conn->query("SELECT UserType FROM User WHERE User_ID = $user_id");
$userRow = $userResult ? $userResult->fetch_assoc() : null;
if (!$userRow || $userRow['UserType'] !== 'staff') {
    header('Location: index.php');
    exit;
}

// Fetch only current staff's logins (latest first)
$current_user_id = $_SESSION['user_id'];
$logins = $conn->query("SELECT lt.*, u.FName, u.LName, u.Profile_Picture FROM Login_Tracker lt JOIN User u ON lt.User_ID = u.User_ID WHERE lt.User_ID = $current_user_id ORDER BY lt.Login_Time DESC");

// Mark all notifications for current user as seen when this page is loaded
$conn->query("UPDATE Login_Tracker SET Seen = 1 WHERE Seen = 0 AND User_ID = $current_user_id");

// Check if there are any unseen notifications for the current user
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0 AND User_ID = $current_user_id");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);

// Handle delete action (only for current user's records)
if (isset($_GET['delete'])) {
    $delId = intval($_GET['delete']);
    // Ensure the record belongs to current user before deleting
    $checkOwnership = $conn->query("SELECT User_ID FROM Login_Tracker WHERE ID = $delId AND User_ID = $current_user_id");
    if ($checkOwnership && $checkOwnership->num_rows > 0) {
        $conn->query("DELETE FROM Login_Tracker WHERE ID = $delId AND User_ID = $current_user_id");
    }
    header('Location: staff_notifications.php');
    exit;
}

function formatDate($dt) {
    return date('F j, Y', strtotime($dt));
}

function formatTime($dt) {
    return date('g:i a', strtotime($dt));
}

function formatDay($dt) {
    return date('l, F j, Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>My Login History - Staff</title>
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
    .notifications-list {
      width: 80%;
      margin-left: 48px;
      margin-top: 0;
      background: none;
      max-width: 1200px;
    }
    .notification-card {
      background: #fff;
      border-radius: 4px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.03);
      display: flex;
      align-items: center;
      padding: 18px 24px;
      margin-bottom: 12px;
      border: 1px solid #eee;
      justify-content: space-between;
    }
    .notification-info {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .notification-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      background: #eee;
      object-fit: cover;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .notification-details {
      display: flex;
      flex-direction: column;
    }
    .notification-title {
      font-weight: bold;
      color: #222;
      font-size: 16px;
      margin-bottom: 2px;
    }
    .notification-message {
      color: #444;
      font-size: 14px;
    }
    .notification-date {
      color: #888;
      font-size: 14px;
      min-width: 120px;
      text-align: right;
    }
    .notification-menu {
      margin-left: 18px;
      color: #888;
      font-size: 22px;
      cursor: pointer;
      user-select: none;
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
    .notif-action-modal {
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
    .notif-action-modal button {
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
    .notif-action-modal button:hover {
      background: #ffe6ee;
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
      .title, .notifications-list {
        margin-left: 12px;
      }
      .topbar {
        padding-right: 12px;
      }
      .notifications-list {
        width: 98%;
        max-width: 100vw;
      }
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
        <!-- Home icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12L12 4l9 8"/><path d="M9 21V9h6v12"/></svg>
      </div>
      <div class="nav-icon" title="Visualization">
        <!-- Pie chart icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 2v10h10"/></svg>
      </div>
      <div class="nav-icon active" title="My Login History" style="position:relative;">
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
      <div class="nav-icon" title="Profile" onclick="window.location.href='staff_profile.php'">
        <!-- Person icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 0 1 13 0"/></svg>
      </div>
    </div>
    <button class="logout-btn" id="logoutBtn" title="Logout">
      <!-- Logout icon -->
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7"/><path d="M3 21V3"/></svg>
    </button>
  </div>
  <div class="main-content">
  <div class="topbar">
  <div style="padding-right: 48px;">
    <?php echo formatDay(date('Y-m-d')); ?>
  </div>
</div>
    <div class="title">My Login History</div>
    <div class="notifications-list">
      <?php while ($row = $logins->fetch_assoc()): ?>
        <div class="notification-card">
          <div class="notification-info">
            <div class="notification-avatar">
              <img src="<?= $row['Profile_Picture'] && file_exists($row['Profile_Picture']) ? htmlspecialchars($row['Profile_Picture']) : 'img/default-avatar.png' ?>" alt="Avatar" style="width:38px;height:38px;border-radius:50%;object-fit:cover;">
            </div>
            <div class="notification-details">
              <div class="notification-title">Login Record</div>
              <div class="notification-message">
                You logged in the system on <?= formatDate($row['Login_Time']) ?> - <?= formatTime($row['Login_Time']) ?>.
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:18px;position:relative;">
            <div class="notification-date"><?= formatDate($row['Login_Time']) ?></div>
            <div class="notification-menu" onclick="showNotifMenu(event, <?= $row['ID'] ?>)">&#8942;
              <div class="notif-action-modal" id="notif-menu-<?= $row['ID'] ?>">
                <form method="get" style="margin:0;">
                  <input type="hidden" name="delete" value="<?= $row['ID'] ?>">
                  <button type="submit">Delete</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endwhile; ?>
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
  
  // Show/hide notification action modal
  let lastNotifMenu = null;
  function showNotifMenu(e, id) {
    e.stopPropagation();
    // Hide any open menu
    if (lastNotifMenu && lastNotifMenu !== id) {
      const prev = document.getElementById('notif-menu-' + lastNotifMenu);
      if (prev) prev.style.display = 'none';
    }
    const menu = document.getElementById('notif-menu-' + id);
    if (menu) {
      menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
      lastNotifMenu = id;
    }
  }
  
  // Hide menu when clicking outside
  document.addEventListener('click', function() {
    if (lastNotifMenu) {
      const menu = document.getElementById('notif-menu-' + lastNotifMenu);
      if (menu) menu.style.display = 'none';
      lastNotifMenu = null;
    }
  });
</script>
</body>
</html> 