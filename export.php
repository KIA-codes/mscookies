<?php
session_start();
$conn = new mysqli("localhost", "root", "", "mscookies");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Log download activity
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    try {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $details = json_encode([
            'username' => $username,
            'file_type' => 'CSV Export',
            'file_name' => 'sales_export.csv',
            'download_time' => date('Y-m-d H:i:s')
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'download', ?, ?, ?, ?)");
        $description = "User '{$username}' downloaded sales history CSV export";
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        error_log("Download logging error: " . $e->getMessage());
    }
}

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=sales_export.csv');

// Open PHP output stream
$output = fopen('php://output', 'w');

// Write column headers
fputcsv($output, ['DATE', 'CUSTOMER NAME', 'PRODUCT CODE', 'QUANTITY', 'PRICE', 'ORDER ID', 'PAYMENT METHOD']);

// Fetch only Active sales
$query = "
    SELECT 
        DATE_FORMAT(Sales_Date, '%m/%d/%Y') AS date,
        Customer_Name,
        p.Product_Code,
        s.Quantity,
        s.Unit_Price,
        s.Order_Code,
        s.Payment_Method
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    WHERE s.Status = 'Active'
    ORDER BY s.Sales_Date DESC
";

$result = $conn->query($query);

// Write rows to CSV
while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
}

fclose($output);
$conn->close();
exit;
?>
