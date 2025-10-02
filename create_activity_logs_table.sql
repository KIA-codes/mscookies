-- Create Activity_Logs table for comprehensive activity tracking
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

-- Create index for better performance
CREATE INDEX idx_activity_logs_user_timestamp ON Activity_Logs(User_ID, Timestamp DESC);
CREATE INDEX idx_activity_logs_type ON Activity_Logs(Activity_Type);
CREATE INDEX idx_activity_logs_status ON Activity_Logs(Status);
CREATE INDEX idx_activity_logs_seen ON Activity_Logs(Seen);
