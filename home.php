<?php
session_start();
$conn = new mysqli("localhost", "root", "", "mscookies");

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
  header("Location: login.html");
  exit;
}
//ctrl shift L to edit all at the same time

// Filters
$selected_category = $_GET['category'] ?? 'All';
$selected_subcategory = $_GET['subcategory'] ?? 'All';
$search = $_GET['search'] ?? '';

// Fetch categories & subcategories
$categories = $conn->query("SELECT DISTINCT Category FROM product");
$subcategories = $conn->query("SELECT DISTINCT Subcategory FROM product");

// Filter query
$filter = "WHERE 1";
if ($selected_category !== 'All') {
  $filter .= " AND Category = '" . $conn->real_escape_string($selected_category) . "'";
}
if ($selected_subcategory !== 'All') {
  $filter .= " AND Subcategory = '" . $conn->real_escape_string($selected_subcategory) . "'";
}
if (!empty($search)) {
  $filter .= " AND Product_Name LIKE '%" . $conn->real_escape_string($search) . "%'";
}
$products = $conn->query("SELECT * FROM product $filter");

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $user_id = $_SESSION['user_id'];
  $order_code = $_POST['order_code'];
  $customer_name = $_POST['customer_name'];
  $sales_date = $_POST['sales_date'];
  $payment_method = $_POST['payment_method'];
  $product_ids = $_POST['product_ids'];
  $quantities = $_POST['quantities'];

  for ($i = 0; $i < count($product_ids); $i++) {
    $product_id = $product_ids[$i];
    $quantity = $quantities[$i];

    $product = $conn->query("SELECT Product_Name, Product_Code, Product_Price FROM product WHERE Product_ID = $product_id")->fetch_assoc();
    $product_name = $product['Product_Name'];
    $product_code = $product['Product_Code'];
    $price = $product['Product_Price'];
    $subtotal = $price * $quantity;

    $stmt = $conn->prepare("INSERT INTO Sales (
      Order_Code, Customer_Name, Product_ID, Product_Name, Product_Code,
      Quantity, Unit_Price, Subtotal, User_ID, Sales_Date, Payment_Method
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssisssddiss", $order_code, $customer_name, $product_id, $product_name, $product_code, $quantity, $price, $subtotal, $user_id, $sales_date, $payment_method);
    $stmt->execute();
  }

  echo "<p style='color:green;'>✅ Sale recorded with " . count($product_ids) . " item(s)!</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>MSC Cookies Ordering</title>
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background: #fcdcdc;
      display: flex;
    }

    .sidebar {
      width: 80px;
      background: #f56b89;
      padding: 20px 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 30px;
    }

    .main {
      flex: 1;
      padding: 20px;
    }

    .filter-form {
      display: flex;
      gap: 10px;
      margin-bottom: 15px;
    }

    .products {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
      gap: 20px;
    }

    .product {
      background: #f98ca3;
      border: none;
      border-radius: 10px;
      padding: 10px;
      text-align: center;
      cursor: pointer;
    }

    .product img {
      width: 100px;
      height: 100px;
      object-fit: cover;
      border-radius: 50%;
    }

    .order-panel {
      width: 350px;
      background: #f56b89;
      color: white;
      padding: 20px;
      font-size: 14px;
    }

    .order-item {
      display: flex;
      flex-direction: column;
      background: rgba(255,255,255,0.1);
      padding: 6px;
      margin: 6px 0;
      border-radius: 5px;
    }

    .order-controls {
      display: flex;
      justify-content: flex-end;
      gap: 5px;
      margin-top: 5px;
    }

    .order-controls button {
      font-size: 12px;
      padding: 2px 6px;
      background: #e94057;
      color: white;
      border: none;
      border-radius: 3px;
      cursor: pointer;
    }

    .save {
      margin-top: 20px;
      background: #e94057;
      border: none;
      color: white;
      font-size: 16px;
      padding: 12px;
      width: 100%;
      cursor: pointer;
    }

    input, select {
      width: 100%;
      padding: 6px;
      margin-bottom: 10px;
      border: none;
      border-radius: 5px;
      font-size: 16px;
    }
  </style>
</head>
<body>

<div class="sidebar">
  <img src="logo.png" style="width:50px;height:50px;border-radius:50%;">
  <a href="#">🏠</a>
  <a href="#">📊</a>
  <a href="#">⚙️</a>
  <a href="#">🚪</a>
</div>

<div class="main">
  <h2>MSC Cookies</h2>
  <p><?php echo date('l, F j, Y'); ?></p>

  <!-- Filter Form -->
  <form method="GET" class="filter-form">
    <select name="category" onchange="this.form.submit()">
      <option value="All">All Categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= htmlspecialchars($cat['Category']) ?>" <?= $selected_category === $cat['Category'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($cat['Category']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="subcategory" onchange="this.form.submit()">
      <option value="All">All Subcategories</option>
      <?php foreach ($subcategories as $sub): ?>
        <option value="<?= htmlspecialchars($sub['Subcategory']) ?>" <?= $selected_subcategory === $sub['Subcategory'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($sub['Subcategory']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="search" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
    <button type="submit">🔍</button>
  </form>

  <!-- Product Buttons -->
  <div class="products">
    <?php while($row = $products->fetch_assoc()): ?>
      <button class="product" onclick="addToOrder('<?php echo $row['Product_Name']; ?>', <?php echo $row['Product_Price']; ?>, <?php echo $row['Product_ID']; ?>)">
        <img src="images/<?php echo $row['Product_Code']; ?>.jpg">
        <div><?php echo $row['Product_Name']; ?></div>
        <div>₱<?php echo number_format($row['Product_Price'], 2); ?></div>
      </button>
    <?php endwhile; ?>
  </div>
</div>

<!-- Order Panel -->
<form method="POST" class="order-panel">
  <h3>Order ID:</h3>
  <input type="text" name="order_code" required placeholder="Enter Order Code">

  <label>Customer Name:</label>
  <input type="text" name="customer_name" required>

  <label>Sales Date:</label>
  <input type="date" name="sales_date" required>

  <label>Payment Method:</label>
  <select name="payment_method" required>
    <option value="Cash">Cash</option>
    <option value="Gcash">Gcash</option>
  </select>

  <div class="order-item" id="orderItems"></div>
  <div id="orderList"></div>

  <div class="totals">
    <div><span>Subtotal</span><span id="subtotal">₱0.00</span></div>
  </div>

  <button type="submit" class="save">Save Order Info</button>
</form>

<!-- JavaScript -->
<script>
  const selectedProducts = [];

  function addToOrder(name, price, id) {
    const existing = selectedProducts.find(p => p.id === id);
    if (existing) {
      existing.quantity += 1;
    } else {
      selectedProducts.push({ id, name, price, quantity: 1 });
    }
    renderOrderItems();
  }

  function changeQuantity(index, delta) {
    selectedProducts[index].quantity += delta;
    if (selectedProducts[index].quantity <= 0) {
      selectedProducts.splice(index, 1);
    }
    renderOrderItems();
  }

  function removeProduct(index) {
    selectedProducts.splice(index, 1);
    renderOrderItems();
  }

  function renderOrderItems() {
    const orderItemsDiv = document.getElementById("orderItems");
    const orderList = document.getElementById("orderList");
    const subtotalEl = document.getElementById("subtotal");

    orderItemsDiv.innerHTML = "";
    orderList.innerHTML = "";
    let total = 0;

    selectedProducts.forEach((item, index) => {
      const row = document.createElement("div");
      row.className = "order-item";
      row.innerHTML = `
        <div><strong>${item.name}</strong> x${item.quantity} — ₱${(item.price * item.quantity).toFixed(2)}</div>
        <div class="order-controls">
          <button onclick="changeQuantity(${index}, 1)">+</button>
          <button onclick="changeQuantity(${index}, -1)">−</button>
          <button onclick="removeProduct(${index})">🗑️</button>
        </div>
      `;
      orderItemsDiv.appendChild(row);

      orderList.innerHTML += `
        <input type="hidden" name="product_ids[]" value="${item.id}">
        <input type="hidden" name="quantities[]" value="${item.quantity}">
      `;

      total += item.price * item.quantity;
    });

    subtotalEl.textContent = `₱${total.toFixed(2)}`;
  }
</script>

</body>
</html>