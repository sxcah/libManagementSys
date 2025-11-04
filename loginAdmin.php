<?php
// Start the session to store user login state
session_start();

// Redirect logged-in users to the dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Include the database connection file
require_once 'connect.php';

// Initialize variables
$error_message = '';
$username_or_email = '';
// Only allow users with Role IDs 1 (Admin) or 2 (Librarian) to use this page.
$allowed_admin_roles = [1, 2];

// --- 1. HANDLE LOGIN FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize and capture input
    $username_or_email = filter_input(INPUT_POST, 'username_or_email', FILTER_SANITIZE_SPECIAL_CHARS);
    $password = $_POST['password'];

    if (empty($username_or_email) || empty($password)) {
        $error_message = "Please enter both username/email and password.";
    } else {
        // SQL to fetch user by username OR email, AND their role_id must be an allowed admin role
        $sql = "
            SELECT user_id, username, password_hash, role_id 
            FROM users 
            WHERE (username = ? OR email = ?) AND role_id IN (?, ?) 
            LIMIT 1
        ";

        if ($stmt = mysqli_prepare($conn, $sql)) {
            // Bind parameters: 'ssii' stands for string (username), string (email), integer (role_id 1), integer (role_id 2)
            mysqli_stmt_bind_param($stmt, "ssii", 
                $username_or_email, 
                $username_or_email, 
                $allowed_admin_roles[0], 
                $allowed_admin_roles[1]
            );

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_bind_result($stmt, $user_id, $username, $password_hash, $role_id);
                
                // Fetch the result
                if (mysqli_stmt_fetch($stmt)) {
                    // --- 2. VERIFY PASSWORD ---
                    if (password_verify($password, $password_hash)) {
                        // Success: Set session variables
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['username'] = $username;
                        $_SESSION['role_id'] = $role_id;
                        
                        // Redirect to the dashboard
                        header('Location: dashboard.php');
                        exit;
                    } else {
                        // Failed: Password mismatch
                        $error_message = "Invalid credentials or unauthorized access.";
                    }
                } else {
                    // Failed: User not found with admin/librarian role
                    $error_message = "Invalid credentials or unauthorized access.";
                }
            } else {
                // Database execution error
                $error_message = "A database error occurred during login. Please try again.";
            }
            mysqli_stmt_close($stmt);
        } else {
            // Database preparation error
            $error_message = "Internal server error. Could not prepare statement.";
        }
    }
}
// Note: mysqli_close($conn) is not called here, assuming it's closed in connect.php or left open for other includes.
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin/Staff Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="form-container">
        <div class="auth-card">
            <div class="auth-header">
                <h2>Admin/Staff Login 🔑</h2>
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
                <button type="submit" class="submit-btn">Staff Log In</button>
            </form>

            <div class="auth-switch">
                Not an Admin? <a href="login.php">Member Login</a>
            </div>
            <div class="auth-switch">
                <a href="index.html">← Back to Home</a>
            </div>
        </div>
    </div>
</body>
</html>
