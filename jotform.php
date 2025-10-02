<?php
session_start();
$conn = new mysqli("localhost", "root", "", "mscookies");

function js_alert_redirect($msg, $redirect) {
    echo "<script>alert(" . json_encode($msg) . "); window.location.href='$redirect';</script>";
    exit;
}

if (isset($_POST['import']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === 0) {
    $file = $_FILES['csv_file']['tmp_name'];

    if (!is_uploaded_file($file)) {
        js_alert_redirect("❌ Invalid uploaded file.", "sales_history.php");
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        js_alert_redirect("❌ Failed to open CSV file.", "sales_history.php");
    }

    $totalInserted = 0;
    $currentYear = date('Y');
    $sequence = 0;
    $missingProducts = [];
    $rowsToInsert = [];

    // Get last Order_Code
    $latest = $conn->query("SELECT Order_Code FROM sales WHERE Order_Code LIKE '{$currentYear}%' ORDER BY Order_Code DESC LIMIT 1");
    if ($latest && $latest->num_rows > 0) {
        $lastId = $latest->fetch_assoc()['Order_Code'];
        $sequence = (int)substr($lastId, 4);
    }

    $conn->begin_transaction();

    $header = fgetcsv($handle); // Skip header

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 16) continue;

        $sales_date_raw = trim($row[0]);
        $myProducts = trim($row[1]);
        $first_name = trim($row[3]);
        $last_name = trim($row[4]);
        $payment = trim($row[15]);

        $customer = "$first_name $last_name";
        $sales_date = date('Y-m-d H:i:s', strtotime($sales_date_raw));

        // Generate Order ID
        $sequence++;
        $orderId = $currentYear . str_pad($sequence, 4, '0', STR_PAD_LEFT);

        // Split multiline product string
        $lines = explode("\n", $myProducts);

        foreach ($lines as $line) {
            $line = trim($line);
            if (stripos($line, 'Total:') !== false) continue;

            if (preg_match('/^(.*?)\s*\(.*?Quantity:\s*(\d+)/i', $line, $matches)) {
                $productName = strtolower(trim($matches[1]));
                $qty = (int)$matches[2];

                // Find Product_ID and price
                $stmt = $conn->prepare("SELECT Product_ID, Product_Price FROM product WHERE LOWER(Product_Name) = ?");
                $stmt->bind_param("s", $productName);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res && $res->num_rows > 0) {
                    $product = $res->fetch_assoc();
                    $productId = $product['Product_ID'];
                    $price = $product['Product_Price'];
                    $subtotal = $qty * $price;

                    $rowsToInsert[] = [
                        'Order_Code' => $orderId,
                        'Customer_Name' => $customer,
                        'Sales_Date' => $sales_date,
                        'Payment_Method' => $payment,
                        'Product_ID' => $productId,
                        'Quantity' => $qty,
                        'Subtotal' => $subtotal
                    ];
                } else {
                    $missingProducts[] = $productName;
                }
            }
        }
    }

    fclose($handle);

    if (!empty($missingProducts)) {
        $conn->rollback();
        js_alert_redirect("❌ Import failed. Missing products: " . implode(", ", array_unique($missingProducts)), "sales_history.php");
    }

    if (empty($rowsToInsert)) {
        $conn->rollback();
        js_alert_redirect("❌ No valid data to import. Check the CSV format.", "sales_history.php");
    }

    $insert = $conn->prepare("INSERT INTO sales (Order_Code, Customer_Name, Sales_Date, Payment_Method, Product_ID, Quantity, Subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($rowsToInsert as $row) {
        $insert->bind_param(
            "sssssid",
            $row['Order_Code'],
            $row['Customer_Name'],
            $row['Sales_Date'],
            $row['Payment_Method'],
            $row['Product_ID'],
            $row['Quantity'],
            $row['Subtotal']
        );
        $insert->execute();
        $totalInserted++;
    }

    $conn->commit();
    
    // Log import activity
    try {
        $user_id = $_SESSION['user_id'] ?? 1; // Default to admin if no session
        $username = $_SESSION['username'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $filename = $_FILES['csv_file']['name'] ?? 'jotform_import.csv';
        $filesize = $_FILES['csv_file']['size'] ?? 0;
        
        // Calculate total amount and collect order codes
        $total_amount = 0;
        $order_codes = [];
        $unique_customers = [];
        foreach ($rowsToInsert as $row) {
            $total_amount += $row['Subtotal'];
            if (!in_array($row['Order_Code'], $order_codes)) {
                $order_codes[] = $row['Order_Code'];
            }
            if (!in_array($row['Customer_Name'], $unique_customers)) {
                $unique_customers[] = $row['Customer_Name'];
            }
        }
        
        $details = json_encode([
            'username' => $username,
            'file_name' => $filename,
            'file_size' => $filesize,
            'records_imported' => $totalInserted,
            'unique_orders' => count($order_codes),
            'unique_customers' => count($unique_customers),
            'total_amount' => $total_amount,
            'order_codes' => $order_codes,
            'import_time' => date('Y-m-d H:i:s'),
            'import_type' => 'JotForm CSV Data'
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'data_import', ?, ?, ?, ?)");
        $description = "User '{$username}' imported {$totalInserted} JotForm sales records from CSV file '{$filename}' covering " . count($order_codes) . " orders totaling ₱" . number_format($total_amount, 2);
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $log_error) {
        error_log("JotForm import logging error: " . $log_error->getMessage());
    }
    
    js_alert_redirect("✅ $totalInserted row(s) imported successfully.", "sales_history.php");
} else {
    js_alert_redirect("❌ File upload failed. Please choose a valid CSV file.", "sales_history.php");
}
?>