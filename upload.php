<!DOCTYPE html>
<html>
<head>
  <title>Upload Sales CSV</title>
  <style>
    body {
      font-family: 'Segoe UI', sans-serif;
      background-color: #f4f4f9;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
      margin: 0;
    }

    .upload-container {
      background: white;
      padding: 40px 30px;
      border-radius: 12px;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
      text-align: center;
    }

    h2 {
      margin-bottom: 20px;
      color: #333;
    }

    input[type="file"] {
      padding: 10px;
      font-size: 16px;
      border-radius: 6px;
      border: 1px solid #ccc;
      margin-bottom: 20px;
    }

    button {
      padding: 10px 20px;
      background-color: #f77a8f;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 16px;
      transition: background-color 0.3s ease;
    }

    button:hover {
      background-color: #e8657a;
    }
  </style>
</head>
<body>
  <div class="upload-container">
    <h2>Upload Sales CSV</h2>
    <form action="jotform.php" method="post" enctype="multipart/form-data">
      <input type="file" name="csv_file" accept=".csv" required><br>
      <button type="submit" name="import">Import</button>
    </form>
  </div>
</body>
</html>