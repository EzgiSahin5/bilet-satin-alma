<?php

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

include("header.php");

$message = "";       
$message_type = ""; 

//checking if an admin is viewing the page
if(!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin"){

    header("Location: index.php");
    exit;

}

function getCompanyName(){ //for making a dropdown list with existing company names

     global $database;

        //getting all company names from the database in alphabetic order
        $companyStmt = $database->prepare("SELECT name FROM Bus_Company ORDER BY name");
        
        $companyResult = $companyStmt->execute();

            while($companyRow = $companyResult->fetchArray(SQLITE3_ASSOC)){

                $filtered = htmlspecialchars($companyRow["name"]);

                echo '<option value="' . $filtered . '">' . $filtered . '</option>';

            }

}


function getCompany(){

    global $database;

    $stmt = $database->prepare("SELECT id, name, logo_path FROM Bus_Company ORDER BY name");

    $result = $stmt->execute();

    $companies = [];

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $companies[] = $row;
    }

    return $companies;

}

function getCoupon(){

    global $database;

    $stmt = $database->prepare("SELECT id, code, discount, usage_limit, expire_date FROM Coupons");

    $result = $stmt->execute();

    $coupons = [];

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $coupons[] = $row;
    }

    return $coupons;

}

function getTripIds($companyId){

    global $database;

    if(empty($companyId)){
        return [];
    }

    $stmt = $database->prepare("SELECT id FROM Trips WHERE company_id = :company_id");
    $stmt->bindValue(":company_id", $companyId, SQLITE3_TEXT);

    $result = $stmt->execute();

    $tripIds = [];

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $tripIds[] = $row["id"];
    }

    return $tripIds;

}

$companies = getCompany();
$coupons = getCoupon();

//ADDING NEW COMPANY-------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["addNewCompany"])){

    //filtering for XSS
    $companyName = htmlspecialchars($_POST["companyName"] ?? '');
    $logo_path = htmlspecialchars($_POST["logo_path"] ?? '');

    //checking if the url format is valid
     if(!empty($logo_path) && !filter_var($logo_path, FILTER_VALIDATE_URL)){

        $message = "Invalid URL format!";
        $message_type = "error";

    }

    elseif(!empty($companyName)){

        //checking if the company name already exists in the database
        $checkStmt = $database->prepare("SELECT 1 FROM Bus_Company WHERE name = :companyName");
        $checkStmt->bindValue(":companyName", $companyName, SQLITE3_TEXT);
        $checkResult = $checkStmt->execute();
        
        //if company name exits, give warning
        if($checkResult->fetchArray()) {

         $message = "This company already exists!";
         $message_type = "error";

        }

        else{

       //insert new company to the database
        $stmt = $database->prepare("INSERT INTO Bus_Company(name, logo_path)
                                       VALUES(:companyName, :logo_path)");

        $stmt->bindValue(":companyName", $companyName, SQLITE3_TEXT);
        $stmt->bindValue(":logo_path", $logo_path ?: null, SQLITE3_TEXT);

        $result = $stmt->execute();
        
        //new company added successfully
        if($result){

            $message = "New Bus Company created successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");

        } 

        else{

            $message = "An error occurred while adding company!";
            $message_type = "error";

        }

    }

    }
    
    else{

        $message = "Company name cannot be empty!";
        $message_type = "error";

    }

}

//DELETING COMPANY--------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["deleteCompany"])){

    $companyId = htmlspecialchars($_POST["company_id"] ?? '');

    if($companyId){

        try{
            
            //begin transaction
            $database->exec('BEGIN TRANSACTION');

            $tripIds = getTripIds($companyId);//getting the company's trips' ids 

            if(!empty($tripIds)){

                $placeholder = implode(',', array_fill(0, count($tripIds), '?'));

                //finding affected active tickets
                $ticketsStmt = $database->prepare("SELECT id, user_id, total_price FROM Tickets WHERE trip_id IN ($placeholder) AND status = 'active' ");

                foreach($tripIds as $k => $id){
                    $ticketsStmt->bindValue(($k + 1), $id, SQLITE3_TEXT);
                }

                $ticketsResult = $ticketsStmt->execute();

                $refunds = [];
                $affectedTicketIds = [];

                while($ticket = $ticketsResult->fetchArray(SQLITE3_ASSOC)){

                    $userId = $ticket["user_id"];
                    $price = (int)$ticket["total_price"];
                    $affectedTicketIds[] = $ticket["id"];

                    if(!isset($refunds[$userId])){
                        $refunds[$userId] = 0;
                    }

                    $refunds[$userId] = $refunds[$userId] + $price;

                }

                //if there is any affected tickets
                if(!empty($affectedTicketIds)){

                    //if any refund is needed
                    if(!empty($refunds)){
                         foreach ($refunds as $userId => $amount){
                             if($amount > 0){

                                 $updateBalanceStmt = $database->prepare("UPDATE User SET balance = balance + :amount WHERE id = :user_id");
                                 $updateBalanceStmt->bindValue(":amount", $amount, SQLITE3_INTEGER);
                                 $updateBalanceStmt->bindValue(":user_id", $userId, SQLITE3_TEXT);

                                 if(!$updateBalanceStmt->execute()) throw new Exception("Error updating balance for user ID: $userId");
                             }
                         }
                    }

                    //unset booked seats
                    $ticketPlaceholder = implode(',', array_fill(0, count($affectedTicketIds), '?'));
                    $deleteSeatsStmt = $database->prepare("DELETE FROM Booked_Seats WHERE ticket_id IN ($ticketPlaceholder)");

                    foreach($affectedTicketIds as $k => $id){
                        $deleteSeatsStmt->bindValue(($k + 1), $id, SQLITE3_TEXT);
                    }

                    if(!$deleteSeatsStmt->execute()) throw new Exception("Error deleting booked seats.");

                    //delete tickets
                    $deleteTicketsStmt = $database->prepare("DELETE FROM Tickets WHERE id IN ($ticketPlaceholder)");

                    foreach($affectedTicketIds as $k => $id){
                        $deleteTicketsStmt->bindValue(($k + 1), $id, SQLITE3_TEXT);
                    }

                    if(!$deleteTicketsStmt->execute()) throw new Exception("Error deleting tickets.");

                }

                //deleting trips
                $deleteTripsStmt = $database->prepare("DELETE FROM Trips WHERE company_id = :company_id");
                $deleteTripsStmt->bindValue(":company_id", $companyId, SQLITE3_TEXT);

                if(!$deleteTripsStmt->execute()) throw new Exception("Error deleting trips.");

            } 

            //delete coupons
            $deleteCouponsStmt = $database->prepare("DELETE FROM Coupons WHERE company_id = :company_id");
            $deleteCouponsStmt->bindValue(":company_id", $companyId, SQLITE3_TEXT);
            
            $deleteCouponsResult = $deleteCouponsStmt->execute();

            if($deleteCouponsResult === false) throw new Exception("Error deleting coupons.");

            //updating user role and user company_id
            $updateUsersStmt = $database->prepare("UPDATE User SET role = 'user', company_id = NULL WHERE company_id = :company_id");
            $updateUsersStmt->bindValue(":company_id", $companyId, SQLITE3_TEXT);

            $updateUserResult = $updateUsersStmt->execute();

            if ($updateUserResult === false) throw new Exception("Error updating user role.");

            //deleting company
            $deleteCompanyStmt = $database->prepare("DELETE FROM Bus_Company WHERE id = :id");
            $deleteCompanyStmt->bindValue(":id", $companyId, SQLITE3_TEXT);

            if(!$deleteCompanyStmt->execute()) throw new Exception("Error deleting company.");

            $database->exec('COMMIT');

            $message = "Company deleted successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");

        } 
        
        catch(Exception $e){

            //reverse changes
            $database->exec('ROLLBACK');

            $message = "Error deleting company: " . $e->getMessage();
            $message_type = "error";

        }

    } 
    
    else{

        $message = "Please select a company to delete!";
        $message_type = "error";

    }

}

//UPDATE EXISTING COMPANY--------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && (isset($_POST["updateCompany"]) || isset($_POST["unsetLogo"]))){

    //filtering for XSS
    $companyId = htmlspecialchars($_POST["company_id"] ?? null);
    $newName = htmlspecialchars($_POST["newName"] ?? null);
    $newLogoPath = htmlspecialchars($_POST["newLogoPath"] ?? null);
     
    //if logo path input isn't empty, validating url format
    if(!empty($newLogoPath) && !filter_var($newLogoPath, FILTER_VALIDATE_URL)){

        $message = "Invalid URL format!";
        $message_type = "error";

    }
    
    elseif(isset($_POST["unsetLogo"])){//if unset button is pressed, unset first

        //unsetting logo
        $unsetLogoStmt = $database->prepare("UPDATE Bus_Company SET logo_path = NULL WHERE id = :id");
        $unsetLogoStmt->bindValue(":id", $companyId, SQLITE3_TEXT);

        $result = $unsetLogoStmt->execute();

        if($result){

            $message = "Logo path removed successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");

        } 

        else{

            $message = "An error occurred while unsetting logo path!";
            $message_type = "error";

        }

    }

    else{

    //if unset button is not pressed, name and logo path can be updated
    if($companyId){

        $result = false;
        $result2 = false;

         if(!empty($newName)){

           $updateCompanyStmt = $database->prepare("UPDATE Bus_Company SET name = :newName WHERE id = :id");
           $updateCompanyStmt->bindValue(":newName", $newName, SQLITE3_TEXT);
           $updateCompanyStmt->bindValue(":id", $companyId, SQLITE3_TEXT);

           $result = $updateCompanyStmt->execute();

         }

         if(!empty($newLogoPath)){

            $stmt = $database->prepare("UPDATE Bus_Company SET logo_path=:newLogo WHERE id=:id");
            $stmt->bindValue(":newLogo", $newLogoPath, SQLITE3_TEXT);
            $stmt->bindValue(":id", $companyId, SQLITE3_TEXT);

           $result2 = $stmt->execute();

         }

         if($result || $result2){

            $message = "Company updated successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");
            
         } 

         else{

            $message = "An error occurred!";
            $message_type = "error";

         }
 
    }

    else{

      $message = "Invalid Company ID!";
      $message_type = "error";

    }

  }

}

//GIVING USER FIRMA ADMIN AUTHORIZATION-----------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["addFirmaAdmin"])){
    
    //filtering for XSS
    $email = htmlspecialchars($_POST["email"] ?? '');
    $searchName = htmlspecialchars($_POST["searchName"] ?? '');

    if(!empty($email) && !empty($searchName)){
        
        //email format validation
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)){

            $message = "Invalid email format!";
            $message_type = "error";

        }

        else{

        //checking if user with that email exists
        $userStmt = $database->prepare("SELECT id FROM User WHERE email = :email");
        $userStmt->bindValue(":email", $email, SQLITE3_TEXT);

        $userResult = $userStmt->execute();

        $user = $userResult->fetchArray(SQLITE3_ASSOC);
        
        //if user doesn't exists, give warning
        if(!$user){

         $message = "This user doesn't exist!";
         $message_type = "error";

        }

        else{

        //checking if the company name exists in the database and get id
        $checkStmt = $database->prepare("SELECT id FROM Bus_Company WHERE name = :searchName");
        $checkStmt->bindValue(":searchName", $searchName, SQLITE3_TEXT);
        $checkResult = $checkStmt->execute();

        $company = $checkResult->fetchArray(SQLITE3_ASSOC);
        
        //if company name doesn't exist, give warning
        if(!($company)){

         $message = "This company doesn't exist!";
         $message_type = "error";

        }

        else{

       //SQL injection safety
        $updateStmt = $database->prepare("UPDATE User SET role = 'company', company_id = :company_id WHERE email = :email");
        $updateStmt->bindValue(":company_id", $company["id"], SQLITE3_TEXT);
        $updateStmt->bindValue(":email", $email, SQLITE3_TEXT);

        $result = $updateStmt->execute();

        //operation successful
        if($result){

            $message = "Authorization given successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

      }

    }

  }

}
    
    else{

        $message = "Missing email or company name!";
        $message_type = "error";

    }

}

//ADDING NEW COUPON-------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["addNewCoupon"])){

    //filtering for XSS
    $code = htmlspecialchars($_POST["code"] ?? '');
    $discount = filter_var($_POST["discount"], FILTER_VALIDATE_FLOAT);
    $usageLimit = filter_var($_POST["usageLimit"], FILTER_VALIDATE_INT);
    $expireDateInput = $_POST["expireDate"] ?? '';

    //converting expire date to correct format (Y-m-d H:i:s)
    $expireDate = !empty($expireDateInput) ? date('Y-m-d H:i:s', strtotime($expireDateInput)) : false;

    //validating inputs
    if($discount === false || $usageLimit === false || $expireDate == false){

        $message = "Invalid input!";
        $message_type = "error";

    }

    elseif(empty($code) || empty($discount) || empty($usageLimit) || empty($expireDate) ){

        //checking if required fields is empty
        $message = "Please fill all required fields!";
        $message_type = "error";

        }

        else{

       //insert new coupon to the database
        $stmt = $database->prepare("INSERT INTO Coupons(code, discount, company_id, usage_limit, expire_date)
                                       VALUES(:code, :discount, :company_id, :usage_limit, :expire_date)");

        $stmt->bindValue(":code", $code, SQLITE3_TEXT);
        $stmt->bindValue(":discount", $discount, SQLITE3_FLOAT);
        $stmt->bindValue(":company_id", null, SQLITE3_NULL);
        $stmt->bindValue(":usage_limit", $usageLimit, SQLITE3_INTEGER);
        $stmt->bindValue(":expire_date", $expireDate, SQLITE3_TEXT);

        $result = $stmt->execute();
        
        //new coupon added successfully
        if($result){

            $message = "New Coupon created successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

    }

}

//DELETING COUPON-----------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["deleteCoupon"])){

       $id = htmlspecialchars($_POST["coupon_id"] ?? '');

        if(!$id){

        $message = "Coupon ID not found!";
        $message_type = "error";

        }
   
        else{

        //deleting the coupon
        $deleteStmt = $database->prepare("DELETE FROM Coupons WHERE id = :id");
        $deleteStmt->bindValue(":id", $id, SQLITE3_TEXT);

        $result = $deleteStmt->execute();

        //delete successfull
        if($result){

            $message = "Coupon deleted successfully!";
            $message_type = "success";

            header("refresh:2; url=adminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

    }

}

//UPDATE EXISTING COUPON--------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["updateCoupon"])) {

    //filtering for XSS
    $couponId = htmlspecialchars($_POST["coupon_id"] ?? '');
    $newCode = htmlspecialchars($_POST["newCode"] ?? '');
    $newDiscount = filter_var($_POST["newDiscount"], FILTER_VALIDATE_FLOAT);
    $newUsageLimit = filter_var($_POST["newUsageLimit"], FILTER_VALIDATE_INT);
    $newExpireDateInput = $_POST["newExpireDate"] ?? '';

    //convert expire date to correct format
    $newExpireDate = !empty($newExpireDateInput) ? date('Y-m-d H:i:s', strtotime($newExpireDateInput)) : false;

    //validate inputs
    if(empty($couponId) || empty($newCode) || $newDiscount === false || $newUsageLimit === false || $newExpireDate === false){

        $message = "Please fill all required fields correctly!";
        $message_type = "error";

    }

    else{

    //update the coupon in the database
    $updateStmt = $database->prepare("UPDATE Coupons SET code = :code, discount = :discount, usage_limit = :usage_limit, 
                                    expire_date = :expire_date WHERE id = :id");
    $updateStmt->bindValue(":code", $newCode, SQLITE3_TEXT);
    $updateStmt->bindValue(":discount", $newDiscount, SQLITE3_FLOAT);
    $updateStmt->bindValue(":usage_limit", $newUsageLimit, SQLITE3_INTEGER);
    $updateStmt->bindValue(":expire_date", $newExpireDate, SQLITE3_TEXT);
    $updateStmt->bindValue(":id", $couponId, SQLITE3_TEXT);

    $result = $updateStmt->execute();

    if($result){

        $message = "Coupon updated successfully!";
        $message_type = "success";

        header("refresh:2; url=adminPanel.php");

    } 
    
    else{

        $message = "An error occurred while updating the coupon!";
        $message_type = "error";

    }

  }

}
    

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<h1>Admin Panel</h1>

   <?php if (!empty($message)): ?>

      <p class="<?php echo $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
        <?php echo htmlspecialchars($message); ?>
      </p>

    <?php endif; ?>

<div class="container3">
    <h2>Add Bus Company</h2>
    <!--adding new bus company-->
    <form action="adminPanel.php" method="post">

        <input type="text" name="companyName" placeholder="Company Name" required>

        <input type="url" name="logo_path" placeholder="Logo Path">

        <input class="green-button" type="submit" name="addNewCompany" value="Add">

    </form>
</div>

    <table>
<thead>
    <tr>
        <th>ID</th>
        <th>Company Name</th>
        <th>Logo</th>
        <th>Delete</th>
        <th>Update</th>
    </tr>
</thead>
<tbody>
    <?php foreach($companies as $company): ?>
    <tr>
        <td><?= htmlspecialchars($company['id']) ?></td>
        <td><?= htmlspecialchars($company['name']) ?></td>
        <td>
            <?php if(!empty($company['logo_path'])): ?>
                <img src="<?= htmlspecialchars($company['logo_path']) ?>" alt="Logo" width="50">
            <?php else: ?>
                -
            <?php endif; ?>
        </td>
        <td>
            <form method="post" onsubmit="return confirm('Are you sure you want to delete this company?');">
                <input type="hidden" name="company_id" value="<?= htmlspecialchars($company['id']) ?>">
                <input class="red-button" type="submit" name="deleteCompany" value="Delete">
            </form>
        </td>
        <td>
        <form method="post">
            <input type="hidden" name="company_id" value="<?= htmlspecialchars($company['id']) ?>">
            <input type="text" name="newName" value="<?= htmlspecialchars($company['name']) ?>" placeholder="New Name">
            <input type="url" name="newLogoPath" value="<?= htmlspecialchars($company['logo_path']) ?>" placeholder="New Logo URL">
            <input class="blue-button" type="submit" name="updateCompany" value="Update">
            <input class="red-button" type="submit" name="unsetLogo" value="Unset Logo">
        </form>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
</table>

<div class="container3">
     <h2>Give Firma Admin Authorization to a User</h2>
     <!--updating a user as a firma admin-->
     <form action="adminPanel.php" method="post">

        <input  type="text" name="email" placeholder="email" required>

        <label for="searchNameAdd">Select company name:</label>
        <select id="searchNameAdd" name="searchName" required>
           <option value="">--Select Company--</option>
              <?php getCompanyName(); ?>
        </select>

        <input class="green-button" type="submit" name="addFirmaAdmin" value="Add">

    </form>

    <h2>Add Coupons</h2>
    <!--adding new coupon-->
    <form action="adminPanel.php" method="post">

        <input type="text" name="code" placeholder="Code" required>

        <input type="number" name="discount" placeholder="Discount" required>

        <input type="number" name="usageLimit" placeholder="Usage Limit" required>

        <label>Expire Date:</label>
        <input type="datetime-local" name="expireDate" required>

        <input class="green-button" type="submit" name="addNewCoupon" value="Add">

    </form>
    </div>

     <table>
<thead>
    <tr>
        <th>Code</th>
        <th>Discount</th>
        <th>Usage Limit</th>
        <th>Expire Date</th>
        <th>Delete</th>
        <th>Update</th>
    </tr>
</thead>
<tbody>
    <?php foreach($coupons as $coupon): ?>
    <tr>
        <td><?= htmlspecialchars($coupon['code']) ?></td>
        <td><?= htmlspecialchars($coupon['discount']) ?></td>
        <td><?= htmlspecialchars($coupon['usage_limit']) ?> </td>
        <td><?= htmlspecialchars($coupon['expire_date']) ?> </td>
        <td>
            <form method="post" onsubmit="return confirm('Are you sure you want to delete this coupon?');">
                <input type="hidden" name="coupon_id" value="<?= htmlspecialchars($coupon['id']) ?>">
                <input class="red-button" type="submit" name="deleteCoupon" value="Delete">
            </form>
        </td>
        <td>
        <form method="post">
            <input type="hidden" name="coupon_id" value="<?= htmlspecialchars($coupon['id']) ?>">
            <input type="text" name="newCode" value="<?= htmlspecialchars($coupon['code']) ?>" placeholder="New Code">
            <input type="number" name="newDiscount" value="<?= htmlspecialchars($coupon['discount']) ?>" placeholder="New Discount">
            <input type="number" name="newUsageLimit" value="<?= htmlspecialchars($coupon['usage_limit']) ?>" placeholder="New Usage Limit">
            <input type="datetime-local" name="newExpireDate" value="<?= date('Y-m-d\TH:i', strtotime($coupon['expire_date'])) ?>">
            <input class="blue-button" type="submit" name="updateCoupon" value="Update">
        </form>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
</table>

</body>
</html>

<?php
include("footer.html");
?>

