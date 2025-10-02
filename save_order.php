<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Not logged in');
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    http_response_code(400);
    exit('Invalid input');
}

$conn = new mysqli("localhost", "root", "", "mscookies");
if ($conn->connect_error) {
    http_response_code(500);
    exit('DB connection failed');
}

$user_id = $_SESSION['user_id'];

// Check if user exists
$user_check = $conn->prepare("SELECT UserType FROM User WHERE User_ID = ?");
$user_check->bind_param("i", $user_id);
$user_check->execute();
$user_result = $user_check->get_result();

if ($user_result->num_rows === 0) {
    http_response_code(401);
    exit('Invalid user');
}

// Validate required fields
if (empty($data['customer_name']) || empty($data['payment_method']) || empty($data['products'])) {
    http_response_code(400);
    exit('Missing required fields');
}

$customer_name = $conn->real_escape_string($data['customer_name']);
$payment_method = $conn->real_escape_string($data['payment_method']);
$sales_date = date('Y-m-d H:i:s');

// ✅ Generate random 6-digit order code (with leading zeros)
$order_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// Begin transaction
$conn->begin_transaction();

try {
    foreach ($data['products'] as $item) {
        $product_id = (int)$item['id'];
        $quantity = (int)$item['quantity'];
        $unit_price = (float)$item['price'];

        // Get Product Details
        $product_stmt = $conn->prepare("SELECT Product_Name, Product_Code FROM Product WHERE Product_ID = ?");
        $product_stmt->bind_param("i", $product_id);
        $product_stmt->execute();
        $product_result = $product_stmt->get_result();

        if ($product_result->num_rows === 0) {
            throw new Exception("Invalid product ID: $product_id");
        }

        $product = $product_result->fetch_assoc();
        $product_name = $product['Product_Name'];
        $product_code = $product['Product_Code'];
        $subtotal = $quantity * $unit_price;

        // ✅ Insert into sales with Status always Active
        $stmt = $conn->prepare("INSERT INTO Sales (
            User_ID, Product_ID, Product_Name, Product_Code, 
            Order_Code, Customer_Name, Payment_Method, 
            Quantity, Unit_Price, Subtotal, Sales_Date, Status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");

        // ✅ 11 placeholders → 11 types
        $stmt->bind_param(
            "iisssssddss", 
            $user_id, $product_id, $product_name, $product_code,
            $order_code, $customer_name, $payment_method,
            $quantity, $unit_price, $subtotal, $sales_date
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert order item: " . $stmt->error);
        }
    }

    $conn->commit();
    
    // Log order creation activity
    try {
        $username = $_SESSION['username'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Calculate total amount and prepare product details
        $total_amount = 0;
        $product_details = [];
        foreach ($data['products'] as $item) {
            $subtotal = (int)$item['quantity'] * (float)$item['price'];
            $total_amount += $subtotal;
            $product_details[] = [
                'product_id' => $item['id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['price'],
                'subtotal' => $subtotal
            ];
        }
        
        $details = json_encode([
            'username' => $username,
            'order_code' => $order_code,
            'customer_name' => $customer_name,
            'payment_method' => $payment_method,
            'total_amount' => $total_amount,
            'product_count' => count($data['products']),
            'products' => $product_details,
            'sales_date' => $sales_date,
            'insert_time' => date('Y-m-d H:i:s')
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'data_insert', ?, ?, ?, ?)");
        $description = "User '{$username}' created new order #{$order_code} for customer '{$customer_name}' with " . count($data['products']) . " item(s) totaling ₱" . number_format($total_amount, 2);
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $log_error) {
        error_log("Order logging error: " . $log_error->getMessage());
    }
    
    echo $order_code; // return order code for reference

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    exit('Error saving order: ' . $e->getMessage());
}

$conn->close();
?>
