<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$conn = new mysqli("localhost", "root", "", "mscookies");

/**
 * ActivityLogger Class
 * Comprehensive activity logging system for MSC Cookies
 */
class ActivityLogger {
    private $conn;
    
    public function __construct($database_connection) {
        $this->conn = $database_connection;
    }
    
    /**
     * Log an activity
     * @param int $user_id - User ID performing the action
     * @param string $activity_type - Type of activity (login, logout, download, etc.)
     * @param string $description - Human readable description
     * @param array $details - Additional details as associative array
     */
    public function logActivity($user_id, $activity_type, $description, $details = []) {
        try {
            // Get user's IP address
            $ip_address = $this->getClientIP();
            
            // Get user agent
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Convert details to JSON
            $details_json = json_encode($details);
            
            $stmt = $this->conn->prepare("
                INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param("isssss", $user_id, $activity_type, $description, $details_json, $ip_address, $user_agent);
            $stmt->execute();
            $stmt->close();
            
            return true;
        } catch (Exception $e) {
            error_log("ActivityLogger Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client's real IP address
     */
    private function getClientIP() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    /**
     * Predefined activity logging methods for common actions
     */
    
    public function logLogin($user_id, $username) {
        return $this->logActivity($user_id, 'login', "User '{$username}' logged into the system", [
            'username' => $username,
            'login_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logLogout($user_id, $username) {
        return $this->logActivity($user_id, 'logout', "User '{$username}' logged out of the system", [
            'username' => $username,
            'logout_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logDownload($user_id, $username, $file_type, $file_name = '') {
        $description = "User '{$username}' downloaded {$file_type}";
        if ($file_name) {
            $description .= ": {$file_name}";
        }
        
        return $this->logActivity($user_id, 'download', $description, [
            'username' => $username,
            'file_type' => $file_type,
            'file_name' => $file_name,
            'download_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logDataInsert($user_id, $username, $data_type, $details = []) {
        return $this->logActivity($user_id, 'data_insert', "User '{$username}' inserted {$data_type} data", array_merge([
            'username' => $username,
            'data_type' => $data_type,
            'insert_time' => date('Y-m-d H:i:s')
        ], $details));
    }
    
    public function logProfileUpdate($user_id, $username, $changes = []) {
        return $this->logActivity($user_id, 'profile_update', "User '{$username}' updated their profile", [
            'username' => $username,
            'changes' => $changes,
            'update_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logReportGeneration($user_id, $username, $report_type, $filters = []) {
        return $this->logActivity($user_id, 'report_generation', "User '{$username}' generated {$report_type} report", [
            'username' => $username,
            'report_type' => $report_type,
            'filters' => $filters,
            'generation_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logDataImport($user_id, $username, $import_type, $file_name, $records_count = 0) {
        return $this->logActivity($user_id, 'data_import', "User '{$username}' imported {$import_type} data from {$file_name}", [
            'username' => $username,
            'import_type' => $import_type,
            'file_name' => $file_name,
            'records_count' => $records_count,
            'import_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logDataExport($user_id, $username, $export_type, $file_name = '') {
        $description = "User '{$username}' exported {$export_type} data";
        if ($file_name) {
            $description .= " to {$file_name}";
        }
        
        return $this->logActivity($user_id, 'data_export', $description, [
            'username' => $username,
            'export_type' => $export_type,
            'file_name' => $file_name,
            'export_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logPageAccess($user_id, $username, $page_name) {
        return $this->logActivity($user_id, 'page_access', "User '{$username}' accessed {$page_name}", [
            'username' => $username,
            'page_name' => $page_name,
            'access_time' => date('Y-m-d H:i:s')
        ]);
    }
    
    public function logSystemAction($user_id, $username, $action, $target = '', $details = []) {
        $description = "User '{$username}' performed {$action}";
        if ($target) {
            $description .= " on {$target}";
        }
        
        return $this->logActivity($user_id, 'system_action', $description, array_merge([
            'username' => $username,
            'action' => $action,
            'target' => $target,
            'action_time' => date('Y-m-d H:i:s')
        ], $details));
    }
    
    /**
     * Setup Activity Logs system - creates table and indexes if they don't exist
     */
    public function setupActivityLogsSystem() {
        $setup_messages = [];
        
        // Create Activity_Logs table
        $sql = "
        CREATE TABLE IF NOT EXISTS Activity_Logs (
            ID INT AUTO_INCREMENT PRIMARY KEY,
            User_ID INT NOT NULL,
            Activity_Type VARCHAR(50) NOT NULL,
            Activity_Description TEXT NOT NULL,
            Details JSON,
            IP_Address VARCHAR(45),
            User_Agent TEXT,
            Timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            Status ENUM('active', 'archived') DEFAULT 'active',
            Seen TINYINT(1) DEFAULT 0,
            FOREIGN KEY (User_ID) REFERENCES User(User_ID) ON DELETE CASCADE
        );
        ";
        
        if ($this->conn->query($sql) === TRUE) {
            $setup_messages[] = "âœ“ Activity_Logs table created successfully";
        } else {
            $setup_messages[] = "âœ— Error creating table: " . $this->conn->error;
        }
        
        // Create indexes for better performance
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_activity_logs_user_timestamp ON Activity_Logs(User_ID, Timestamp DESC)",
            "CREATE INDEX IF NOT EXISTS idx_activity_logs_type ON Activity_Logs(Activity_Type)",
            "CREATE INDEX IF NOT EXISTS idx_activity_logs_status ON Activity_Logs(Status)",
            "CREATE INDEX IF NOT EXISTS idx_activity_logs_seen ON Activity_Logs(Seen)"
        ];
        
        foreach ($indexes as $index_sql) {
            if ($this->conn->query($index_sql) === TRUE) {
                $setup_messages[] = "âœ“ Index created successfully";
            } else {
                $setup_messages[] = "âœ— Error creating index: " . $this->conn->error;
            }
        }
        
        // Migrate existing login data from Login_Tracker to Activity_Logs
        $migrate_sql = "
        INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Timestamp, Status, Seen)
        SELECT 
            User_ID, 
            'login' as Activity_Type,
            CONCAT('User logged into the system') as Activity_Description,
            Login_Time as Timestamp,
            Status,
            Seen
        FROM Login_Tracker
        WHERE NOT EXISTS (
            SELECT 1 FROM Activity_Logs 
            WHERE Activity_Logs.User_ID = Login_Tracker.User_ID 
            AND Activity_Logs.Timestamp = Login_Tracker.Login_Time
            AND Activity_Logs.Activity_Type = 'login'
        )
        ";
        
        if ($this->conn->query($migrate_sql) === TRUE) {
            $migrated_count = $this->conn->affected_rows;
            $setup_messages[] = "âœ“ Migrated {$migrated_count} login records to Activity_Logs";
        } else {
            $setup_messages[] = "âœ— Error migrating data: " . $this->conn->error;
        }
        
        return $setup_messages;
    }
}

// Initialize ActivityLogger
$activityLogger = new ActivityLogger($conn);

// Auto-setup Activity Logs system if setup parameter is provided
if (isset($_GET['setup']) && $_GET['setup'] === 'activity_logs') {
    $setup_results = $activityLogger->setupActivityLogsSystem();
    echo "<div style='background: #f0f8ff; padding: 20px; margin: 20px; border-radius: 8px; font-family: monospace;'>";
    echo "<h3>ðŸ”§ Activity Logs System Setup</h3>";
    foreach ($setup_results as $message) {
        echo "<p>" . htmlspecialchars($message) . "</p>";
    }
    echo "<p><strong>ðŸŽ‰ Activity Logs system setup complete!</strong></p>";
    echo "<p><a href='notifications.php'>â† Back to Activity Logs</a></p>";
    echo "</div>";
    exit;
}

// Fetch recent active activity logs (latest first)
$activities = $conn->query("SELECT al.*, u.FName, u.LName, u.Profile_Picture FROM Activity_Logs al JOIN User u ON al.User_ID = u.User_ID WHERE al.Status = 'active' ORDER BY al.Timestamp DESC LIMIT 50");
// Fetch archived activity logs
$archived_activities = $conn->query("SELECT al.*, u.FName, u.LName, u.Profile_Picture FROM Activity_Logs al JOIN User u ON al.User_ID = u.User_ID WHERE al.Status = 'archived' ORDER BY al.Timestamp DESC LIMIT 50");
// Mark all notifications as seen when this page is loaded
$conn->query("UPDATE Activity_Logs SET Seen = 1 WHERE Seen = 0");
// Check if there are any unseen notifications for the badge
$badgeResult = $conn->query("SELECT COUNT(*) as cnt FROM Activity_Logs WHERE Seen = 0");
$badgeRow = $badgeResult ? $badgeResult->fetch_assoc() : ["cnt" => 0];
$hasNotifications = ($badgeRow["cnt"] > 0);
// Archive activity record
if (isset($_GET['archive'])) {
    $archiveId = intval($_GET['archive']);
    $stmt = $conn->prepare("UPDATE Activity_Logs SET Status = 'archived' WHERE ID = ?");
    $stmt->bind_param("i", $archiveId);
    $stmt->execute();
    header('Location: notifications.php');
    exit;
}
// Restore activity record
if (isset($_GET['restore'])) {
    $restoreId = intval($_GET['restore']);
    $stmt = $conn->prepare("UPDATE Activity_Logs SET Status = 'active' WHERE ID = ?");
    $stmt->bind_param("i", $restoreId);
    $stmt->execute();
    header('Location: notifications.php');
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
  <title>Activity Logs - System Monitoring</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    :root {
      --sidebar-bg: #ff7e94;
      --main-bg: #f7bfc3;
    }
    
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
      padding: 36px 0 0 80px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      width: 100%;
      min-height: 100vh;
      height: 100vh;
      overflow-y: auto;
      margin-left: 80px;
    }
    .topbar {
      width: 100%;
      display: flex;
      justify-content: flex-start;
      align-items: center;
      padding: 0 0 0 48px;
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
      text-align: left;
    }
    .notifications-list {
      width: 90%;
      margin: 0 auto;
      background: none;
      max-width: 1000px;
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
      .title, .topbar {
        margin-left: 12px;
        padding-left: 12px;
      }
      .notifications-list {
        width: 95%;
        max-width: 100vw;
        margin: 0 auto;
      }
    }
    /* Floating Archive Button */
    .fab-archive {
      position: fixed;
      right: 24px;
      bottom: 24px;
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: #ff7e94;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
      box-shadow: 0 6px 16px rgba(0,0,0,0.2);
      cursor: pointer;
      z-index: 2100;
      border: none;
      transition: background 0.2s, transform 0.05s;
    }
    .fab-archive:hover { background: #ec3462; }
    .fab-archive:active { transform: translateY(1px); }
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
      color: #222;
    }
    .archivemodal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
      margin-top: 20px;
    }
    .btn-secondary {
      background: #fff;
      color: #ec3462;
      border: 2px solid #ec3462;
      border-radius: 6px;
      padding: 10px 18px;
      font-weight: bold;
      cursor: pointer;
    }
    .btn-secondary:hover { background: #ffe6ee; }
  </style>
</head>
<body onload="showCookieLoader()">
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

      <div class="nav-icon active" title="User Logs" onclick="window.location.href='notifications.php'" style="position:relative;">
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
    <?php echo formatDay(date('Y-m-d')); ?>
  </div>
    <div class="title">Activity Logs</div>
    <br>
    <div class="notifications-list">
      <?php while ($row = $activities->fetch_assoc()): ?>
        <div class="notification-card">
          <div class="notification-info">
            <div class="notification-avatar">
              <img src="<?= $row['Profile_Picture'] && file_exists($row['Profile_Picture']) ? htmlspecialchars($row['Profile_Picture']) : 'img/default-avatar.png' ?>" alt="Avatar" style="width:38px;height:38px;border-radius:50%;object-fit:cover;">
            </div>
            <div class="notification-details">
              <div class="notification-title"><?= ucfirst(str_replace('_', ' ', $row['Activity_Type'])) ?></div>
              <div class="notification-message">
                <?= htmlspecialchars($row['Activity_Description']) ?>
                    </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:18px;position:relative;">
            <div class="notification-date"><?= formatDate($row['Timestamp']) ?></div>
            <div class="notification-menu" onclick="showNotifMenu(event, <?= $row['ID'] ?>)">&#8942;
              <div class="notif-action-modal" id="notif-menu-<?= $row['ID'] ?>">
                <form method="get" style="margin:0;">
                  <input type="hidden" name="archive" value="<?= $row['ID'] ?>">
                  <button type="submit">Archive</button>
                </form>
                    </div>
            </div>
          
      </div>
        </div>
      <?php endwhile; ?>
          </div>
  </div>
</div>

<!-- Floating Archive Button -->
<button class="fab-archive" id="openArchiveModal" title="View Archived Logs">ðŸ“¦</button>

<!-- Archive Modal -->
<div id="archivemodalOverlay" class="archivemodal-overlay">
  <div class="archivemodal" role="dialog" aria-modal="true" aria-labelledby="archiveTitle">
    <div class="archivemodal-title" id="archiveTitle">Archived Activity Logs</div>
    <div class="notifications-list">
      <?php while ($archived_row = $archived_activities->fetch_assoc()): ?>
        <div class="notification-card">
          <div class="notification-info">
            <div class="notification-avatar">
              <img src="<?= $archived_row['Profile_Picture'] && file_exists($archived_row['Profile_Picture']) ? htmlspecialchars($archived_row['Profile_Picture']) : 'img/default-avatar.png' ?>" alt="Avatar" style="width:38px;height:38px;border-radius:50%;object-fit:cover;">
            </div>
            <div class="notification-details">
              <div class="notification-title"><?= ucfirst(str_replace('_', ' ', $archived_row['Activity_Type'])) ?></div>
              <div class="notification-message">
                <?= htmlspecialchars($archived_row['Activity_Description']) ?>
                    </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:18px;position:relative;">
            <div class="notification-date"><?= formatDate($archived_row['Timestamp']) ?></div>
            <div class="notification-menu" onclick="showNotifMenu(event, <?= $archived_row['ID'] ?>)">&#8942;
              <div class="notif-action-modal" id="notif-menu-<?= $archived_row['ID'] ?>">
                <form method="get" style="margin:0;">
                  <input type="hidden" name="restore" value="<?= $archived_row['ID'] ?>">
                  <button type="submit">Restore</button>
                </form>
                    </div>
            </div>
          
      </div>
        </div>
      <?php endwhile; ?>
    </div>
    <div class="archivemodal-actions">
      <button type="button" class="btn-secondary" id="cancelArchiveModal">Close</button>
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
  // Logout modal logic (reuse from dashboard if needed)
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
// Archive modal behavior
(function(){
  const overlay = document.getElementById('archivemodalOverlay');
  const openBtn = document.getElementById('openArchiveModal');
  const cancelBtn = document.getElementById('cancelArchiveModal');
  if (openBtn) openBtn.addEventListener('click', () => { overlay.style.display = 'flex'; });
  if (cancelBtn) cancelBtn.addEventListener('click', () => { overlay.style.display = 'none'; });
  window.addEventListener('click', function(e){ if (e.target === overlay) overlay.style.display = 'none'; });
})();
</script>
</body>
</html> 
<?php include 'loadingscreen.html'; ?>




