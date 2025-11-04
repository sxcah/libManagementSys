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
$active_page = 'loansReturn.php';

// Get user data from session
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 3; // Default to Member
$staff_roles = [1, 2]; // Admin and Librarian
$is_staff = in_array($role_id, $staff_roles);

// Define loan parameters
$MAX_LOANS = 5;
$LOAN_PERIOD_DAYS = 14;

$message = '';
$error = '';
$loans = [];

// Check if the connection is available
if (!$conn) {
    die("Database connection failed.");
}

// --- 2. FETCH ROLE NAME AND CHECK PERMISSIONS ---
// Fetch role name for display
$sql_role = "SELECT role_name FROM roles WHERE role_id = ?";
if ($stmt = mysqli_prepare($conn, $sql_role)) {
    mysqli_stmt_bind_param($stmt, "i", $role_id);
    if (mysqli_stmt_execute($stmt)) {
        mysqli_stmt_bind_result($stmt, $role_name);
        mysqli_stmt_fetch($stmt);
    }
    mysqli_stmt_close($stmt);
}

// Staff-only check for this page's main functionality
if (!$is_staff) {
    header('Location: loanBook.php'); // Redirect non-staff to the loan search page
    exit;
}

// --- 3. LOAN RETURN HANDLER (STAFF ONLY) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'return') {
    $return_loan_id = isset($_POST['return_loan_id']) ? (int)$_POST['return_loan_id'] : 0;
    
    if ($return_loan_id > 0) {
        mysqli_begin_transaction($conn);
        $success = true;

        try {
            // 1. Get the book_id associated with the loan
            // We need this to get the book title for the success message
            $sql_book_info = "SELECT book_id FROM loans WHERE loan_id = ?";
            $book_id = 0;
            if ($stmt_info = mysqli_prepare($conn, $sql_book_info)) {
                mysqli_stmt_bind_param($stmt_info, "i", $return_loan_id);
                mysqli_stmt_execute($stmt_info);
                mysqli_stmt_bind_result($stmt_info, $book_id);
                mysqli_stmt_fetch($stmt_info);
                mysqli_stmt_close($stmt_info);
            }
            if ($book_id === 0) {
                throw new Exception("Loan ID not found.");
            }

            // 2. Update the loan record with a return date
            $return_date = date('Y-m-d H:i:s');
            $sql_return = "UPDATE loans SET return_date = ?, returned_by_user_id = ? WHERE loan_id = ? AND return_date IS NULL";
            if ($stmt_return = mysqli_prepare($conn, $sql_return)) {
                // Assuming returned_by_user_id tracks the staff member handling the return
                mysqli_stmt_bind_param($stmt_return, "sii", $return_date, $user_id, $return_loan_id);
                
                if (!mysqli_stmt_execute($stmt_return)) {
                    throw new Exception("Error updating loan record: " . mysqli_error($conn));
                }
                if (mysqli_stmt_affected_rows($stmt_return) === 0) {
                    throw new Exception("Loan record not found or already returned.");
                }
                mysqli_stmt_close($stmt_return);
            } else {
                throw new Exception("SQL error preparing return update: " . mysqli_error($conn));
            }
            
            // The book_copies status update is REMOVED as copies are no longer tracked individually.
            
            // 3. Get book title for confirmation
            $book_title = '';
            $sql_title = "SELECT title FROM books WHERE book_id = ?";
            if ($stmt_title = mysqli_prepare($conn, $sql_title)) {
                mysqli_stmt_bind_param($stmt_title, "i", $book_id);
                mysqli_stmt_execute($stmt_title);
                mysqli_stmt_bind_result($stmt_title, $book_title_fetched);
                mysqli_stmt_fetch($stmt_title);
                mysqli_stmt_close($stmt_title);
                $book_title = $book_title_fetched;
            }


            mysqli_commit($conn);
            $message = "Successfully processed return for '$book_title' (Loan ID: $return_loan_id).";

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to process return: " . $e->getMessage();
        }
    } else {
        $error = "Invalid loan ID for return.";
    }
}


// --- 4. FETCH LOANS FOR DISPLAY ---

// Filter options
$filter_status = $_GET['status'] ?? 'active'; // 'active' or 'all'
$filter_late = $_GET['late'] ?? '0'; // '1' for only late loans

$where_clauses = ["1=1"];

if ($filter_status == 'active') {
    $where_clauses[] = "l.return_date IS NULL";
}

if ($filter_late == '1') {
    // Requires active loans
    $where_clauses[] = "l.return_date IS NULL";
    $where_clauses[] = "l.due_date < NOW()";
}

$where_sql = "WHERE " . implode(' AND ', $where_clauses);

// UPDATED SQL QUERY: Direct join from loans to books
$sql_loans = "
    SELECT 
        l.*, 
        b.title AS book_title, 
        u.username AS member_username,
        u.user_id AS member_user_id
    FROM 
        loans l
    JOIN 
        users u ON l.user_id = u.user_id
    JOIN 
        books b ON l.book_id = b.book_id -- Direct join to books table
    {$where_sql}
    ORDER BY 
        l.loan_date DESC
";

if ($result = mysqli_query($conn, $sql_loans)) {
    $loans = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_free_result($result);
} else {
    $error .= " Failed to fetch loans: " . mysqli_error($conn);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loans & Returns | Library System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .data-table .status-overdue { color: #dc3545; font-weight: bold; }
        .data-table .status-returned { color: #17a2b8; }
        .data-table .status-active { color: #28a745; font-weight: bold; }
        .filter-form { margin-bottom: 20px; display: flex; gap: 15px; align-items: center; }
        .filter-form label { font-weight: bold; }
        .filter-form select, .filter-form button { padding: 8px 12px; border: 1px solid #ccc; border-radius: 4px; }
        .return-btn { background-color: #28a745; color: white; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        
        <?php include 'sidebar.php'; // Includes the sidebar navigation ?>

        <div class="main-content">
            <?php include 'navbar.php'; // Includes the top navigation bar ?>

            <section class="page-content">
                <h1><i class="fas fa-history"></i> Loan and Return Management</h1>
                <p>Track all current and past loans, and process returns.</p>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <h2>Loan History</h2>
                    
                    <form method="GET" action="loansReturn.php" class="filter-form">
                        <label for="status_filter">Show Status:</label>
                        <select name="status" id="status_filter">
                            <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active Loans</option>
                            <option value="all" <?php echo $filter_status == 'all' ? 'selected' : ''; ?>>All Loans (Active & Returned)</option>
                        </select>
                        
                        <label for="late_filter">Filter:</label>
                        <select name="late" id="late_filter">
                            <option value="0" <?php echo $filter_late == '0' ? 'selected' : ''; ?>>All</option>
                            <option value="1" <?php echo $filter_late == '1' ? 'selected' : ''; ?>>Only Overdue (Active)</option>
                        </select>

                        <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                    </form>

                    <?php if (empty($loans)): ?>
                        <p>No loans found matching the current filters.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Loan ID</th>
                                    <th>Book Title</th>
                                    <th>Member</th>
                                    <th>Loan Date</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <?php if ($is_staff): ?>
                                    <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($loans as $loan): 
                                $is_returned = $loan['return_date'] !== NULL;
                                $is_overdue = !$is_returned && (strtotime($loan['due_date']) < time());
                                
                                $status_class = 'status-active';
                                $status_text = 'Active';

                                if ($is_returned) {
                                    $status_text = 'Returned';
                                    $status_class = 'status-returned';
                                    // Check if returned late
                                    if (strtotime($loan['return_date']) > strtotime($loan['due_date'])) {
                                        $status_text .= ' (Late)';
                                        $status_class .= ' status-overdue'; // Overdue class for styling late returns
                                    }
                                } elseif ($is_overdue) {
                                    $status_text = 'OVERDUE';
                                    $status_class = 'status-overdue';
                                }
                            ?>
                            <tr>
                                <td data-label="Loan ID"><?php echo htmlspecialchars($loan['loan_id']); ?></td>
                                <td data-label="Book Title" class="book-title"><?php echo htmlspecialchars($loan['book_title']); ?></td>
                                <td data-label="Member">
                                    <a href="userManagement.php?user_id=<?php echo $loan['member_user_id']; ?>" style="text-decoration: none; font-weight: bold; color: var(--accent-color);">
                                        <?php echo htmlspecialchars($loan['member_username']); ?>
                                    </a>
                                </td>
                                <td data-label="Loan Date"><?php echo date('M d, Y', strtotime($loan['loan_date'])); ?></td>
                                <td data-label="Due Date" class="<?php echo $status_class; ?>"><?php echo date('M d, Y', strtotime($loan['due_date'])); ?></td>
                                <td data-label="Status" class="<?php echo $status_class; ?>"><?php echo $status_text; ?></td>
                                <?php if ($is_staff): ?>
                                <td>
                                    <?php if (!$is_returned): ?>
                                        <form method="POST" action="loansReturn.php" style="margin: 0;" onsubmit="return confirm('Confirm return for Loan ID <?php echo $loan['loan_id']; ?>?');">
                                            <input type="hidden" name="action" value="return">
                                            <input type="hidden" name="return_loan_id" value="<?php echo $loan['loan_id']; ?>">
                                            <button type="submit" class="btn-primary return-btn"><i class="fas fa-undo"></i> Return</button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn-secondary" disabled>Returned</button>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
    
    <script src="scripts.js"></script>
</body>
</html>
<?php
// --- FINAL FIX: Connection closure moved to the end of the script --
if (isset($conn)) {
    mysqli_close($conn);
}
?>