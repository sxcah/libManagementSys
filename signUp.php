<?php
// Include the database connection
require_once 'connect.php';

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
            mysqli_stmt_bind_param($stmt_check, "ss", $param_username, $param_email);
            
            $param_username = $username;
            $param_email = $email; 

            if (mysqli_stmt_execute($stmt_check)) {
                mysqli_stmt_store_result($stmt_check);

                if (mysqli_stmt_num_rows($stmt_check) >= 1) {
                    $error_message = "This username or email is already registered.";
                } else {
                    // Proceed with INSERT if no existing user found
                    // --- Database Insert using Prepared Statement ---
                    $sql_insert = "INSERT INTO users (username, email, password_hash, role_id) VALUES (?, ?, ?, 2)"; // role_id 2 for standard user

                    if ($stmt_insert = mysqli_prepare($conn, $sql_insert)) {
                        
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $role_id_default = 2; // Assuming 1=Admin, 2=Librarian/Member
                        
                        // 'sss' means three string parameters
                        mysqli_stmt_bind_param($stmt_insert, "sss", $param_username_i, $param_email_i, $param_password_i);
                        
                        // Set parameters
                        $param_username_i = $username;
                        $param_email_i = $email;
                        $param_password_i = $hashed_password;

                        if (mysqli_stmt_execute($stmt_insert)) {
                            $success_message = "Account created successfully! You can now log in.";
                            // Clear form fields on success
                            $username = $email = '';
                        } else {
                            $error_message = "Error creating account: " . mysqli_error($conn);
                        }

                        mysqli_stmt_close($stmt_insert);
                    } else {
                        $error_message = "Database error: Could not prepare insert statement.";
                    }
                }
            } else {
                $error_message = "Oops! Something went wrong during the pre-check. Please try again later.";
            }

            mysqli_stmt_close($stmt_check);
        } else {
            $error_message = "Database error: Could not prepare pre-check statement.";
        }
    }
    // mysqli_close($conn); // Close connection
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
            <h2>Register New Account 📝</h2>
            
            <?php if (!empty($error_message)): ?>
                <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <p class="success-message"><?php echo htmlspecialchars($success_message); ?></p>
                <div class="auth-switch"><a href="login.php">Proceed to Login</a></div>
            <?php endif; ?>

            <!-- The form is only displayed if no final success message is present -->
            <?php if (empty($success_message)): ?>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
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
