<?php
// Include the database connection
require_once 'connect.php';

// Start the session to check for existing login, though new users won't have one
session_start();

// Check if a session is already active (user is logged in)
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$success_message = '';
$error_message = '';
// Variables to pre-fill the form on error
$username = $email = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Sanitize and retrieve user input
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 2. Simple field validation
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $error_message = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must have at least 6 characters.";
    } else {
        // --- Security Check: Check if user or email already exists ---
        $sql_check = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        
        if ($stmt_check = mysqli_prepare($conn, $sql_check)) {
            mysqli_stmt_bind_param($stmt_check, "ss", $param_username_check, $param_email_check);
            $param_username_check = $username;
            $param_email_check = $email;
            
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_store_result($stmt_check);

            if (mysqli_stmt_num_rows($stmt_check) > 0) {
                $error_message = "This username or email is already registered.";
            }
            mysqli_stmt_close($stmt_check);
        } else {
            $error_message = "Database check error. Please try again later.";
        }

        // Proceed with insertion only if no errors occurred
        if (empty($error_message)) {
            // --- 4. Insert the new user into the database ---
            $sql_insert = "INSERT INTO users (username, email, password_hash, role_id) VALUES (?, ?, ?, ?)";

            if ($stmt_insert = mysqli_prepare($conn, $sql_insert)) {
                // Default role for new sign-ups: 3 (Member)
                $param_role_id = 3; 
                
                // Hash the password
                $param_password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Bind parameters
                mysqli_stmt_bind_param($stmt_insert, "sssi", $param_username, $param_email, $param_password_hash, $param_role_id);
                
                // Set parameters
                $param_username = $username;
                $param_email = $email;

                // Execute the query
                if (mysqli_stmt_execute($stmt_insert)) {
                    $success_message = "Account created successfully! You can now log in.";
                    // Clear variables to empty the form on success
                    $username = $email = ''; 
                } else {
                    $error_message = "Something went wrong. Please try again later. Error: " . mysqli_error($conn);
                }

                // Close statement
                mysqli_stmt_close($stmt_insert);
            } else {
                $error_message = "Database insert error. Please try again later.";
            }
        }
    }
    
    // Close connection for post request
    mysqli_close($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMS - Register Account</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="form-container">
        <div class="auth-card">
            <h2>Create New Account 📝</h2>

            <?php if (!empty($error_message)): ?>
                <div class="message error-message">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="message success-message">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
                <!-- Do not display form if registration was successful -->
                <p style="margin-top: 15px;">
                    <a href="login.php" class="submit-btn" style="display: inline-block; padding: 10px 20px;">Go to Login</a>
                </p>
            <?php else: ?>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password (min 6 chars)</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    <button type="submit" class="submit-btn">Create Account</button>
                </form>
            <?php endif; ?>

            <div class="auth-switch">
                Already have an account? <a href="login.php">Log In</a>
            </div>
            <div class="auth-switch" style="margin-top: 5px;">
                <a href="index.html">← Back to Home</a>
            </div>
        </div>
    </div>
</body>
</html>
