<?php
session_start();
$conn = new mysqli("localhost", "root", "", "mscookies");

// Redirect if no email/code verified
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgetpass.php");
    exit;
}

$message = "";

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    $email = $_SESSION['reset_email'];

    if ($new_password !== $confirm) {
        $message = "<p class='error'>❌ Passwords do not match.</p>";
    } elseif (strlen($new_password) < 6) {
        $message = "<p class='error'>❌ Password must be at least 6 characters long.</p>";
    } else {
        $stmt = $conn->prepare("UPDATE user SET Password = ? WHERE Email = ?");
        $stmt->bind_param("ss", $new_password, $email); // plain text
        if ($stmt->execute()) {
            $message =  " <script>
            alert('Password successfully updated.');
            window.location.href = 'index.php';
        </script>";
            "<p class='success'>✅  <a href='index.php'>Go to Login</a></p>";
            session_unset();
            session_destroy();
        } else {
            $message =  "<p class='error'>❌ Something went wrong. Please try again.</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password</title>
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
    .box {
      background: #fff;
      padding: 30px;
      border-radius: 10px;
      width: 320px;
      text-align: center;
      box-shadow: 0 0 10px rgba(0,0,0,0.3);
    }
    input, button {
      width: 100%;
      padding: 12px;
      margin-top: 10px;
      font-size: 14px;
      border-radius: 5px;
      border: 1px solid #ccc;
    }
    button {
      background-color: #ef476f;
      color: white;
      border: none;
      cursor: pointer;
    }
    h2 {
      margin-bottom: 10px;
    }
    .error {
      color: red;
      font-size: 14px;
    }
    .success {
      color: green;
      font-size: 14px;
    }
    a {
      color: #ef476f;
      text-decoration: none;
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
            top: 62%;
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
        
        <div class="login-title">Reset your Password</div>
        <?= $message ?>
        <form method="POST" >
            <div class="form-group">
                <div class="input-wrapper">
                    <span class="input-icon">
                        <!-- User SVG icon -->
                       <svg width="23" height="23" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <rect x="4" y="11" width="16" height="9" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M17.5 5.5l1 .5-1 .5-.5 1-.5-1-1-.5 1-.5.5-1z" stroke-linecap="round" stroke-linejoin="round"/>
</svg>

                    </span>
                    <input type="password" id="new_password" name="new_password" placeholder="New Password" required style="padding-left:38px;">
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                    <span class="input-icon">
                        <!-- Lock SVG icon -->
                       <svg width="23" height="23" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <rect x="4" y="11" width="16" height="9" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M9 16l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
</svg>

                    </span>
                    <input type="password" id="confim_password" name="confirm_password" placeholder="Confirm Password" required style="padding-left:38px;">
                </div>
            </div>
            <button type="submit" class="login-btn">Change Password</button>
        </form>
      
    </div>
</body>
</html>