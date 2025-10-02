<?php
// Simple script to check February 2025 sales total
$conn = new mysqli("localhost", "root", "", "mscookies");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query for February 2025 sales
$query = "
    SELECT 
        DATE_FORMAT(Sales_Date, '%Y-%m') AS month,
        SUM(Subtotal) AS total_sales,
        COUNT(DISTINCT Order_Code) AS total_orders,
        COUNT(*) AS total_items
    FROM sales 
    WHERE YEAR(Sales_Date) = 2025 AND MONTH(Sales_Date) = 2
    GROUP BY DATE_FORMAT(Sales_Date, '%Y-%m')
";

$result = $conn->query($query);

echo "<h2>February 2025 Sales Check</h2>";

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo "<table border='1' style='border-collapse: collapse; padding: 10px;'>";
    echo "<tr><th>Month</th><th>Total Sales</th><th>Total Orders</th><th>Total Items</th></tr>";
    echo "<tr>";
    echo "<td>" . $row['month'] . "</td>";
    echo "<td>₱" . number_format($row['total_sales'], 2) . "</td>";
    echo "<td>" . number_format($row['total_orders']) . "</td>";
    echo "<td>" . number_format($row['total_items']) . "</td>";
    echo "</tr>";
    echo "</table>";
    
    echo "<br><strong>Result:</strong> ";
    if (number_format($row['total_sales'], 2) == "11,733.00") {
        echo "<span style='color: green;'>✓ CONFIRMED: Total sales for February 2025 is exactly ₱11,733.00</span>";
    } else {
        echo "<span style='color: red;'>✗ MISMATCH: Total sales for February 2025 is ₱" . number_format($row['total_sales'], 2) . " (not ₱11,733.00)</span>";
    }
} else {
    echo "<p style='color: orange;'>No sales data found for February 2025.</p>";
}

// Also show detailed breakdown
echo "<h3>Detailed Breakdown for February 2025:</h3>";
$detailQuery = "
    SELECT 
        Sales_Date,
        Customer_Name,
        p.Product_Name,
        Quantity,
        Unit_Price,
        Subtotal
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    WHERE YEAR(Sales_Date) = 2025 AND MONTH(Sales_Date) = 2
    ORDER BY Sales_Date DESC
";

$detailResult = $conn->query($detailQuery);

if ($detailResult && $detailResult->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; padding: 5px; font-size: 12px;'>";
    echo "<tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr>";
    
    $runningTotal = 0;
    while ($detail = $detailResult->fetch_assoc()) {
        $runningTotal += $detail['Subtotal'];
        echo "<tr>";
        echo "<td>" . date('M d, Y', strtotime($detail['Sales_Date'])) . "</td>";
        echo "<td>" . htmlspecialchars($detail['Customer_Name']) . "</td>";
        echo "<td>" . htmlspecialchars($detail['Product_Name']) . "</td>";
        echo "<td>" . $detail['Quantity'] . "</td>";
        echo "<td>₱" . number_format($detail['Unit_Price'], 2) . "</td>";
        echo "<td>₱" . number_format($detail['Subtotal'], 2) . "</td>";
        echo "</tr>";
    }
    echo "<tr style='background-color: #f0f0f0; font-weight: bold;'>";
    echo "<td colspan='5'>TOTAL:</td>";
    echo "<td>₱" . number_format($runningTotal, 2) . "</td>";
    echo "</tr>";
    echo "</table>";
} else {
    echo "<p>No detailed sales records found for February 2025.</p>";
}

$conn->close();
?>
