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

// Set active page for sidebar highlighting
$active_page = 'dashboard.php';

// Get user data from session
$username = $_SESSION['username'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 3; // Default to Member if role_id is somehow missing
$staff_roles = [1, 2]; // Admin and Librarian
$is_staff = in_array($role_id, $staff_roles);

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


// --- 3. FETCH DASHBOARD DATA (STAFF ONLY) ---
$stats = [
    'members_with_loans' => 0,
    'active_loans' => 0,
    'distinct_books_out' => 0,
    'total_copies_catalogued' => 0,
    'total_members' => 0
];
$recent_activity = [];

if ($is_staff) {
    // UPDATED SQL FOR LOAN STATS
    $sql_loan_stats = "
        SELECT 
            (SELECT COUNT(DISTINCT user_id) FROM loans WHERE return_date IS NULL) AS members_with_loans,
            (SELECT COUNT(loan_id) FROM loans WHERE return_date IS NULL) AS active_loans,
            (SELECT COUNT(DISTINCT book_id) FROM loans WHERE return_date IS NULL) AS distinct_books_out,
            (SELECT SUM(total_copies) FROM books) AS total_copies_catalogued, -- UPDATED TO SUM total_copies
            (SELECT COUNT(user_id) FROM users WHERE role_id = 3) AS total_members
    ";

    if ($result = mysqli_query($conn, $sql_loan_stats)) {
        $stats = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
    }

    // --- FETCH RECENT LOAN/RETURN ACTIVITY ---
    // UPDATED SQL QUERY: Direct join from loans to books
    $sql_activity = "
        SELECT 
            l.loan_date, 
            l.return_date, 
            l.due_date, 
            b.title AS book_title, 
            u.username AS member_username
        FROM 
            loans l
        JOIN 
            users u ON l.user_id = u.user_id
        JOIN 
            books b ON l.book_id = b.book_id -- Direct join to books table
        ORDER BY 
            l.loan_date DESC, l.return_date DESC
        LIMIT 15
    ";

    if ($result = mysqli_query($conn, $sql_activity)) {
        $recent_activity = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_free_result($result);
    }
} else {
    // --- MEMBER DASHBOARD DATA ---
    $user_id = $_SESSION['user_id'];
    
    // Total Active Loans for the Member
    $sql_member_loans = "SELECT COUNT(*) AS active_loans FROM loans WHERE user_id = ? AND return_date IS NULL";
    if ($stmt = mysqli_prepare($conn, $sql_member_loans)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $stats['active_loans']);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }

    // Overdue Loans for the Member
    $stats['overdue_loans'] = 0;
    $sql_member_overdue = "SELECT COUNT(*) AS overdue_loans FROM loans WHERE user_id = ? AND return_date IS NULL AND due_date < NOW()";
    if ($stmt = mysqli_prepare($conn, $sql_member_overdue)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $stats['overdue_loans']);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }

    // Member's recent activity (loans/returns)
    // UPDATED SQL QUERY: Direct join from loans to books
    $sql_member_activity = "
        SELECT 
            l.loan_date, 
            l.return_date, 
            l.due_date, 
            b.title AS book_title
        FROM 
            loans l
        JOIN 
            books b ON l.book_id = b.book_id -- Direct join to books table
        WHERE 
            l.user_id = ?
        ORDER BY 
            l.loan_date DESC, l.return_date DESC
        LIMIT 10
    ";
    
    if ($stmt = mysqli_prepare($conn, $sql_member_activity)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $recent_activity = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
    }
}

// --- 4. HTML OUTPUT ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Library System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 5px solid var(--accent-color);
        }
        .stat-card.danger {
            border-left: 5px solid #dc3545;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            color: var(--secondary-color);
            font-size: 1em;
        }
        .stat-card p {
            font-size: 2.5em;
            font-weight: bold;
            color: var(--primary-color);
            margin: 0;
        }
        .activity-list {
            list-style: none;
            padding: 0;
        }
        .activity-list li {
            padding: 8px 0;
            border-bottom: 1px dashed #eee;
        }
        .activity-list li:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        
        <?php include 'sidebar.php'; // Includes the sidebar navigation ?>

        <div class="main-content">
            <?php include 'navbar.php'; // Includes the top navigation bar ?>

            <section class="page-content">
                <h1>Welcome, <?php echo htmlspecialchars($username); ?> (<span style="color: var(--accent-color);"><?php echo htmlspecialchars($role_name); ?></span>)</h1>
                <p>Overview of the library system activity and your status.</p>
                
                <?php if ($is_staff): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Total Copies Catalogued</h3>
                            <p><?php echo number_format($stats['total_copies_catalogued']); ?></p>
                        </div>
                        <div class="stat-card danger">
                            <h3>Active Loans</h3>
                            <p><?php echo number_format($stats['active_loans']); ?></p>
                        </div>
                        <div class="stat-card">
                            <h3>Books Currently Out</h3>
                            <p><?php echo number_format($stats['distinct_books_out']); ?></p>
                        </div>
                        <div class="stat-card">
                            <h3>Total Members</h3>
                            <p><?php echo number_format($stats['total_members']); ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3>Active Loans</h3>
                            <p><?php echo $stats['active_loans']; ?></p>
                        </div>
                        <div class="stat-card <?php echo $stats['overdue_loans'] > 0 ? 'danger' : ''; ?>">
                            <h3>Overdue Loans</h3>
                            <p><?php echo $stats['overdue_loans']; ?></p>
                        </div>
                        <div class="stat-card">
                            <h3>Loan Limit</h3>
                            <p>5</p>
                        </div>
                        <div class="stat-card">
                            <h3>Access Role</h3>
                            <p style="font-size: 1.5em;"><?php echo htmlspecialchars($role_name); ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <section class="activity-section card">
                    <h2>Recent Activity (<?php echo $is_staff ? 'Library-wide' : 'Your Loans'; ?>)</h2>
                    <div class="card-content">
                        <?php if (empty($recent_activity)): ?>
                            <p>No recent activity to display.</p>
                        <?php else: ?>
                            <ul class="activity-list">
                                <?php foreach ($recent_activity as $activity): 
                                    $is_return = $activity['return_date'] !== NULL;
                                    $type_label = $is_return ? 'RETURNED' : 'LOANED';
                                    $date_info = $is_return ? date('M d, H:i', strtotime($activity['return_date'])) : date('M d, H:i', strtotime($activity['loan_date']));
                                    $status_color = $is_return ? '#17a2b8' : '#28a745'; // Default colors

                                    // Determine if the return was late
                                    $is_late = $is_return && (strtotime($activity['return_date']) > strtotime($activity['due_date']));
                                    $status_text = $is_late ? ' (LATE)' : '';
                                    if ($is_late) {
                                        $status_color = '#dc3545'; // Use Danger red for late returns
                                    }
                                    // Overdue active loans (only check if it's not a return)
                                    if (!$is_return && (strtotime($activity['due_date']) < time())) {
                                        $status_color = '#dc3545';
                                        $type_label = 'OVERDUE';
                                    }

                                ?>
                                    <li>
                                        <span style="font-weight: bold; color: <?php echo $status_color; ?>;">[<?php echo $type_label; echo $status_text; ?>]</span> 
                                        '<?php echo htmlspecialchars($activity['book_title']); ?>' 
                                        <?php if ($is_staff): ?>
                                            <?php echo $is_return ? 'by' : 'to'; ?> 
                                            <span style="font-weight: 600;"><?php echo htmlspecialchars($activity['member_username']); ?></span> 
                                        <?php endif; ?>
                                        (<span style="font-size: 0.9em; color: var(--secondary-color);"><?php echo $date_info; ?></span>)
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            </section>
        </div>
    </div>
    
    <script src="scripts.js"></script>
</body>
</html>