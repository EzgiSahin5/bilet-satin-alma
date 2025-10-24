<?php

if(session_status() === PHP_SESSION_NONE) {
    session_start();
}

include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="container2">
    <h1>Contact Us</h1>
    <p>If you have encountered any problems. You may contact us:</p>
    <form action="#" method="post" onsubmit="alert('Thank you for contacting us! We will contact you within shortest notice!');">
        <input type="text" name="message" required>
        <input class="green-button" type="submit" name="send" value="Send">
    </form>
    <p>You may also contact us via our email or phone addresses:</p>
    <p>Phone: 555 555 5555</p>
    <p>Email: email@gmail.com</p>  
</div>

</body>
</html>

<?php
include("footer.html");
?>