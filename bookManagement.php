<?php
// Start the session to access user data
session_start();

// Include the database connection
require_once 'connect.php';

// --- 1. SECURITY & AUTHENTICATION CHECK ---
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Get user data from session
$username = $_SESSION['username'] ?? 'User';
$role_id = $_SESSION['role_id'] ?? 3;
$role_name = 'Unknown Role';

// Define roles for permission checks
$staff_roles = [1, 2]; // Admin and Librarian
$is_staff = in_array($role_id, $staff_roles);
// Set active page for sidebar highlighting
$active_page = 'bookManagement.php';

$message = '';
$error = '';

// --- FETCH ROLE NAME AND CHECK BANNED STATUS ---
if ($conn) {
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

    // Optional: Check if the user is banned (if applicable)
    // $sql_banned = "SELECT is_banned FROM users WHERE user_id = ?";
    // ... logic to set $is_banned ...
}

// --- 2. LOGIC TO HANDLE NEW BOOK SUBMISSION, UPDATE, AND DELETE ---

// Helper function to sanitize input
function sanitize_input($data) {
    global $conn;
    return mysqli_real_escape_string($conn, trim($data));
}

// REMOVING get_copy_counts - inventory is now managed in the books table

// Handle Add Book
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_book']) && $is_staff) {
    $isbn = sanitize_input($_POST['isbn']);
    $title = sanitize_input($_POST['title']);
    $author = sanitize_input($_POST['author']);
    $publisher = sanitize_input($_POST['publisher']);
    $publication_year = (int)$_POST['publication_year'];
    $genre = sanitize_input($_POST['genre']);
    // NEW VARIABLE: Total copies directly inserted into books table
    $total_copies = (int)$_POST['total_copies'];

    if (empty($title) || empty($author) || $total_copies <= 0) {
        $error = "Title, Author, and number of copies are required, and copies must be > 0.";
    } else {
        // NOTE: The transaction logic is simplified since only one table is directly inserted to
        try {
            // 1. Insert into books table, including the new 'total_copies' column
            // New SQL: Added 'total_copies' to columns
            $sql_book = "INSERT INTO books (isbn, title, author, publisher, publication_year, genre, total_copies) VALUES (?, ?, ?, ?, ?, ?, ?)";
            if ($stmt_book = mysqli_prepare($conn, $sql_book)) {
                // New binding: 'total_copies' (i) added
                mysqli_stmt_bind_param($stmt_book, "ssssisi", $isbn, $title, $author, $publisher, $publication_year, $genre, $total_copies);
                if (!mysqli_stmt_execute($stmt_book)) {
                    throw new Exception("Error adding book details: " . mysqli_error($conn));
                }
                $book_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt_book);
            } else {
                 throw new Exception("SQL error preparing book insertion: " . mysqli_error($conn));
            }

            // The 'book_copies' insertion logic is REMOVED.

            $message = "Successfully added '$title' with $total_copies copies!";

        } catch (Exception $e) {
            $error = "Failed to add book: " . $e->getMessage();
        }
    }
}

// Handle Update Book Details and Copies
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_book_details']) && $is_staff) {
    $book_id = (int)$_POST['book_id'];
    $isbn = sanitize_input($_POST['isbn']);
    $title = sanitize_input($_POST['title']);
    $author = sanitize_input($_POST['author']);
    $publisher = sanitize_input($_POST['publisher']);
    $publication_year = (int)$_POST['publication_year'];
    $genre = sanitize_input($_POST['genre']);
    
    // NEW VARIABLES for simplified inventory
    $new_total_copies = (int)$_POST['total_copies']; // Get updated copy count
    $currently_loaned = (int)$_POST['currently_loaned_hidden']; // Hidden field from modal

    if (empty($title) || empty($author) || $book_id <= 0) {
        $error = "Missing required book information for update.";
    } else {
        try {
            // Validation for total_copies
            if ($new_total_copies < $currently_loaned) {
                throw new Exception("Cannot reduce total copies to $new_total_copies. $currently_loaned copies are currently on loan.");
            }

            // 1. Update book details in 'books' table, including the new 'total_copies' column
            // New SQL: Added 'total_copies' to SET clause
            $sql_details = "UPDATE books SET isbn=?, title=?, author=?, publisher=?, publication_year=?, genre=?, total_copies=? WHERE book_id=?";
            if ($stmt_details = mysqli_prepare($conn, $sql_details)) {
                // New binding: 'total_copies' (i) added before 'book_id'
                mysqli_stmt_bind_param($stmt_details, "ssssisii", $isbn, $title, $author, $publisher, $publication_year, $genre, $new_total_copies, $book_id);
                if (!mysqli_stmt_execute($stmt_details)) {
                    throw new Exception("Error updating book details: " . mysqli_error($conn));
                }
                mysqli_stmt_close($stmt_details);
            } else {
                throw new Exception("SQL error preparing book details update: " . mysqli_error($conn));
            }
            
            // Inventory update logic is now a single UPDATE statement, no longer involving book_copies.
            $message = "Book details for '$title' updated successfully! Total copies set to $new_total_copies.";

        } catch (Exception $e) {
            $error = "Failed to update book: " . $e->getMessage();
        }
    }
}

// Handle Delete Book
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_book']) && $is_staff) {
    $book_id = (int)$_POST['book_id'];

    if ($book_id > 0) {
        mysqli_begin_transaction($conn);
        try {
            // Check for active loans (direct join to loans table on book_id)
            // The loans table is assumed to now link to book_id directly for this simplified model
            $sql_check_loans = "SELECT COUNT(l.loan_id) FROM loans l WHERE l.book_id = ? AND l.return_date IS NULL";
            $count = 0;
            if ($stmt_check = mysqli_prepare($conn, $sql_check_loans)) {
                mysqli_stmt_bind_param($stmt_check, "i", $book_id);
                mysqli_stmt_execute($stmt_check);
                mysqli_stmt_bind_result($stmt_check, $count);
                mysqli_stmt_fetch($stmt_check);
                mysqli_stmt_close($stmt_check);
            }

            if ($count > 0) {
                throw new Exception("Cannot delete book. $count copies are currently on loan.");
            }

            // 1. Get book title for message
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

            // 2. The DELETE FROM book_copies is REMOVED.
            // NOTE: If the loans table still links to copy_id, or if foreign keys exist,
            // this delete may fail unless those tables are also cleaned up. Assuming a clean foreign key setup.

            // 3. Delete the book record
            $sql_delete_book = "DELETE FROM books WHERE book_id = ?";
            if ($stmt_book = mysqli_prepare($conn, $sql_delete_book)) {
                mysqli_stmt_bind_param($stmt_book, "i", $book_id);
                if (!mysqli_stmt_execute($stmt_book)) {
                    throw new Exception("Error deleting book: " . mysqli_error($conn));
                }
                mysqli_stmt_close($stmt_book);
            } else {
                 throw new Exception("SQL error preparing book deletion: " . mysqli_error($conn));
            }

            mysqli_commit($conn);
            $message = "Book '$book_title' successfully deleted.";

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to delete book: " . $e->getMessage();
        }
    } else {
        $error = "Invalid book ID for deletion.";
    }
}


// --- 3. FETCH ALL BOOKS AND THEIR COPY COUNTS ---
$books = [];
// NEW SQL to fetch books and copy counts (as provided in prompt)
$sql_fetch = "
    SELECT b.*, 
            b.total_copies,
            COUNT(l.loan_id) AS currently_loaned
        FROM books b
        LEFT JOIN loans l ON b.book_id = l.book_id AND l.return_date IS NULL
        GROUP BY b.book_id
        ORDER BY b.title
";

if ($result = mysqli_query($conn, $sql_fetch)) {
    while ($row = mysqli_fetch_assoc($result)) {
        // Calculate available_copies based on the new model
        $row['available_copies'] = $row['total_copies'] - $row['currently_loaned'];
        $books[] = $row;
    }
    mysqli_free_result($result);
} else {
    $error .= " Failed to fetch book inventory: " . mysqli_error($conn);
}


// --- 4. HTML OUTPUT (CLEANED OF INLINE STYLES) ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Management | Library System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
        .required-star { color: red; }
        .form-group-span-2 { grid-column: span 2; }
        .stat-number-error { font-weight: bold; color: #dc3545; }
        .stat-number-warning { font-weight: bold; color: #ffc107; }
        .stat-number-success { font-weight: bold; color: var(--accent-color); }
        .inline-form { display: inline; }
        .loaned-count-display { font-size: 1.2em; font-weight: bold; color: #dc3545; }
        .warning-text-error { color: #dc3545; }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        
        <?php include 'sidebar.php'; // Includes the sidebar navigation ?>

        <div class="main-content">
            <?php include 'navbar.php'; // Includes the top navigation bar ?>

            <section class="page-content">
                <h1><i class="fas fa-book-open"></i> Book Inventory & Management</h1>
                <p>Manage the entire library catalog, add new titles, update details, and handle inventory counts.</p>
                
                <?php if (!empty($message)): ?>
                    <div class="message success-message">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="message error-message">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($is_staff): ?>
                <div class="card-form">
                    <h2>Add New Book Title</h2>
                    <form action="bookManagement.php" method="POST">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="title">Title <span class="required-star">*</span></label>
                                <input type="text" id="title" name="title" required>
                            </div>
                            <div class="form-group">
                                <label for="author">Author <span class="required-star">*</span></label>
                                <input type="text" id="author" name="author" required>
                            </div>
                            <div class="form-group">
                                <label for="isbn">ISBN (Optional)</label>
                                <input type="text" id="isbn" name="isbn">
                            </div>
                            <div class="form-group">
                                <label for="publisher">Publisher (Optional)</label>
                                <input type="text" id="publisher" name="publisher">
                            </div>
                            <div class="form-group">
                                <label for="publication_year">Year (Optional)</label>
                                <input type="number" id="publication_year" name="publication_year" min="1000" max="<?php echo date('Y'); ?>" value="<?php echo date('Y'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="genre">Genre (Optional)</label>
                                <input type="text" id="genre" name="genre">
                            </div>
                            <div class="form-group form-group-span-2">
                                <label for="total_copies">Number of Copies (Total Inventory) <span class="required-star">*</span></label>
                                <input type="number" id="total_copies" name="total_copies" min="1" value="1" required>
                            </div>
                        </div>
                        <button type="submit" name="add_book" class="btn-add"><i class="fas fa-plus-circle"></i> Add Book to Inventory</button>
                    </form>
                </div>
                <?php endif; ?>
                <div class="card">
                    <h2>Library Catalog (<?php echo count($books); ?> Unique Titles)</h2>
                    <div class="table-container">
                        <table class="book-table">
                            <thead>
                                <tr>
                                    <th>Book ID</th> 
                                    <th>Title</th>
                                    <th>Author</th>
                                    <th>ISBN</th>
                                    <th>Year</th>
                                    <th>Genre</th>
                                    <th>Total Copies</th>
                                    <th>Available</th>
                                    <th>Loaned</th> 
                                    <?php if ($is_staff): ?>
                                    <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($books)): ?>
                                    <?php foreach ($books as $book): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($book['book_id']); ?></td>
                                            <td><?php echo htmlspecialchars($book['title']); ?></td>
                                            <td><?php echo htmlspecialchars($book['author']); ?></td>
                                            <td><?php echo htmlspecialchars($book['isbn'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($book['publication_year'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($book['genre'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($book['total_copies']); ?></td>
                                            <td class="<?php echo ($book['available_copies'] > 0) ? 'stat-number-success' : 'stat-number-error'; ?>">
                                                <?php echo htmlspecialchars($book['available_copies']); ?>
                                            </td>
                                            <td class="<?php echo ($book['currently_loaned'] > 0) ? 'stat-number-warning' : ''; ?>">
                                                <?php echo htmlspecialchars($book['currently_loaned']); ?>
                                            </td> 
                                            <?php if ($is_staff): ?>
                                            <td>
                                                <button 
                                                    class="btn-edit" 
                                                    title="Edit Book Details & Quantity"
                                                    onclick="openEditModal(
                                                        <?php echo $book['book_id']; ?>, 
                                                        '<?php echo htmlspecialchars(addslashes($book['title'])); ?>', 
                                                        '<?php echo htmlspecialchars(addslashes($book['isbn'])); ?>',
                                                        '<?php echo htmlspecialchars(addslashes($book['author'])); ?>',
                                                        '<?php echo htmlspecialchars(addslashes($book['publisher'])); ?>',
                                                        <?php echo (int)$book['publication_year']; ?>,
                                                        '<?php echo htmlspecialchars(addslashes($book['genre'])); ?>',
                                                        <?php echo (int)$book['total_copies']; ?>,          <?php echo (int)$book['currently_loaned']; ?>       )">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>

                                                <form method="POST" action="bookManagement.php" class="inline-form" onsubmit="return confirm('WARNING: This will permanently delete the book \'<?php echo htmlspecialchars(addslashes($book['title'])); ?>\'. This action cannot be undone. Are you sure you want to delete \'<?php echo htmlspecialchars(addslashes($book['title'])); ?>\'?');">
                                                    <input type="hidden" name="delete_book" value="1">
                                                    <input type="hidden" name="book_id" value="<?php echo htmlspecialchars($book['book_id']); ?>">
                                                    <button type="submit" class="btn-delete" title="Delete Book"><i class="fas fa-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?php echo $is_staff ? 10 : 9; ?>" style="text-align: center; color: var(--secondary-color);">No books found in the inventory.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>
        </div>
    </div>
    
    <div id="editBookModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeEditModal()">&times;</span>
            <h2>Edit Book Details & Inventory</h2>
            <p>Editing: <strong id="modalBookTitle"></strong></p>
            <form action="bookManagement.php" method="POST">
                <input type="hidden" name="update_book_details" value="1">
                <input type="hidden" id="modalBookId" name="book_id">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_title">Title</label>
                        <input type="text" id="edit_title" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_author">Author</label>
                        <input type="text" id="edit_author" name="author" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_isbn">ISBN</label>
                        <input type="text" id="edit_isbn" name="isbn">
                    </div>
                    <div class="form-group">
                        <label for="edit_publisher">Publisher</label>
                        <input type="text" id="edit_publisher" name="publisher">
                    </div>
                    <div class="form-group">
                        <label for="edit_publication_year">Year</label>
                        <input type="number" id="edit_publication_year" name="publication_year" min="1000" max="<?php echo date('Y'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="edit_genre">Genre</label>
                        <input type="text" id="edit_genre" name="genre">
                    </div>
                </div>

                <hr class="form-separator">
                <h3>Inventory Management</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="edit_total_copies">Total Copies</label>
                        <input type="number" id="edit_total_copies" name="total_copies" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Copies Currently Loaned</label>
                        <p class="loaned-count-display" id="loanedCountDisplay">0</p>
                        <input type="hidden" id="currently_loaned_hidden" name="currently_loaned_hidden"> 
                        <small class="warning-text-error">You cannot set 'Total Copies' below the 'Currently Loaned' count.</small>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn primary-btn">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        // Modal logic
        const modal = document.getElementById('editBookModal');
        const modalBookId = document.getElementById('modalBookId');
        const modalBookTitle = document.getElementById('modalBookTitle');
        const editTitle = document.getElementById('edit_title');
        const editAuthor = document.getElementById('edit_author');
        const editIsbn = document.getElementById('edit_isbn');
        const editPublisher = document.getElementById('edit_publisher');
        const editYear = document.getElementById('edit_publication_year');
        const editGenre = document.getElementById('edit_genre');
        const editTotalCopies = document.getElementById('edit_total_copies');
        const loanedCountDisplay = document.getElementById('loanedCountDisplay');
        // UPDATED: Renamed from editLoanedCopies to match new hidden input
        const currentlyLoanedHidden = document.getElementById('currently_loaned_hidden');

        // Modified openEditModal function to accept copy counts
        function openEditModal(bookId, title, isbn, author, publisher, year, genre, totalCopies, currentlyLoaned) {
            modalBookId.value = bookId;
            modalBookTitle.textContent = title;
            editTitle.value = title;
            editAuthor.value = author;
            editIsbn.value = isbn;
            editPublisher.value = publisher;
            editYear.value = year;
            editGenre.value = genre;
            
            // INVENTORY FIELDS
            editTotalCopies.value = totalCopies;
            loanedCountDisplay.textContent = currentlyLoaned;
            // Store the count in the hidden field for server-side validation
            currentlyLoanedHidden.value = currentlyLoaned; 
            // Set minimum based on loaned copies
            editTotalCopies.min = currentlyLoaned; 

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
// --- FINAL FIX: Connection closure moved to the end of the script ---\
if (isset($conn)) {
    mysqli_close($conn);
}
?>