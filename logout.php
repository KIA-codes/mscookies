<?php
session_start();

// Get user info before destroying session
$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? 'Unknown';

// Connect to database and log logout activity
if ($user_id) {
    $conn = new mysqli("localhost", "root", "", "mscookies");
    
    // Include ActivityLogger class (embedded version from notifications.php)
    class ActivityLogger {
        private $conn;
        
        public function __construct($database_connection) {
            $this->conn = $database_connection;
        }
        
        public function logActivity($user_id, $activity_type, $description, $details = []) {
            try {
                $ip_address = $this->getClientIP();
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
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
        
        public function logLogout($user_id, $username) {
            return $this->logActivity($user_id, 'logout', "User '{$username}' logged out of the system", [
                'username' => $username,
                'logout_time' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    // Log the logout activity
    $activityLogger = new ActivityLogger($conn);
    $activityLogger->logLogout($user_id, $username);
    
    $conn->close();
}

// Destroy session and redirect
session_unset();
session_destroy();
header("Location: index.php");
exit;
?>