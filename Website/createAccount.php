<?php

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

if(isset($_SESSION["email"])){

    header("Location: index.php");
    exit;

}

$message = "";       
$message_type = ""; 

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $fullname = htmlspecialchars($_POST["fullname"] ?? '');
    $email = htmlspecialchars($_POST["email"] ?? '');
    
    $password = $_POST["password"] ?? '';

    if(!empty($email) && !empty($password)){

        //email format validation
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){

            $message = "Invalid email format!";
            $message_type = "error";

        } 
        
    else{

        //strong password constraints
        $passwordPattern = '/^(?=.*\p{Ll})(?=.*\p{Lu})(?=.*\d)(?=.*[@$!%*?&#^()_\-+=,.;<>\/\\|{}\[\]"]).{8,}$/u';

        if(!preg_match($passwordPattern, $password)){

              $message = "Password must be at least 8 characters long and include at least one uppercase letter, one lowercase letter, one number and one special character.";
              $message_type = "error";

        } 
            
        else{

              //checking if email is already registered
              $checkStmt = $database->prepare("SELECT 1 FROM User WHERE email = :email");
              $checkStmt->bindValue(":email", $email, SQLITE3_TEXT);
              $checkResult = $checkStmt->execute();

              if($checkResult->fetchArray()){

                  $message = "This email is already registered!";
                  $message_type = "error";

              } 
                
              else{

                  //hashing password
                  $hashed = password_hash($password, PASSWORD_DEFAULT);

                  //inserting new user
                  $stmt = $database->prepare("INSERT INTO User(full_name, email, role, password)
                                                VALUES(:full_name, :email, :role, :password)");
                  $stmt->bindValue(":full_name", $fullname ?: null, SQLITE3_TEXT);
                  $stmt->bindValue(":email", $email, SQLITE3_TEXT);
                  $stmt->bindValue(":role", 'user', SQLITE3_TEXT);
                  $stmt->bindValue(":password", $hashed, SQLITE3_TEXT);

                  $result = $stmt->execute();

                  if($result){

                      $message = "Account created successfully!";
                      $message_type = "success";

                      header("Refresh:2; url=login.php");
                       
                  } 
                    
                  else{

                      $message = "An error occurred while creating account.";
                      $message_type = "error";

                  }

              }

        }

    }

    }

    else{

        $message = "Please fill email and password fields.";
        $message_type = "error";

    }

}

include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Account</title>
  <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="content">
  <div class="container">
    <h2>Create Account</h2>

    <?php if (!empty($message)): ?>

      <p class="<?php echo $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
        <?php echo htmlspecialchars($message); ?>
      </p>

    <?php endif; ?>

    <form action="createAccount.php" method="post">

      <label>Full name:</label>
      <input type="text" name="fullname" value="<?php echo isset($fullname) ? htmlspecialchars($fullname) : ''; ?>">

      <label>Email:</label>
      <input type="email" name="email" required value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">

      <label>Password:</label>
      <input type="password" name="password" required>

      <input type="submit" name="create" value="Create Account">

    </form>

    <p class="register-text">
      Already have an account?
      <a href="login.php">Login</a>
    </p>

    <p class="hint">
      Password must be at least 8 characters and include uppercase, lowercase, a number and a special character.
    </p>

  </div>
</div>

</body>
</html>

<?php
include("footer.html");
?>