<?php

if(session_status() === PHP_SESSION_NONE) {
    session_start();
}

if(isset($_POST["logout"])){
    session_destroy();
    header("Location: index.php");
    exit; 
}

include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <body style="background-image: url('index.jpg'); background-size: cover; background-position: center; background-repeat: no-repeat;">
</head>
<body>

 <div class="content">
    <div class="welcome-box">
        <h1>WELCOME</h1>
    </div>
</div>
</body>

</html>

<?php
include("footer.html");
?>