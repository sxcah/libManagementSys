<?php
// Start the session to access user data
session_start();

// Include the database connection
require_once 'connect.php'; 

// --- 1. SESSION & AUTHENTICATION CHECK ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Set active page for sidebar highlighting
$active_page = 'loanBook.php'; 

// Get user data from session
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 3;
$role_name = 'Unknown Role';

// Define loan parameters (based on typical library system settings)
$MAX_LOANS = 5; // Maximum number of active loans per user
$LOAN_PERIOD_DAYS = 14; // Default loan period
$message = '';
$error = '';
$books = [];
$search_term = $_GET['search'] ?? '';

// Check if the connection is available
if (!$conn) {
    die("Database connection failed.");
}

// --- 2. FETCH ROLE NAME AND CHECK BANNED STATUS (Standard practice) ---
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


// =========================================================
// --- 3. LOAN REQUEST HANDLER (New/Modified Logic) ---
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'loan') {
    
    // Safety check: Only members (role_id 3) should use this user-facing loan system
    if ($role_id != 3) {
        $error = "Staff members must process loans via the Loans & Returns page.";
    } else {
        $target_book_id = isset($_POST['book_id']) ? (int)$_POST['book_id'] : 0;

        if ($target_book_id > 0) {
            
            // --- TRANSACTION START: Ensure atomic loan operation ---
            mysqli_begin_transaction($conn);
            $success = true;

            try {
                // --- VALIDATION CHECKS (Inside transaction for concurrency safety) ---
                
                // 1. Check Max Loans 
                $sql_loan_count = "SELECT COUNT(*) FROM loans WHERE user_id = ? AND return_date IS NULL";
                if ($stmt_count = mysqli_prepare($conn, $sql_loan_count)) {
                    mysqli_stmt_bind_param($stmt_count, "i", $user_id);
                    mysqli_stmt_execute($stmt_count);
                    mysqli_stmt_bind_result($stmt_count, $active_loans);
                    mysqli_stmt_fetch($stmt_count);
                    mysqli_stmt_close($stmt_count);
                    
                    if ($active_loans >= $MAX_LOANS) {
                        $error = "Loan failed: You have reached your maximum limit of {$MAX_LOANS} active loans.";
                        $success = false;
                    }
                } else { $success = false; }


                // 2. Check for Active Loan of *This* Book (Simplified for book_id-based loans)
                if ($success) {
                    $sql_active_book = "
                        SELECT 
                            loan_id 
                        FROM 
                            loans
                        WHERE 
                            user_id = ? AND book_id = ? AND return_date IS NULL 
                        LIMIT 1";
                        
                    if ($stmt_active = mysqli_prepare($conn, $sql_active_book)) {
                        mysqli_stmt_bind_param($stmt_active, "ii", $user_id, $target_book_id);
                        mysqli_stmt_execute($stmt_active);
                        mysqli_stmt_store_result($stmt_active);
                        
                        if (mysqli_stmt_num_rows($stmt_active) > 0) {
                            $error = "Loan failed: You already have an active loan for this book. Please return it first.";
                            $success = false;
                        }
                        mysqli_stmt_close($stmt_active);
                    } else { $success = false; }
                }

                // 3. Check availability by comparing total copies to active loans & EXECUTE LOAN INSERT
                if ($success) {
                    $total_copies = 0;
                    $currently_loaned = 0;
                    
                    // NEW SQL to check availability
                    $sql_check_availability = "
                        SELECT b.total_copies, COUNT(l.loan_id) AS currently_loaned
                        FROM books b
                        LEFT JOIN loans l ON b.book_id = l.book_id AND l.return_date IS NULL
                        WHERE b.book_id = ?
                        GROUP BY b.book_id";

                    if ($stmt_check = mysqli_prepare($conn, $sql_check_availability)) {
                        mysqli_stmt_bind_param($stmt_check, "i", $target_book_id);
                        mysqli_stmt_execute($stmt_check);
                        mysqli_stmt_bind_result($stmt_check, $total_copies, $currently_loaned);
                        mysqli_stmt_fetch($stmt_check);
                        mysqli_stmt_close($stmt_check);

                        if ($total_copies > $currently_loaned) {
                            // Copies are available! Proceed with loan.
                            $loan_date = date('Y-m-d H:i:s');
                            $due_date = date('Y-m-d H:i:s', strtotime("+{$LOAN_PERIOD_DAYS} days"));
                            
                            // NEW SQL: INSERT LOAN using book_id instead of copy_id
                            $sql_insert_loan = "INSERT INTO loans (user_id, book_id, loan_date, due_date) VALUES (?, ?, ?, ?)";
                            
                            if ($stmt_insert = mysqli_prepare($conn, $sql_insert_loan)) {
                                // Binding uses book_id (i) instead of copy_id (i)
                                mysqli_stmt_bind_param($stmt_insert, "iiss", $user_id, $target_book_id, $loan_date, $due_date);
                                
                                if (mysqli_stmt_execute($stmt_insert)) {
                                    $message = "Success! Requested loan for Book ID: {$target_book_id}. Due date: {$due_date}.";
                                    mysqli_commit($conn); // Commit the transaction
                                } else {
                                    $error = "Error inserting loan record: " . mysqli_error($conn);
                                    $success = false;
                                }
                                mysqli_stmt_close($stmt_insert);
                            } else {
                                $error = "Database error: Could not prepare loan insertion statement.";
                                $success = false;
                            }
                        } else {
                            $error = "Loan failed: No available copies of this book were found. (Total: {$total_copies}, Loaned: {$currently_loaned})";
                            $success = false;
                        }

                    } else { 
                        $error = "Database error: Could not prepare availability check statement.";
                        $success = false; 
                    }
                }
            
            } catch (Exception $e) {
                // Catch any unexpected exceptions
                $error = "An unexpected error occurred during the loan process: " . $e->getMessage();
                $success = false;
            }

            // --- TRANSACTION ROLLBACK ---
            if (!$success) {
                mysqli_rollback($conn);
                if (empty($error)) {
                     $error = "An unknown database error occurred. Transaction rolled back.";
                }
            }
            
        } else {
            $error = "Invalid book ID for loan request.";
        }
    }
}
// END LOAN HANDLER


// =========================================================
// --- 4. FETCH LOAN STATUS AND BOOKS FOR DISPLAY (Modified) ---
// =========================================================

// A. Get COUNT of user's active loans (used to disable all buttons if max reached)
$active_loans_count = 0;
$sql_user_loans = "SELECT COUNT(*) FROM loans WHERE user_id = ? AND return_date IS NULL";
if ($stmt_user_loans = mysqli_prepare($conn, $sql_user_loans)) {
    mysqli_stmt_bind_param($stmt_user_loans, "i", $user_id);
    mysqli_stmt_execute($stmt_user_loans);
    mysqli_stmt_bind_result($stmt_user_loans, $active_loans_count);
    mysqli_stmt_fetch($stmt_user_loans);
    mysqli_stmt_close($stmt_user_loans);
}
$is_max_loans = $active_loans_count >= $MAX_LOANS;


// B. Get a list of book_ids the user currently has loaned (used to disable button for that specific book)
$user_loaned_books = [];
// Simplified query: No longer joins book_copies
$sql_loaned_books = "
    SELECT 
        book_id
    FROM 
        loans
    WHERE 
        user_id = ? AND return_date IS NULL
";
if ($stmt_loaned_books = mysqli_prepare($conn, $sql_loaned_books)) {
    mysqli_stmt_bind_param($stmt_loaned_books, "i", $user_id);
    mysqli_stmt_execute($stmt_loaned_books);
    $result_loaned = mysqli_stmt_get_result($stmt_loaned_books);
    while ($row = mysqli_fetch_assoc($result_loaned)) {
        $user_loaned_books[$row['book_id']] = true;
    }
    mysqli_stmt_close($stmt_loaned_books);
}


// C. Main Book Fetching Query: Calculates availability by comparing total copies to active loans for the book_id
$search_param = "%{$search_term}%";
// NEW SQL to fetch books for loan list (Simplified join)
$sql_book_fetch = "
    SELECT b.*, b.total_copies, COUNT(l.loan_id) AS currently_loaned
    FROM books b
    LEFT JOIN loans l ON b.book_id = l.book_id AND l.return_date IS NULL
    WHERE (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.genre LIKE ?)
    GROUP BY b.book_id
    ORDER BY b.title ASC
";

if ($stmt = mysqli_prepare($conn, $sql_book_fetch)) {
    // Bind parameters for title, author, isbn, and genre search
    mysqli_stmt_bind_param($stmt, "ssss", $search_param, $search_param, $search_param, $search_param);
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $books = mysqli_fetch_all($result, MYSQLI_ASSOC);
        
        // Calculate available_copies based on the new total_copies/currently_loaned model
        foreach ($books as &$book) {
            $book['available_copies'] = $book['total_copies'] - $book['currently_loaned'];
            // Ensure available copies isn't negative, though it shouldn't be with correct data
            if ($book['available_copies'] < 0) {
                 $book['available_copies'] = 0;
            }
        }
        unset($book); // Unset reference to avoid side effects
        
    } else {
        $error = "Error fetching books: " . mysqli_error($conn);
    }
    mysqli_stmt_close($stmt);
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Book - Library System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="dashboard-layout">
        <?php 
            // Include sidebar component
            $active_page = 'loanBook.php';
            include 'sidebar.php'; 
        ?>
        
        <div class="main-content">
            <?php include 'navbar.php'; // Include the navbar ?>
            <header>
                <h1><i class="fas fa-book-reader"></i> Browse & Loan Books</h1>
                <p>Find books and request a loan. You currently have **<?php echo $active_loans_count; ?> / <?php echo $MAX_LOANS; ?>** active loans.</p>
            </header>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <section class="book-list-section card">
                <h2>Book Catalog</h2>
                
                <form method="GET" action="loanBook.php" class="search-bar-form">
                    <input type="text" name="search" placeholder="Search by Title, Author, or ISBN..." value="<?php echo htmlspecialchars($search_term); ?>">
                    <button type="submit" class="btn-primary"><i class="fas fa-search"></i> Search</button>
                    <?php if ($search_term): ?>
                        <a href="loanBook.php" class="btn-secondary" style="margin-left: 10px;">Clear Search</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($books)): ?>
                    <p>No books found matching your criteria.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Book ID</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>ISBN</th>
                                <th>Genre</th>
                                <th class="text-center">Available Copies</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($books as $book): ?>
                                <?php 
                                    // Loan Logic based on pre-calculated status
                                    $is_book_available = $book['available_copies'] > 0;
                                    $has_active_loan = isset($user_loaned_books[$book['book_id']]);
                                    $can_loan = $is_book_available && !$is_max_loans && !$has_active_loan;

                                    // Determine the reason for disabling the button
                                    $disabled_text = '';
                                    if ($is_max_loans) {
                                        $disabled_text = 'Loan Limit Reached';
                                    } elseif ($has_active_loan) {
                                        $disabled_text = 'Book Already Loaned';
                                    } elseif (!$is_book_available) {
                                        $disabled_text = 'Out of Stock';
                                    }
                                    
                                    // Highlight books that are not loanable by the user
                                    $row_class = $can_loan ? '' : 'table-row-disabled';
                                ?>
                                <tr class="<?php echo $row_class; ?>">
                                    <td data-label="Book ID"><?php echo htmlspecialchars($book['book_id']); ?></td>
                                    <td data-label="Title" class="book-title"><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td data-label="Author"><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td data-label="ISBN"><?php echo htmlspecialchars($book['isbn']); ?></td>
                                    <td data-label="Genre"><?php echo htmlspecialchars($book['genre']); ?></td>
                                    <td data-label="Available Copies" class="text-center">
                                        <span class="<?php echo $is_book_available ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo htmlspecialchars($book['available_copies']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Action">
                                        <?php if ($can_loan): ?>
                                            <form method="POST" action="loanBook.php" style="margin: 0;" onsubmit="return confirm('Confirm loan request for ' + '<?php echo htmlspecialchars($book['title']); ?>' + '?');">
                                                <input type="hidden" name="action" value="loan">
                                                <input type="hidden" name="book_id" value="<?php echo $book['book_id']; ?>">
                                                <button type="submit" class="btn-primary"><i class="fas fa-plus-circle"></i> Request Loan</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn-danger" disabled>
                                                <i class="fas fa-times-circle"></i> 
                                                <?php echo $disabled_text; ?>
                                            </button>
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
    
    <script src="scripts.js"></script>
</body>
</html>
<?php
// Close the database connection at the end
if (isset($conn)) {
    mysqli_close($conn);
}
?>