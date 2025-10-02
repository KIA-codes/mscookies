<?php
$conn = new mysqli("localhost", "root", "", "mscookies");

// === Gather descriptive data ===

// Total sales & total orders
$totalSalesQuery = $conn->query("SELECT SUM(Subtotal) as totalSales, COUNT(DISTINCT Order_Code) as totalOrders FROM sales");
$totals = $totalSalesQuery->fetch_assoc();
$totalSales = $totals['totalSales'] ?? 0;
$totalOrders = $totals['totalOrders'] ?? 0;

// Average sales per order
$avgSales = $totalOrders > 0 ? $totalSales / $totalOrders : 0;

// Best-selling product (all time)
$topProductQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 1
");
$topProduct = $topProductQuery->fetch_assoc();

// Peak sales month
$peakMonthQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%Y-%M') as month, SUM(Subtotal) as total
    FROM sales
    GROUP BY month
    ORDER BY total DESC
    LIMIT 1
");
$peakMonth = $peakMonthQuery->fetch_assoc();

// Recent sales (last 5 orders)
$recentSalesQuery = $conn->query("
    SELECT DATE_FORMAT(Sales_Date, '%b %d, %Y') as date, Customer_Name, Subtotal, p.Product_Name, s.Quantity
    FROM sales s
    JOIN product p ON s.Product_ID = p.Product_ID
    ORDER BY Sales_Date DESC
    LIMIT 5
");
$recentSales = $recentSalesQuery->fetch_all(MYSQLI_ASSOC);

// Top 3 products from recent sales
$topRecentProductsQuery = $conn->query("
    SELECT p.Product_Name, SUM(s.Quantity) as qty
    FROM (
        SELECT * FROM sales ORDER BY Sales_Date DESC LIMIT 5
    ) s
    JOIN product p ON s.Product_ID = p.Product_ID
    GROUP BY p.Product_Name
    ORDER BY qty DESC
    LIMIT 3
");
$topRecentProducts = $topRecentProductsQuery->fetch_all(MYSQLI_ASSOC);

// Build report sentences
$report = [];
$report[] = "The system has recorded a total of ₱" . number_format($totalSales, 2) . " in sales across " . number_format($totalOrders) . " orders.";
$report[] = "On average, each order contributes ₱" . number_format($avgSales, 2) . " in sales.";
if ($topProduct) {
    $report[] = "The top-selling product overall is " . htmlspecialchars($topProduct['Product_Name']) . " with " . $topProduct['qty'] . " units sold.";
}
if ($peakMonth) {
    $report[] = "The highest sales were recorded in " . $peakMonth['month'] . ", reaching ₱" . number_format($peakMonth['total'], 2) . ".";
}
$report[] = "Recent sales trends are shown below, including the top 3 products in the most recent transactions.";
?>
<!DOCTYPE html>
<html>
<head>
  <title>Descriptive Analytics Report</title>
  <style>
    .report-btn {
      background: #f98ca3;
      border: none;
      padding: 12px 18px;
      border-radius: 6px;
      color: white;
      font-weight: bold;
      cursor: pointer;
      margin: 20px;
    }
    .reportmodal {
      display: none; position: fixed; z-index: 1000;
      left: 50; top: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.5);
      justify-content: center; align-items: center;
      font-family: Arial;
      font-size: small;
    }
    .reportmodal-content {
      background: white; padding: 25px; border-radius: 12px;
      width: 750px; max-width: 95%;
      animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn { from {opacity: 0; transform: scale(0.9);} to {opacity: 1; transform: scale(1);} }
    .closebtn {
  font-size: 24px;
  cursor: pointer;
  color: #333;
}
    .close:hover { color: black; }
    .report-text { text-align: left; font-size: 15px; line-height: 1.6; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    th { background: #f98ca3; color: white; }
 .download-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 6px;
  background: transparent;
  border-radius: 6px;
  transition: 0.3s;
  color:#333;
}
.download-btn:hover {
  color: #d96a87;
}
.reportmodal-header {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 10px;
}
  </style>
</head>
<body>

<!-- Button -->
<button class="report-btn" onclick="openreportModal()">📄 Generate Report</button>

<!-- Modal -->
<div id="reportModal" class="reportmodal">
  <div class="reportmodal-content">
    <div class="reportmodal-header">
      <a href="export_report.php" target="_blank" class="download-btn" title="Download Report">
        <svg class="w-[20px] h-[20px] text-gray-800" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" 
             width="24" height="24" fill="none" viewBox="0 0 24 24">
          <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                d="M12 13V4M7 14H5a1 1 0 0 0-1 1v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4a1 1 0 0 0-1-1h-2m-1-5-4 5-4-5m9 8h.01"/>
        </svg>
      </a>
      <span class="closebtn" onclick="closereportModal()">&times;</span>
      
    </div>
    <h2>📊 Sales Report Summary</h2>
    <div class="report-text">
      <?php foreach ($report as $line): ?>
        <p><?= $line ?></p>
      <?php endforeach; ?>
    </div>

    <h3>🛒 Recent Sales (Last 5 Orders)</h3>
    <table>
      <tr><th>Date</th><th>Customer</th><th>Product</th><th>Qty</th><th>Amount (₱)</th></tr>
      <?php foreach ($recentSales as $sale): ?>
        <tr>
          <td><?= $sale['date'] ?></td>
          <td><?= htmlspecialchars($sale['Customer_Name']) ?></td>
          <td><?= htmlspecialchars($sale['Product_Name']) ?></td>
          <td><?= $sale['Quantity'] ?></td>
          <td>₱<?= number_format($sale['Subtotal'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h3>🏆 Top 3 Products (From Recent Sales)</h3>
    <table>
      <tr><th>Product</th><th>Quantity Sold</th></tr>
      <?php foreach ($topRecentProducts as $prod): ?>
        <tr>
          <td><?= htmlspecialchars($prod['Product_Name']) ?></td>
          <td><?= $prod['qty'] ?> pcs</td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>

<script>
function openreportModal() {
  document.getElementById("reportModal").style.display = "flex";
}
function closereportModal() {
  document.getElementById("reportModal").style.display = "none";
}
window.onclick = function(event) {
  if (event.target === document.getElementById("reportModal")) {
    closereportModal();
  }
}
</script>

</body>
</html>
