<?php
session_start();
$conn = new mysqli("localhost", "root", "", "mscookies");
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

if (!isset($_SESSION['user_id'])) {
    echo "<script>alert('Please log in to import sales.'); window.location.href='login.php';</script>";
    exit;
}

if (!isset($_POST['import']) || !isset($_FILES['csv_file'])) {
    echo "<script>alert('No file uploaded.'); window.location.href='sales_history.php';</script>";
    exit;
}

$file = $_FILES['csv_file']['tmp_name'];
if (!is_uploaded_file($file) || $_FILES['csv_file']['size'] == 0) {
    echo "<script>alert('Invalid or empty file.'); window.location.href='sales_history.php';</script>";
    exit;
}

$handle = fopen($file, "r");
if ($handle === false) {
    echo "<script>alert('Unable to read uploaded file.'); window.location.href='sales_history.php';</script>";
    exit;
}

// Read header row (if present) and map columns (case-insensitive)
$header = fgetcsv($handle);
$hasHeader = false;
$map = [];
if ($header !== false) {
    // remove BOM from first header cell if present
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    // determine if row looks like header (contains non-numeric text)
    $nonNumericCount = 0;
    foreach ($header as $h) {
        if (!is_numeric($h) && trim($h) !== '') $nonNumericCount++;
    }
    // treat as header if more than 1 non-empty non-numeric cell
    if ($nonNumericCount >= 1) {
        $hasHeader = true;
        foreach ($header as $idx => $h) {
            $h = trim($h);
            $h = preg_replace('/\x{FEFF}/u', '', $h); // remove BOM
            $map[mb_strtolower($h)] = $idx;
        }
    } else {
        // not a header - rewind file to start of first data row
        rewind($handle);
    }
}

// helper finder: check variants and return index or null
function find_col_index($map, $variants) {
    foreach ($variants as $v) {
        $k = mb_strtolower($v);
        if (isset($map[$k])) return $map[$k];
    }
    return null;
}

// If header exists, find indices; otherwise we'll use positional fallback
if ($hasHeader) {
    $dateCol       = find_col_index($map, ['DATE','date','sales_date','order_date','date of order','order date']);
    $customerCol   = find_col_index($map, ['CUSTOMER NAME','customer','customer_name','client','buyer']);
    $productCodeCol= find_col_index($map, ['PRODUCT CODE','product_code','product code','sku','code']);
    $productNameCol= find_col_index($map, ['PRODUCT NAME','product_name','product name','product']);
    $quantityCol   = find_col_index($map, ['QUANTITY','quantity','qty','q']);
    $priceCol      = find_col_index($map, ['PRICE','unit_price','price','unit price','price_per_unit']);
    $subtotalCol   = find_col_index($map, ['SUBTOTAL','subtotal','total','amount','line_total']);
    $orderCodeCol  = find_col_index($map, ['ORDER ID','order_code','order code','orderid','order id','order']);
    $paymentCol    = find_col_index($map, ['PAYMENT METHOD','payment_method','payment','payment type','payment_method']);
} else {
    // We'll use positional fallback when reading each row
    $dateCol = $customerCol = $productCodeCol = $productNameCol = $quantityCol = $priceCol = $subtotalCol = $orderCodeCol = $paymentCol = null;
}

// Prepare collecting rows
$salesData = [];
$errors = [];
$rowNum = 0;

while (($row = fgetcsv($handle, 0, ",")) !== FALSE) {
    $rowNum++;

    // Skip empty rows
    $allEmpty = true;
    foreach ($row as $c) {
        if (trim($c) !== '') { $allEmpty = false; break; }
    }
    if ($allEmpty) continue;

    // If header existed -> read by map; else fallback to positions:
    if ($hasHeader) {
        $date_raw       = isset($dateCol) && $dateCol !== null && isset($row[$dateCol]) ? trim($row[$dateCol]) : '';
        $customer_name  = $customerCol !== null && isset($row[$customerCol]) ? trim($row[$customerCol]) : '';
        $product_code   = $productCodeCol !== null && isset($row[$productCodeCol]) ? trim($row[$productCodeCol]) : '';
        $product_name   = $productNameCol !== null && isset($row[$productNameCol]) ? trim($row[$productNameCol]) : '';
        $quantity_raw   = $quantityCol !== null && isset($row[$quantityCol]) ? trim($row[$quantityCol]) : '0';
        $price_raw      = $priceCol !== null && isset($row[$priceCol]) ? trim($row[$priceCol]) : '';
        $subtotal_raw   = $subtotalCol !== null && isset($row[$subtotalCol]) ? trim($row[$subtotalCol]) : '';
        $order_code     = $orderCodeCol !== null && isset($row[$orderCodeCol]) ? trim($row[$orderCodeCol]) : '';
        $payment_method = $paymentCol !== null && isset($row[$paymentCol]) ? trim($row[$paymentCol]) : '';
    } else {
        // positional fallback (common format: Date, Customer, ProductCode, Quantity, Price/Subtotal, OrderCode, Payment)
        $date_raw       = isset($row[0]) ? trim($row[0]) : '';
        $customer_name  = isset($row[1]) ? trim($row[1]) : '';
        $product_code   = isset($row[2]) ? trim($row[2]) : '';
        $product_name   = ''; // not present in fallback
        $quantity_raw   = isset($row[3]) ? trim($row[3]) : '0';
        $price_raw      = isset($row[4]) ? trim($row[4]) : '';
        $subtotal_raw   = isset($row[5]) ? trim($row[5]) : '';
        $order_code     = isset($row[6]) ? trim($row[6]) : '';
        $payment_method = isset($row[7]) ? trim($row[7]) : '';
        // note: if your CSV order is slightly different adjust this fallback block
    }

    // Ensure customer and payment preserved; do not overwrite with "Unknown" unless empty
    if ($customer_name === '') $customer_name = 'Unknown Customer';
    if ($payment_method === '') $payment_method = 'Unknown';

    // Parse numbers
    $quantity = (int) filter_var($quantity_raw, FILTER_SANITIZE_NUMBER_INT);
    $unit_price = $price_raw !== '' ? floatval(str_replace(['₱', ',', ' '], ['', '', ''], $price_raw)) : null;
    $subtotal = $subtotal_raw !== '' ? floatval(str_replace(['₱', ',', ' '], ['', '', ''], $subtotal_raw)) : null;

    

    // If order_code empty, generate a 6-digit zero padded random code
    if ($order_code === '') {
        $order_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    // Date: convert to YYYY-MM-DD (no time). If conversion fails, skip row with error.
    if ($date_raw !== '') {
        $ts = strtotime($date_raw);
        if ($ts === false) {
            $errors[] = "Row $rowNum: Invalid date format: '$date_raw'";
            continue;
        }
        $sales_date = date('Y-m-d', $ts);
    } else {
        // if no date provided, use today
        $sales_date = date('Y-m-d');
    }

    // Find product_id using code or name
    $product_id = null;
    if ($product_code !== '') {
        $stmtP = $conn->prepare("SELECT Product_ID FROM product WHERE Product_Code = ? LIMIT 1");
        if ($stmtP) {
            $stmtP->bind_param("s", $product_code);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            if ($resP && $resP->num_rows > 0) {
                $product_id = (int) $resP->fetch_assoc()['Product_ID'];
            }
            $stmtP->close();
        }
    }
    if ($product_id === null && $product_name !== '') {
        $stmtP = $conn->prepare("SELECT Product_ID FROM product WHERE Product_Name = ? LIMIT 1");
        if ($stmtP) {
            $stmtP->bind_param("s", $product_name);
            $stmtP->execute();
            $resP = $stmtP->get_result();
            if ($resP && $resP->num_rows > 0) {
                $product_id = (int) $resP->fetch_assoc()['Product_ID'];
            }
            $stmtP->close();
        }
    }

    if ($product_id === null) {
        $errors[] = "Row $rowNum: Product not found (code='{$product_code}' name='{$product_name}')";
        continue;
    }

    // Collect row (do not overwrite CSV values)
    $salesData[] = [
        'Order_Code' => $order_code,
        'Customer_Name' => $customer_name,
        'Product_ID' => $product_id,
        'Quantity' => $quantity,
        'Unit_Price' => $unit_price !== null ? $unit_price : 0.0,
        'Subtotal' => $subtotal !== null ? $subtotal : 0.0,
        'User_ID' => (int) $_SESSION['user_id'],
        'Sales_Date' => $sales_date,
        'Payment_Method' => $payment_method,
        'Status' => 'Active'
    ];
}

fclose($handle);

// If there were errors, show them and abort
if (!empty($errors)) {
    $msg = "Upload failed due to the following issues:\\n" . implode("\\n", $errors);
    echo "<script>alert('$msg'); window.location.href='sales_history.php';</script>";
    exit;
}

// Build and execute insert statement (simple fixed column set)
$insertCols = [
    'Order_Code', 'Customer_Name', 'Product_ID',
    'Quantity', 'Unit_Price', 'Subtotal',
    'User_ID', 'Sales_Date', 'Payment_Method', 'Status'
];

$placeholders = implode(',', array_fill(0, count($insertCols), '?'));
$sql = "INSERT INTO Sales (" . implode(',', $insertCols) . ") VALUES ($placeholders)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<script>alert('Prepare failed: " . addslashes($conn->error) . "'); window.location.href='sales_history.php';</script>";
    exit;
}

// We'll bind everything as strings (safer and simpler). MySQL will cast numerics automatically.
$types = str_repeat('s', count($insertCols)); // all strings
$inserted = 0;
$conn->begin_transaction();
try {
    foreach ($salesData as $s) {
        // create values array in same order as $insertCols
        $vals = [
            $s['Order_Code'],
            $s['Customer_Name'],
            (string)$s['Product_ID'],
            (string)$s['Quantity'],
            (string)$s['Unit_Price'],
            (string)$s['Subtotal'],
            (string)$s['User_ID'],
            $s['Sales_Date'],
            $s['Payment_Method'],
            $s['Status']
        ];

        // bind params dynamically (mysqli requires references)
        $bind_names = [];
        $bind_names[] = $types;
        for ($i = 0; $i < count($vals); $i++) {
            $bind_names[] = &$vals[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);

        if (!$stmt->execute()) {
            throw new Exception("Insert failed on Order {$s['Order_Code']}: " . $stmt->error);
        }
        $inserted++;
    }
    $conn->commit();
    
    // Log import activity
    try {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $filename = $_FILES['csv_file']['name'] ?? 'unknown.csv';
        $filesize = $_FILES['csv_file']['size'] ?? 0;
        
        // Calculate total amount and collect order codes
        $total_amount = 0;
        $order_codes = [];
        $unique_customers = [];
        foreach ($salesData as $s) {
            $total_amount += $s['Subtotal'];
            if (!in_array($s['Order_Code'], $order_codes)) {
                $order_codes[] = $s['Order_Code'];
            }
            if (!in_array($s['Customer_Name'], $unique_customers)) {
                $unique_customers[] = $s['Customer_Name'];
            }
        }
        
        $details = json_encode([
            'username' => $username,
            'file_name' => $filename,
            'file_size' => $filesize,
            'records_imported' => $inserted,
            'unique_orders' => count($order_codes),
            'unique_customers' => count($unique_customers),
            'total_amount' => $total_amount,
            'order_codes' => $order_codes,
            'import_time' => date('Y-m-d H:i:s'),
            'import_type' => 'CSV Sales Data'
        ]);
        
        $stmt = $conn->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'data_import', ?, ?, ?, ?)");
        $description = "User '{$username}' imported {$inserted} sales records from CSV file '{$filename}' covering " . count($order_codes) . " orders totaling ₱" . number_format($total_amount, 2);
        $stmt->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    } catch (Exception $log_error) {
        error_log("Import logging error: " . $log_error->getMessage());
    }
    
    echo "<script>alert('Import successful: {$inserted} rows added.'); window.location.href='sales_history.php';</script>";
} catch (Exception $e) {
    $conn->rollback();
    $err = addslashes($e->getMessage());
    echo "<script>alert('Import failed: {$err}'); window.location.href='sales_history.php';</script>";
    exit;
}

$conn->close();
