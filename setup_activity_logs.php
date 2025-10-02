<?php
/**
 * Setup script for Activity Logs system
 * Run this once to create the Activity_Logs table
 */

$conn = new mysqli("localhost", "root", "", "mscookies");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Setting up Activity Logs system...\n";

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

if ($conn->query($sql) === TRUE) {
    echo "✓ Activity_Logs table created successfully\n";
} else {
    echo "✗ Error creating table: " . $conn->error . "\n";
}

// Create indexes for better performance
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_activity_logs_user_timestamp ON Activity_Logs(User_ID, Timestamp DESC)",
    "CREATE INDEX IF NOT EXISTS idx_activity_logs_type ON Activity_Logs(Activity_Type)",
    "CREATE INDEX IF NOT EXISTS idx_activity_logs_status ON Activity_Logs(Status)",
    "CREATE INDEX IF NOT EXISTS idx_activity_logs_seen ON Activity_Logs(Seen)"
];

foreach ($indexes as $index_sql) {
    if ($conn->query($index_sql) === TRUE) {
        echo "✓ Index created successfully\n";
    } else {
        echo "✗ Error creating index: " . $conn->error . "\n";
    }
}

// Migrate existing login data from Login_Tracker to Activity_Logs
echo "\nMigrating existing login data...\n";

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

if ($conn->query($migrate_sql) === TRUE) {
    $migrated_count = $conn->affected_rows;
    echo "✓ Migrated {$migrated_count} login records to Activity_Logs\n";
} else {
    echo "✗ Error migrating data: " . $conn->error . "\n";
}

echo "\n🎉 Activity Logs system setup complete!\n";
echo "You can now use the ActivityLogger class to log user activities.\n";

$conn->close();
?>
