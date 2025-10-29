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
$role_id = $_SESSION['role_id'] ?? 3; // Default to Member if role_id is somehow missing

$role_name = 'Unknown Role';

// --- 2. FETCH ROLE NAME FROM DATABASE ---
// Retrieve the descriptive role name based on the role_id stored in the session
$sql = "SELECT role_name FROM roles WHERE role_id = ?";
if ($stmt = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($stmt, "i", $role_id);
    
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_bind_result($stmt, $fetched_role_name);
        if (mysqli_stmt_fetch($stmt)) {
            $role_name = $fetched_role_name;
        }
    }
    mysqli_stmt_close($stmt);
}

// Close database connection
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LMS Dashboard</title>
    <link rel="stylesheet" href="styles.css">
    <!-- Include Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Additional custom styles for the dashboard layout */
        .dashboard-layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background-color: #343a40; /* Dark background */
            color: white;
            padding-top: 20px;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1);
            position: fixed; /* Fixed sidebar */
            height: 100%;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            color: var(--primary-color);
        }

        .sidebar-nav a {
            display: block;
            color: #adb5bd; /* Lighter text color */
            text-decoration: none;
            padding: 15px 20px;
            transition: background-color 0.3s, color 0.3s;
            font-size: 1.1rem;
        }

        .sidebar-nav a i {
            margin-right: 10px;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background-color: #495057; /* Slightly lighter dark color on hover/active */
            color: white;
        }

        /* Main Content Area */
        .main-content {
            margin-left: 250px; /* Offset for the fixed sidebar */
            flex-grow: 1;
            padding: 40px;
            background-color: var(--bg-color);
        }

        /* Welcome Header */
        .welcome-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .welcome-header h1 {
            font-size: 2.5rem;
            color: var(--text-color);
            margin-bottom: 5px;
        }
        
        /* Role Tag Styling */
        .role-tag {
            font-size: 1.1rem;
            color: var(--secondary-color);
            margin-top: 5px;
        }

        .role-tag strong {
            color: var(--primary-color);
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            background-color: rgba(0, 123, 255, 0.1); /* Light background for the tag */
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card .icon {
            font-size: 2.5rem;
            color: var(--primary-color);
        }

        .stat-card .info p {
            margin-bottom: 5px;
            color: var(--secondary-color);
        }

        .stat-card .info h2 {
            font-size: 1.8rem;
            margin: 0;
            font-weight: bold;
        }

        /* Activity Section */
        .activity-section {
            background-color: #f8f9fa;
            padding: 30px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .activity-section h2 {
            color: var(--text-color);
            margin-bottom: 20px;
        }

        .activity-section ul {
            list-style: none;
            padding: 0;
        }

        .activity-section li {
            background-color: white;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 8px;
            border-left: 5px solid var(--accent-color);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <!-- Sidebar Navigation -->
        <<aside class="sidebar">
            <h2>Library Pro LMS</h2>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                
                <?php if ($role_id == 1 || $role_id == 2): // Admin and Librarian can manage books ?>
                    <a href="#"><i class="fas fa-book"></i> Book Management</a>
                <?php endif; ?>
                
                <?php if ($role_id == 1 || $role_id == 2): // Admin and Librarian can manage members ?>
                    <a href="#"><i class="fas fa-user-friends"></i> Member Accounts</a>
                <?php endif; ?>

                <?php if ($role_id == 1 || $role_id == 2): // Admin and Librarian can manage loans ?>
                    <a href="#"><i class="fas fa-exchange-alt"></i> Loans & Returns</a>
                <?php endif; ?>

                <?php if ($role_id == 1): ?>
                    <a href="userManagement.php"><i class="fas fa-users-cog"></i> User Management</a>
                <?php endif; ?>

                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </nav>
        </aside>

        <!-- Main Content Area -->
        <main class="main-content">
            <!-- Welcome Header -->
            <header class="welcome-header">
                <!-- Display the logged-in username -->
                <h1>Welcome back, <?php echo htmlspecialchars($username); ?>!</h1>
                <!-- Display the fetched role name -->
                <p class="role-tag">You are logged in as: <strong><?php echo htmlspecialchars($role_name); ?></strong></p>
                <p style="color: var(--secondary-color); margin-top: 5px;">A quick overview of the library system.</p>
            </header>

            <!-- Key Statistics Section -->
            <div class="stats-grid">
                <!-- Stat Card 1: Total Books -->
                <div class="stat-card">
                    <i class="fas fa-book-open icon"></i>
                    <div class="info">
                        <p>Total Books</p>
                        <h2 style="color: var(--primary-color);">1,200</h2>
                    </div>
                </div>
                <!-- Stat Card 2: Registered Members -->
                <div class="stat-card">
                    <i class="fas fa-users icon"></i>
                    <div class="info">
                        <p>Total Members</p>
                        <h2 style="color: var(--accent-color);">450</h2>
                    </div>
                </div>
                <!-- Stat Card 3: Books Currently Borrowed -->
                <div class="stat-card">
                    <i class="fas fa-hand-holding-box icon"></i>
                    <div class="info">
                        <p>Books on Loan</p>
                        <h2 style="color: #ffc107;">185</h2>
                    </div>
                </div>
                <!-- Stat Card 4: Overdue Books -->
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle icon"></i>
                    <div class="info">
                        <p>Overdue</p>
                        <h2 style="color: #dc3545;">78</h2>
                    </div>
                </div>
            </div>

            <!-- Recent Activity/Summary Section -->
            <section class="activity-section">
                <h2 style="color: var(--text-color); margin-bottom: 20px;">Recent Loans & Activity</h2>
                <div style="background-color: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);">
                    <p style="font-style: italic; color: var(--secondary-color);">This area will contain a list of recent transactions and important alerts.</p>
                    <ul style="list-style: none; margin-top: 15px; color: var(--text-color); padding: 0;">
                        <li>Loan: 'The Martian' to John Doe (Due: 2025-06-01)</li>
                        <li>Return: 'Educated' by Jane Smith (On Time)</li>
                        <li>New Member Registered: Maria Garcia</li>
                    </ul>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
