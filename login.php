<?php

session_start();
// Include the database connection
require_once 'connect.php';

// Check if a session is already active (user is logged in)
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';
$username_or_email = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Sanitize and retrieve user input
    $username_or_email = trim($_POST['username_or_email']);
    $password = $_POST['password'];

    // 2. Simple field validation
    if (empty($username_or_email) || empty($password)) {
        $error_message = "Please enter both username/email and password.";
    } else {
        // 3. Prepare the SQL statement using placeholders (?)
        // We check for both username OR email against the input
        $sql = "SELECT user_id, password_hash, role_id, username FROM users WHERE username = ? OR email = ?";
        
        // 4. Initialize prepared statement
        if ($stmt = mysqli_prepare($conn, $sql)) {
            // 5. Bind variables to the prepared statement as parameters
            // 'ss' means two string parameters
            mysqli_stmt_bind_param($stmt, "ss", $param_login, $param_login_email);
            
            // Set parameters (same value for both username and email check)
            $param_login = $username_or_email;
            $param_login_email = $username_or_email; 

            // 6. Attempt to execute the prepared statement
            if (mysqli_stmt_execute($stmt)) {
                // 7. Store result set
                mysqli_stmt_store_result($stmt);

                // 8. Check if username/email exists
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    // 9. Bind result variables
                    mysqli_stmt_bind_result($stmt, $id, $hashed_password, $role_id, $username);
                    
                    if (mysqli_stmt_fetch($stmt)) {
                        // 10. Verify password
                        if (password_verify($password, $hashed_password)) {
                            // Password is correct, start session
                            $_SESSION["loggedin"] = true;
                            $_SESSION["user_id"] = $id;
                            $_SESSION["username"] = $username;
                            $_SESSION["role_id"] = $role_id;
                            
                            // Redirect to dashboard (assuming you will create this)
                            header("location: dashboard.php");
                            exit;
                        } else {
                            // Display an error message if password is not valid
                            $error_message = "Invalid username/email or password.";
                        }
                    }
                } else {
                    // Display an error message if username/email doesn't exist
                    $error_message = "Invalid username/email or password.";
                }
            } else {
                $error_message = "Oops! Something went wrong with the database query. Please try again later.";
            }

            // 11. Close statement
            mysqli_stmt_close($stmt);
        } else {
            $error_message = "Database error: Could not prepare statement.";
        }
    }
    // 12. Close connection (optional, as PHP automatically closes it at script end)
    // mysqli_close($conn); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMS - Administrator Login</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="form-container">
        <div class="auth-card">
            <h2>LMS Login 🔑</h2>
            
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group">
                    <label for="username_or_email">Username or Email</label>
                    <input type="text" id="username_or_email" name="username_or_email" value="<?php echo htmlspecialchars($username_or_email); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="submit-btn">Login</button>
            </form>

            <div class="auth-switch">
                Don't have an account? <a href="signUp.php">Register Now</a>
            </div>
            <div class="auth-switch" style="margin-top: 5px;">
                <a href="loginAdmin.php">Admin Login</a> | <a href="index.html">← Back to Home</a>
            </div>
        </div>
    </div>
</body>
</html>
