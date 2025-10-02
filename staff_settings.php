<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");
// Notification badge logic
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Login_Tracker WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>MSC COOKIES Settings</title>
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
    /* --- Settings Layout --- */
    .settings-main {
      flex: 1;
      display: flex;
      flex-direction: row;
      width: 100%;
      min-height: 100vh;
      background: #f4c6c6;
    }
    .settings-left {
      width: 250px;
      background: #f5a9a9;
      padding: 40px 20px;
      font-size: 14px;
      margin: 40px 20px;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
    }
    .settings-title {
      font-size: 24px;
      font-weight: 600;
      color: #222;
      margin-bottom: 32px;
      letter-spacing: 0.5px;
      font-family: 'Barlow', Arial, sans-serif;
    }
    .settings-panel {
      width: 100%;
      background: none;
      padding: 0;
      border-radius: 0;
      box-shadow: none;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }
    .settings-item {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      font-size: 1.08rem;
      color: #222;
      font-weight: 400;
      margin-bottom: 0;
      font-family: 'Barlow', Arial, sans-serif;
    }
    .settings-item-icon {
      width: 20px;
      height: 20px;
      margin-top: 2px;
      flex-shrink: 0;
      color: #ec3462;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .settings-item-label {
      font-weight: 400;
      font-size: 1.08rem;
      color: #222;
      font-family: 'Barlow', Arial, sans-serif;
    }
    .settings-item-desc {
      font-weight: 400;
      font-size: 1rem;
      color: #444;
      margin-left: 0;
      font-family: 'Barlow', Arial, sans-serif;
    }
    .settings-version {
      margin-top: 32px;
      font-size: 1rem;
      color: #555;
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 500;
      font-family: 'Barlow', Arial, sans-serif;
    }
    .settings-version-icon {
      width: 18px;
      height: 18px;
      margin-right: 4px;
      opacity: 0.7;
    }
    /* --- Main Content --- */
    .settings-content-container {
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
    }
    .settings-content {
      flex: none;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 48px 60px;
      min-width: 380px;
    }
    .settings-logo {
      width: 180px;
      height: 180px;
      object-fit: contain;
      border-radius: 16px;
      background: #fff;
      margin-bottom: 18px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }
    .settings-main-title {
      font-size: 2.1rem;
      font-weight: 600;
      color: #222;
      margin-bottom: 6px;
      letter-spacing: 0.5px;
      text-align: center;
    }
    .settings-main-desc {
      font-size: 1.1rem;
      color: #444;
      font-weight: 500;
      margin-bottom: 32px;
      text-align: center;
    }
    .settings-owner {
      font-size: 1.1rem;
      color: #222;
      font-weight: 600;
      margin-bottom: 2px;
      text-align: center;
    }
    .settings-owner-role {
      font-size: 1rem;
      color: #444;
      font-weight: 500;
      margin-bottom: 32px;
      text-align: center;
    }
    .settings-contact-row {
      display: flex;
      flex-direction: row;
      gap: 48px;
      margin-bottom: 18px;
      justify-content: center;
    }
    .settings-contact-item {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 1.1rem;
      color: #222;
      font-weight: 600;
    }
    .settings-contact-icon {
      width: 26px;
      height: 26px;
      object-fit: contain;
      margin-right: 2px;
    }
    @media (max-width: 900px) {
      .settings-main { flex-direction: column; }
      .settings-left { width: 100%; padding: 24px 0 0 0; align-items: center; }
      .settings-panel { width: 90vw; }
      .settings-content { padding: 24px 0 0 0; }
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
      <div class="nav-icon active" title="Settings">
        <!-- Gear icon (active) -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.09a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.09a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.09a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </div>
      <div class="nav-icon" title="People" onclick="window.location.href='staff_profile.php'">
        <!-- Person icon -->
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a8.38 8.38 0 0 1 13 0"/></svg>
      </div>
    </div>
    <button class="logout-btn" id="logoutBtn" title="Logout">
      <!-- Logout icon -->
      <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7"/><path d="M3 21V3"/></svg>
    </button>
  </div>
  <div class="settings-main">
    <div class="settings-left">
      <div class="settings-title">Settings</div>
      <div class="settings-panel">
        <div class="settings-item">
          <span class="settings-item-icon">&#37;</span>
          <div>
            <div class="settings-item-label">Visualizations (predictive)</div>
            <div class="settings-item-desc">PowerBI</div>
          </div>
        </div>
        <div class="settings-item">
          <span class="settings-item-icon">&#10084;&#65039;</span>
          <div>
            <div class="settings-item-label">Visualizations (descriptive)</div>
            <div class="settings-item-desc">PowerBI</div>
          </div>
        </div>
        <div class="settings-item">
          <span class="settings-item-icon">&#128337;</span>
          <div>
            <div class="settings-item-label">JotForms</div>
            <div class="settings-item-desc">Last Updated: 5/10/25 10:30 pm</div>
          </div>
        </div>
      </div>
      <div class="settings-version">
        <img class="settings-version-icon" src="img/message1_icon.png" alt="Version Icon">
        System Version 1.1.1
      </div>
    </div>
    <div class="settings-content-container">
      <div class="settings-content">
        <img class="settings-logo" src="msclogo.jpg" alt="logo">
        <div class="settings-main-title">MSC COOKIES</div>
        <div class="settings-main-desc">Point-of-Sales System</div>
        <div class="settings-owner">Mariah Jana Tamayo</div>
        <div class="settings-owner-role">owner</div>
        <div class="settings-contact-row">
          <div class="settings-contact-item">
            <img class="settings-contact-icon" src="fb_icon.png" alt="Facebook">
            MSC Cookies
          </div>
          <div class="settings-contact-item">
            <img class="settings-contact-icon" src="message1_icon.png" alt="Phone">
            09098316895
          </div>
        </div>
        <div class="settings-contact-row">
          <div class="settings-contact-item">
            <img class="settings-contact-icon" src="message_icon.png" alt="Email">
            msccookies@gmail.com
          </div>
          <div class="settings-contact-item">
            <img class="settings-contact-icon" src="tel_icon.png" alt="Tel">
            214-593-6620
          </div>
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
<script>
    // Logout modal logic (matches other pages)
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