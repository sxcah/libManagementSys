<?php
// Start the session to store user login state
session_start();

// Include the database connection file
require_once 'connect.php';

// Define the expected password hash for the 'testpass' password for debugging
// The hash provided by the user: $2y$10$iI0T7Wl0XpGzXzS6U0v4d.eP9h7t8d9Q2L3M4N5O6P7R8S9T0U1V2W3X4Y5Z6A7B8C
// IMPORTANT: This hash is hardcoded ONLY for a temporary diagnostic. Remove this block after fixing the issue.
$DEBUG_EXPECTED_HASH = '$2y$10$iI0T7Wl0XpGzXzS6U0v4d.eP9h7t8d9Q2L3M4N5O6P7R8S9T0U1V2W3X4Y5Z6A7B8C';
$DEBUG_PLAINTEXT_PASS = 'testpass';

// --- DEBUG HASH CHECK BLOCK (TEMPORARY) ---
// This check verifies if the hash you provided is actually the hash for 'testpass'.
// If this is FALSE, the hash in your database is incorrect.
$hash_is_valid = password_verify($DEBUG_PLAINTEXT_PASS, $DEBUG_EXPECTED_HASH);

if (!$hash_is_valid) {
    // If this message appears in your PHP error logs, the hash you provided is NOT for 'testpass'. 
    error_log("DEBUG ALERT: The hardcoded hash for 'testpass' is invalid! Generate a new one.");
}
// --- END DEBUG HASH CHECK BLOCK ---


// Check if a session is already active (user is logged in)
if (isset($_SESSION['user_id']) && isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
    header('Location: dashboard.php'); // Redirect to main dashboard if already logged in as Admin
    exit;
}

$error_message = '';
$username_or_email = ''; // Initialize variable for form pre-filling

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 1. Sanitize and retrieve user input (Consistent variable name)
    $username_or_email = trim($_POST['username_or_email']);
    $password = $_POST['password'];

    // 2. Simple validation
    if (empty($username_or_email) || empty($password)) {
        $error_message = "Both username/email and password are required.";
    } else {
        // --- Security Check: Retrieve user data and role using Prepared Statement ---
        // Selecting the password_hash is crucial for verification
        $sql = "SELECT user_id, password_hash, role_id FROM users WHERE username = ? OR email = ?";
        
        // Prepare the statement
        if ($stmt = mysqli_prepare($conn, $sql)) {
            // Bind parameters ('ss' for two strings)
            mysqli_stmt_bind_param($stmt, "ss", $param_login, $param_login_email);
            
            // Set parameters (same value for both username and email check)
            $param_login = $username_or_email;
            $param_login_email = $username_or_email;

            // Execute the statement
            if (mysqli_stmt_execute($stmt)) {
                // Get result set
                mysqli_stmt_store_result($stmt);

                // Check if username/email exists
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    // Bind result variables
                    mysqli_stmt_bind_result($stmt, $user_id, $hashed_password, $role_id);
                    
                    // Fetch the results
                    if (mysqli_stmt_fetch($stmt)) {
                        
                        // ** CRITICAL STEP: Verify the password **
                        if (password_verify($password, $hashed_password)) {
                            // Password is correct! Now check the role.
                            if ($role_id == 1) { // 1 is for Admin role
                                // Password and Role are correct! Set session variables.
                                session_regenerate_id();
                                $_SESSION['user_id'] = $user_id;
                                $_SESSION['role_id'] = $role_id;
                                
                                // Redirect to a protected admin dashboard page
                                header("Location: dashboard.php");
                                exit;
                            } else {
                                // User found, but not an admin. Display their role ID for debugging.
                                $error_message = "Access Denied: This portal is only for administrators (Role ID found: " . htmlspecialchars($role_id) . ").";
                            }
                        } else {
                            // Password is not valid
                            $error_message = "Invalid credentials. Please check your username and password.";
                        }
                    }
                } else {
                    // Username or email not found
                    $error_message = "Invalid credentials. Please check your username and password.";
                }

                // Close statement
                mysqli_stmt_close($stmt);
            } else {
                $error_message = "Database query failed: " . mysqli_error($conn);
            }
        } else {
            $error_message = "Database statement preparation failed: " . mysqli_error($conn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Library Pro</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="form-container">
        <div class="auth-card">
            <div class="auth-header">
                <h2>Admin Login 🔑</h2>
                <p>Sign in to the system management portal.</p>
            </div>
            
            <?php if (!empty($error_message)): ?>
                <div class="message error-message">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                <div class="form-group">
                    <label for="username_or_email">Username or Email</label>
                    <input type="text" id="username_or_email" name="username_or_email" value="<?php echo htmlspecialchars($username_or_email); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="submit-btn">Admin Log In</button>
            </form>

            <div class="auth-switch">
                Not an Admin? <a href="login.php">User Login</a>
            </div>
            <div class="auth-switch">
                <a href="index.html">← Back to Home</a>
            </div>
        </div>
    </div>
</body>
</html>
