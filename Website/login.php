<?php

if(session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

if(isset($_SESSION["email"])){

    header("Location: index.php");
    exit;

}

$message = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $email=htmlspecialchars($_POST["email"] ?? '');
    
    $password = $_POST["password"] ?? '';
 
    if(!empty($email) && !empty($password)){ //email and password cannot be empty

        //email format validation
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
            $message = "Invalid email format!";        
        }

        else{

            //finding the user who has that email from the database 
            $stmt = $database->prepare("SELECT * FROM User WHERE email = :email");
            $stmt->bindValue(":email", $email, SQLITE3_TEXT);

            $result = $stmt->execute();

            $user = $result->fetchArray(SQLITE3_ASSOC);

            //if user with that email exists and the inputted password matches the hash stored in the database
            if($user && password_verify($password, $user["password"])){
          
                $_SESSION["email"] = $user["email"]; //assigning session to keep the user logged in
                $_SESSION["role"] = $user["role"];

                session_regenerate_id(true);

                header("Location:index.php");

                exit;

            } 
        
            else{
                $message = "Invalid email or password!";
            }

        }
        
    }

    else{
        $message = "Missing email or password!";
    }

}

include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="content">
    <div class="container">
        <h2>Login</h2>

        <?php if (!empty($message)): ?>

            <p class="error-message">
                <?php echo htmlspecialchars($message); ?>
            </p>
            
        <?php endif; ?>
        
        <form action="login.php" method="post">

            <label>Email:</label>
            <input type="email" name="email" required>

            <label>Password:</label>
            <input type="password" name="password" required>

            <input type="submit" name="login" value="Log in">
            
        </form>

        <p class="register-text">
            Don’t have an account?
            <a href="createAccount.php">Create one</a>
        </p>
        
    </div>
</div>

</body>
</html>

<?php
include("footer.html");
?>
