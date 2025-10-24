<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<header>

    <div class="header-left">

        <a href="index.php">Home</a>
        <a href="contact.php">Contact</a>
        <a href="trips.php">Trips</a>

        <?php if(isset($_SESSION["email"])): ?>
            <?php if(($_SESSION["role"] ?? '') === "company"): ?>
                <a href="firmaAdminPanel.php">Company Panel</a>
            <?php endif; ?>

            <?php if(($_SESSION["role"] ?? '') === "admin"): ?>
                <a href="adminPanel.php">Admin Panel</a>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <div class="header-right">

        <?php if(isset($_SESSION["email"])): ?>
            <p>Hello, <?php echo htmlspecialchars($_SESSION["email"]); ?>!</p>

            <form action="index.php" method="post" style="display:inline;">
                <input type="submit" name="logout" value="Log out">
            </form>

            <?php if(($_SESSION["role"] ?? '') !== "admin"): ?>
                <a href="profile.php">Profile</a>
            <?php endif; ?>

        <?php else: ?>
            <a href="login.php">Log in</a>
        <?php endif; ?>
        
    </div>

</header>

