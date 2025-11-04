<?php
// navbar.php - Top Navigation Bar Component

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check for the connect.php dependency (required for fetching role name)
if (!isset($conn) && file_exists('connect.php')) {
    require_once 'connect.php';
}

// --- 1. GET USER DATA FROM SESSION ---
$user_id = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'Guest';
$role_id = $_SESSION['role_id'] ?? 3; // Default to Member

$role_name = 'Unknown Role';

// --- 2. FETCH ROLE NAME FROM DATABASE (if user is logged in) ---
if ($user_id > 0 && isset($conn)) {
    $sql_role = "SELECT role_name FROM roles WHERE role_id = ?";
    if ($stmt = mysqli_prepare($conn, $sql_role)) {
        mysqli_stmt_bind_param($stmt, "i", $role_id);
        
        if (mysqli_stmt_execute($stmt)) {
            mysqli_stmt_bind_result($stmt, $fetched_role_name);
            if (mysqli_stmt_fetch($stmt)) {
                $role_name = $fetched_role_name;
            }
        }
        mysqli_stmt_close($stmt);
    }
}
?>

<!-- The HTML structure uses classes defined in styles.css -->
<div class="navbar">
    <!-- System Logo/Title -->
    <div class="logo">
        <i class="fas fa-book"></i> Library System
    </div>
    
    <!-- User Information and Logout -->
    <div class="user-info">
        <span>
            <i class="fas fa-user-circle"></i> Hello, 
            <strong><?php echo htmlspecialchars($username); ?></strong> 
            (<span class="user-role"><?php echo htmlspecialchars($role_name); ?></span>)
        </span>
        <a href="logout.php" class="btn-logout" title="Logout">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>
</div>

<!-- Add a small internal style block for better visual separation of the role -->
<style>
/* Additional specific styling for the navbar to ensure proper display */
.navbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 30px;
    background-color: var(--card-bg); 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.logo {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--primary-color);
}

.logo i {
    margin-right: 10px;
    color: var(--accent-color);
}

.user-info {
    display: flex;
    align-items: center;
    gap: 20px;
    font-size: 1rem;
    color: var(--text-color);
}

.user-info i {
    color: var(--secondary-color);
    margin-right: 5px;
}

.user-role {
    font-weight: normal; /* Make the role name slightly less emphasized than the username */
    color: var(--secondary-color);
}

.btn-logout {
    text-decoration: none;
    color: #dc3545; /* Red for logout button */
    font-weight: 600;
    transition: color 0.3s ease;
}

.btn-logout:hover {
    color: #c82333;
}
</style>
