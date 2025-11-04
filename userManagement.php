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
$active_page = 'userManagement.php'; // Set active page for sidebar highlighting

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
$roles = [];

// --- 3. HANDLE USER ACTIONS (Edit Role / Delete) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $target_user_id = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        
        // --- 3a. Handle DELETE Action ---
        if ($action == 'delete') {
            // Prevent deleting the currently logged-in user (Admin)
            if ($target_user_id == $user_id) {
                $error = "You cannot delete your own account.";
            } else if ($target_user_id > 0) {
                // IMPORTANT: In a real system, you must also check and handle outstanding loans 
                // before deleting a user (e.g., set loans to null, or cascade delete loans).
                // Assuming loans are handled elsewhere or cascade deletion is set up.

                $sql_delete = "DELETE FROM users WHERE user_id = ?";
                if ($stmt = mysqli_prepare($conn, $sql_delete)) {
                    mysqli_stmt_bind_param($stmt, "i", $target_user_id);
                    if (mysqli_stmt_execute($stmt)) {
                        if (mysqli_stmt_affected_rows($stmt) > 0) {
                            $message = "User ID $target_user_id successfully deleted.";
                        } else {
                            $error = "User not found or could not be deleted.";
                        }
                    } else {
                        $error = "Database error during deletion: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            }
        }
        
        // --- 3b. Handle EDIT ROLE Action ---
        if ($action == 'edit_role') {
            $new_role_id = isset($_POST['new_role_id']) ? (int)$_POST['new_role_id'] : 0;

            if ($target_user_id == $user_id) {
                $error = "You cannot change your own role through this interface.";
            } else if ($target_user_id > 0 && $new_role_id > 0) {
                $sql_update = "UPDATE users SET role_id = ? WHERE user_id = ?";
                if ($stmt = mysqli_prepare($conn, $sql_update)) {
                    mysqli_stmt_bind_param($stmt, "ii", $new_role_id, $target_user_id);
                    if (mysqli_stmt_execute($stmt)) {
                        if (mysqli_stmt_affected_rows($stmt) > 0) {
                            $message = "User ID $target_user_id role successfully updated.";
                        } else {
                            $error = "User not found or role was not changed.";
                        }
                    } else {
                        $error = "Database error during role update: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
            } else {
                $error = "Invalid user ID or new role ID provided.";
            }
        }
    }
    // Refresh page to clear POST data and show updated list
    // header('Location: userManagement.php');
    // exit;
}


// --- 4. FETCH ALL USERS ---
$sql_users = "
    SELECT u.user_id, u.username, u.role_id, r.role_name 
    FROM users u
    JOIN roles r ON u.role_id = r.role_id
    ORDER BY u.user_id ASC";

if ($stmt = mysqli_prepare($conn, $sql_users)) {
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $users[] = $row;
        }
    } else {
        $error = "Failed to fetch users: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}


// --- 5. FETCH ALL ROLES (for the modal) ---
$sql_roles = "SELECT role_id, role_name FROM roles ORDER BY role_id ASC";
if ($result = mysqli_query($conn, $sql_roles)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $roles[] = $row;
    }
} else {
    $error = "Failed to fetch roles: " . mysqli_error($conn);
}


// --- 6. HTML STRUCTURE ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | LibSys</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
</head>
<body>
    <div class="dashboard-layout">
        <?php include 'sidebar.php'; // Assumes sidebar.php handles $active_page and $role_id ?>
        
        <section class="main-content">
                <?php include 'navbar.php'; // Assumes navbar.php handles its own variables/includes ?>
                <h1>User Management</h1>

                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="card">
                    <h2>All System Users (<?php echo count($users); ?>)</h2>
                    
                    <?php if (empty($users)): ?>
                        <p style="text-align: center; padding: 20px;">No users found in the system.</p>
                    <?php else: ?>
                    <div class="table-container">
                        <table class="book-table user-table"> 
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td>
                                            <span class="role-display role-<?php echo htmlspecialchars($user['role_id']); ?>">
                                                <?php echo htmlspecialchars($user['role_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['user_id'] != $user_id): // Prevent admin from editing/deleting themselves ?>
                                                <button 
                                                    class="btn-primary" 
                                                    onclick="openEditModal(
                                                        <?php echo $user['user_id']; ?>, 
                                                        '<?php echo htmlspecialchars($user['username']); ?>', 
                                                        '<?php echo htmlspecialchars($user['role_id']); ?>'
                                                    )"
                                                >
                                                    <i class="fas fa-edit"></i> Edit Role
                                                </button>
                                                
                                                <form method="POST" action="userManagement.php" style="display: inline-block; margin-left: 5px;" onsubmit="return confirm('Are you sure you want to delete user <?php echo htmlspecialchars($user['username']); ?>? This action is irreversible.');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="btn-danger"><i class="fas fa-trash-alt"></i> Delete</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: var(--secondary-color); font-style: italic;">(You)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
        </section>
    </div>
    
    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeEditModal()">&times;</span>
            <h2>Edit User Role: <span id="modalUsername"></span></h2>
            
            <form method="POST" action="userManagement.php">
                <input type="hidden" name="action" value="edit_role">
                <input type="hidden" id="modalUserId" name="target_user_id">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="new_role_id" style="display: block; font-weight: bold; margin-bottom: 5px;">Select New Role:</label>
                    <select id="new_role_id" name="new_role_id" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo htmlspecialchars($role['role_id']); ?>">
                                <?php echo htmlspecialchars($role['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn-primary submit-btn">Save Changes</button>
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
<?php
// Close the database connection at the end
if (isset($conn)) {
    mysqli_close($conn);
}
?>