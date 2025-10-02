<?php
session_start();
require_once 'dtbconn.php'; // Use your connection file

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Fetch user record
    $stmt = $connection->prepare("SELECT * FROM User WHERE Username = ? AND Password = ?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['User_ID'];
        $_SESSION['username'] = $user['Username'];
        
        // Record login in Login_Tracker (legacy)
        $user_id = $user['User_ID'];
        $stmt2 = $connection->prepare("INSERT INTO Login_Tracker (User_ID, Login_Time) VALUES (?, NOW())");
        $stmt2->bind_param("i", $user_id);
        $stmt2->execute();
        $stmt2->close();
        
        // Record login in Activity_Logs (new system)
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $details = json_encode(['username' => $username, 'login_time' => date('Y-m-d H:i:s')]);
            
            $stmt3 = $connection->prepare("INSERT INTO Activity_Logs (User_ID, Activity_Type, Activity_Description, Details, IP_Address, User_Agent) VALUES (?, 'login', ?, ?, ?, ?)");
            $description = "User '{$username}' logged into the system";
            $stmt3->bind_param("issss", $user_id, $description, $details, $ip_address, $user_agent);
            $stmt3->execute();
            $stmt3->close();
        } catch (Exception $e) {
            error_log("Login logging error: " . $e->getMessage());
        }
        
        if ($user['UserType'] === 'admin') {
            header("Location: descriptive_dashboard.php");
            exit;
        } else if ($user['UserType'] === 'staff') {
            header("Location: staff_dashboard.php");
            exit;
        } else {
            echo "<script>alert('Access denied: Unknown user type.'); window.location.href='index.php';</script>";
            exit;
        }
    } else {
        echo "<script>alert('Invalid username or password.'); window.location.href='index.php';</script>";
        exit;
    }

    $stmt->close();
    $connection->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>MSC Cookies Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        body {
            min-height: 100vh;
            min-width: 100vw;
           background:linear-gradient(rgba(0,0,0,0.5),rgba(0,0,0,0.5)) url(PINK.jpg) no-repeat center fixed;
            background-size: cover;
           position: relative;
            font-family: 'Arial', sans-serif;
            animation: slide 15s infinite;
        }
        .logoo{
         position: absolute;
           left: 50%;
            top: 30%;
            transform: translate(-50%, -50%);
        }
        .overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            width: 100vw; height: 100vh;
            background: rgba(0, 0, 0, 0.53);
            z-index: 1;
        }
        .login-container {
            position: absolute;
            left: 50%;
            top: 60%;
            transform: translate(-50%, -50%);
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.15);
            padding: 40px 32px 32px 32px;
            width: 100%;
            max-width: 370px;
            z-index: 2;
            text-align: center;
        }
        .login-container img {
            width: 120px;
            margin-bottom: 18px;
        }
        .login-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 24px;
            color: #222;
        }
        .form-group {
            margin-bottom: 18px;
            text-align: left;
        }
        .form-group label {
            display: none;
        }
        .form-group input {
            width: 100%;
            padding: 12px 40px 12px 40px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 15px;
            background: #fafafa;
            outline: none;
            box-sizing: border-box;
        }
        .form-group input:focus {
            border-color: #2148c0;
        }
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 16px;
        }
        .input-wrapper {
            position: relative;
        }
        .login-btn {
            background-color: #ec3462;
            color: #fff;
            padding: 12px;
            border: none;
            border-radius: 4px;
            width: 100%;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 8px;
            box-shadow: 0 2px 4px rgba(236,52,98,0.08);
            transition: background 0.2s;
        }
        .login-btn:hover {
            background-color: #c72b52;
        }
        .forgot-link {
            display: block;
            margin-top: 16px;
            color: #222;
            font-size: 14px;
            text-decoration: none;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .forgot-link:hover {
            opacity: 1;
            text-decoration: underline;
        }
        @media (max-width: 500px) {
            .login-container {
                padding: 24px 8px 16px 8px;
                max-width: 98vw;
            }
        }
        .slideshow {
    position: relative;
    width: 100%;
    height: 100%;
  }

  .slide {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    opacity: 0;
    animation: fade 22s infinite;
  }

  /* Each slide gets its own delay */
  .slide:nth-child(1) {
    background-image: url("loginbg.jpg");
    animation-delay: 0s;
  }
  .slide:nth-child(2) {
    background-image: url("loginbg2.jpg");
    animation-delay: 6s;
  }
  .slide:nth-child(3) {
    background-image: url("loginbg3.jpg");
    animation-delay: 12s;
  }
  .slide:nth-child(4) {
    background-image: url("loginbg4.jpg");
    animation-delay: 18s;
  }


  /* Fade animation with overlap (no white gaps) */
  @keyframes fade {
    0%   { opacity: 0; }
    8%   { opacity: 1; }  /* fade in */
    33%  { opacity: 1; }  /* stay visible */
    41%  { opacity: 0; }  /* fade out */
    100% { opacity: 0; }
  }
    </style>
</head>
<body>
    <div class="slideshow">
    <div class="slide"></div>
    <div class="slide"></div>
    <div class="slide"></div>
      <div class="slide"></div>
  </div>
 
    <div class="overlay">
        <img src="nobackgroundlogo.png" alt="logo" width="700" height="200" style="position: absolute;
           left: 50%;
            top: 25%;
            transform: translate(-50%, -50%);">
    </div>
    
    
    <div class="login-container">
        <!-- Logo (replace src with your logo if available) -->
       
        <div class="login-title">Sales Data Analytics System</div>
        <form method="POST" action="index.php">
            <div class="form-group">
                <div class="input-wrapper">
                    <span class="input-icon">
                        <!-- User SVG icon -->
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 8-4 8-4s8 0 8 4"/></svg>
                    </span>
                    <input type="text" id="username" name="username" placeholder="USERNAME" required style="padding-left:38px;">
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <span class="input-icon">
                        <!-- Lock SVG icon -->
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="8" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input type="password" id="password" name="password" placeholder="PASSWORD" required style="padding-left:38px;">
                </div>
            </div>
            <button type="submit" class="login-btn">LOGIN</button>
        </form>
        <a href="forgetpass.php" class="forgot-link">Forgot password?</a>
    </div>
    
</body>
</html>