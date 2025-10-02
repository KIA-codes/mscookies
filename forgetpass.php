<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // ✅ Make sure Composer is installed

$message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['email'])) {
    $email = $_POST['email'];
    $reset_code = rand(100000, 999999); // You can make this more secure

    $_SESSION['reset_code'] = $reset_code; // store code in session
    $_SESSION['reset_email'] = $email;

    $mail = new PHPMailer(true);
    try {
        // SMTP config
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'alcantara.kristian16@gmail.com';      // ✅ your Gmail
        $mail->Password   = 'ympo pkzt vyzp ramv';         // ✅ Gmail app password only
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Email headers
        $mail->setFrom('alcantara_kristian16@gmail.com', 'MSC Cookies');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = ' Password Reset Code - MSC Cookies';
        $mail->Body    = "
            <h3>Hi there!</h3>
            <p>Your password reset code is:</p>
            <h2 style='color:#ef476f;'>$reset_code</h2>
            <p>Use this code to reset your password.</p>
        ";

        $mail->send();
        $message = " <script>
            alert('Reset code sent to $email.');
            window.location.href = 'verify_code.php';
        </script>";
       
    } catch (Exception $e) {
        $message = " <script>
            alert('Message could not be sent.
            Mailer Error: {$mail->ErrorInfo}');
        </script>";
      
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Forgot Password</title>
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
      text-align: center;
      width: 300px;
      box-shadow: 0 0 10px rgba(0,0,0,0.2);
    }
    input, button {
      width: 100%;
      padding: 12px;
      margin-top: 10px;
      font-size: 14px;
    }
    button {
      background: #ef476f;
      color: #fff;
      border: none;
      cursor: pointer;
      border-radius: 5px;
    }
    .success { color: green; margin-top: 10px; font-size: 14px; }
    .error { color: red; margin-top: 10px; font-size: 14px; }
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
        
        <div class="login-title">Forgot Password</div>
        <?php echo $message; ?>
        <form method="POST">
            <div class="form-group">
                <div class="input-wrapper">
                    <span class="input-icon">
                        <!-- User SVG icon -->
                      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
  <path d="M4 6h16c1.1 0 2 .9 2 2v8c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V8c0-1.1.9-2 2-2z" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M22 6l-10 7L2 6" stroke-linecap="round" stroke-linejoin="round"/>
</svg>

                    </span>
                    <input type="email" id="email" name="email" placeholder="Please enter your email" required style="padding-left:38px;">
                </div>
            </div>
            <div class="form-group">
                <div class="input-wrapper">
                  
                  
                </div>
            </div>
            <button type="submit" class="login-btn">Send reset code</button>
        </form>
       
    </div>
</body>
</html>