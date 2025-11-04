<?php
// sidebar.php - Component for the main site navigation sidebar

// This component assumes the following variables are set in the calling script:
// $role_id (1:Admin, 2:Librarian, 3:Member)
// $active_page (e.g., 'dashboard.php', 'bookManagement.php')

// Define the navigation links based on role
$nav_links = [
    // --- Common Links ---
    'dashboard.php' => ['label' => 'Dashboard', 'icon' => 'fas fa-home', 'roles' => [1, 2, 3]],
    
    // NEW: Link for members to borrow books
    'loanBook.php' => ['label' => 'Borrow Books', 'icon' => 'fas fa-book-reader', 'roles' => [1, 2, 3]], 

    // --- Staff & Common Links ---
    // Note: loansReturn.php requires role_id 1 or 2 for full functionality
    'loansReturn.php' => ['label' => 'Loans & Returns', 'icon' => 'fas fa-exchange-alt', 'roles' => [1, 2]],
    'bookManagement.php' => ['label' => 'Book Management', 'icon' => 'fas fa-book-open', 'roles' => [1, 2]],
    
    // --- Admin Links ---\
    'userManagement.php' => ['label' => 'User Management', 'icon' => 'fas fa-users-cog', 'roles' => [1]],
];

// Determine the section title based on the role
$sidebar_title = ($role_id == 1) ? "Admin Panel" : (($role_id == 2) ? "Librarian Tools" : "Member Portal");
?>

<div class="sidebar">
    <h2><?php echo htmlspecialchars($sidebar_title); ?></h2>
    
    <nav class="sidebar-nav">
        <?php foreach ($nav_links as $file => $details): ?>
            <?php 
                // Check if the current user role is allowed to see this link
                if (in_array($role_id, $details['roles'])): 
                    // Set 'active' class for the current page
                    $is_active = (isset($active_page) && $active_page === $file) ? 'active' : '';
            ?>
                <a href="<?php echo htmlspecialchars($file); ?>" class="<?php echo $is_active; ?>">
                    <i class="<?php echo $details['icon']; ?>"></i>
                    <span><?php echo $details['label']; ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>

        <a href="logout.php" class="logout-link">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
</div>