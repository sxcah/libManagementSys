<?php
// Start the session to access user data
session_start();

// Include the database connection
require_once 'connect.php';

// --- 1. SESSION & AUTHENTICATION CHECK ---
// If the user is not logged in, redirect them to the login page
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user data from session
$username = $_SESSION['username'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 3;
$user_id = $_SESSION['user_id'];

// --- 2. AUTHORIZATION CHECK (Admin Only) ---
// Only Admin (role_id = 1) is authorized for User Management
if ($role_id != 1) {
    // Redirect non-admins to the dashboard or show an error
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';
$users = [];

// --- 3. HANDLE USER ACTIONS (Edit Role / Delete) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $target_user_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        
        // --- 3a. Handle DELETE Action ---
        if ($action == 'delete') {
            // Prevent admin from deleting their own account
            if ($target_user_id == $user_id) {
                $error = "Error: You cannot delete your own active account.";
            } else {
                $sql = "DELETE FROM users WHERE user_id = ?";
                if ($stmt = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param($stmt, "i", $target_user_id);
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "User ID: {$target_user_id} successfully deleted.";
                    } else {
                        $error = "Error deleting user: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
        
        // --- 3b. Handle EDIT ROLE Action (from the modal) ---
        if ($action == 'edit_role_submit' && $target_user_id > 0 && isset($_POST['new_role_id'])) {
            $new_role_id = (int)$_POST['new_role_id'];
            
            // Prevent admin from demoting or changing the role of their own account
            if ($target_user_id == $user_id && $new_role_id != $role_id) {
                $error = "Error: You cannot change the role of your own active Admin account.";
            } else {
                $sql = "UPDATE users SET role_id = ? WHERE user_id = ?";
                if ($stmt = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param($stmt, "ii", $new_role_id, $target_user_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Check if any rows were affected
                        if (mysqli_stmt_affected_rows($stmt) > 0) {
                            $message = "User ID: {$target_user_id}'s role was successfully updated to Role ID: {$new_role_id}.";
                        } else {
                            $message = "User ID: {$target_user_id}'s role was already Role ID: {$new_role_id}. No changes were made.";
                        }
                    } else {
                        $error = "Error updating user role: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $error = "Database preparation error: " . mysqli_error($conn);
                }
            }
        }
    }
}


// --- 4. FETCH ALL USERS FOR DISPLAY ---
// Select all users and join with roles to get the role name
$sql = "SELECT 
            u.user_id, 
            u.username, 
            u.email, 
            u.first_name, 
            r.role_name,
            u.role_id 
        FROM users u 
        JOIN roles r ON u.role_id = r.role_id
        ORDER BY u.role_id ASC, u.username ASC";

if ($result = mysqli_query($conn, $sql)) {
    // Fetch all records into an array
    $users = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_free_result($result);
} else {
    $error = "Database query failed: " . mysqli_error($conn);
}

// Fetch all possible roles for the Edit Modal
$roles = [];
$sql_roles = "SELECT role_id, role_name FROM roles";
if ($result_roles = mysqli_query($conn, $sql_roles)) {
    $roles = mysqli_fetch_all($result_roles, MYSQLI_ASSOC);
    mysqli_free_result($result_roles);
}

// Close database connection (note: if you need to perform more queries later, you'd keep it open)
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMS - User Management</title>
    <link rel="stylesheet" href="styles.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <h2>Library Pro LMS</h2>
            <nav class="sidebar-nav">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                
                <?php if ($role_id == 1 || $role_id == 2): ?>
                    <a href="#"><i class="fas fa-book"></i> Book Management</a>
                <?php endif; ?>
                
                <?php if ($role_id == 1 || $role_id == 2): ?>
                    <a href="#"><i class="fas fa-user-friends"></i> Member Accounts</a>
                <?php endif; ?>

                <?php if ($role_id == 1 || $role_id == 2): ?>
                    <a href="#"><i class="fas fa-exchange-alt"></i> Loans & Returns</a>
                <?php endif; ?>

                <?php if ($role_id == 1): // Highlight User Management as active ?>
                    <a href="userManagement.php" class="active"><i class="fas fa-users-cog"></i> User Management</a>
                <?php endif; ?>

                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <header class="main-header">
                <div class="user-info">
                    <span>Welcome, **<?php echo htmlspecialchars($username); ?>** (Admin)</span>
                </div>
            </header>

            <h1 class="page-title">User Management</h1>
            <p class="page-subtitle">Manage system users, view account details, and modify roles.</p>

            <?php if (!empty($message)): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- User Table Container -->
            <div class="data-card">
                <div class="card-header">
                    <h3>All Registered Users</h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--secondary-color);">No users found in the system.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td><?php echo htmlspecialchars($user['first_name']); ?></td>
                                        <td>
                                            <span class="role-badge role-<?php echo htmlspecialchars($user['role_id']); ?>">
                                                <?php echo htmlspecialchars($user['role_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <!-- Edit Button (Opens Modal) -->
                                            <button 
                                                class="action-btn edit-btn" 
                                                onclick="openEditModal(
                                                    '<?php echo htmlspecialchars($user['user_id']); ?>',
                                                    '<?php echo htmlspecialchars($user['username']); ?>',
                                                    '<?php echo htmlspecialchars($user['role_id']); ?>'
                                                )">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            
                                            <!-- Delete Form -->
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete user <?php echo htmlspecialchars($user['username']); ?>? This action cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="target_user_id" value="<?php echo htmlspecialchars($user['user_id']); ?>">
                                                <button type="submit" class="action-btn delete-btn" <?php echo ($user['user_id'] == $user_id) ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- Edit User Modal -->
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeEditModal()">&times;</span>
            <h2>Edit User: <span id="modalUsername"></span></h2>
            <form id="editUserForm" method="POST" action="userManagement.php">
                <input type="hidden" name="action" value="edit_role_submit">
                <input type="hidden" name="target_user_id" id="modalUserId">

                <div class="form-group">
                    <label for="new_role_id">Change Role</label>
                    <select id="new_role_id" name="new_role_id" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo htmlspecialchars($role['role_id']); ?>">
                                <?php echo htmlspecialchars($role['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="submit-btn">Save Changes</button>
                <div class="auth-switch" style="margin-top: 10px;">
                    <a href="#" onclick="closeEditModal()">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal logic
        const modal = document.getElementById('editUserModal');
        const modalUserId = document.getElementById('modalUserId');
        const modalUsername = document.getElementById('modalUsername');
        const newRoleId = document.getElementById('new_role_id');

        function openEditModal(userId, username, currentRoleId) {
            modalUserId.value = userId;
            modalUsername.textContent = username;
            newRoleId.value = currentRoleId;
            modal.style.display = 'block';
        }

        function closeEditModal() {
            modal.style.display = 'none';
        }

        // Close the modal if the user clicks outside of it
        window.onclick = function(event) {
            if (event.target == modal) {
                closeEditModal();
            }
        }
    </script>
</body>
</html>
