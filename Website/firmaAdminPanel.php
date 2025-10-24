<?php

if(session_status() === PHP_SESSION_NONE){
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

include("header.php");

$message = "";       
$message_type = ""; 

//checking if an admin is viewing the page
if(!isset($_SESSION["role"]) || $_SESSION["role"] !== "company"){

    header("Location: index.php");
    exit;

}

function getCompanyId(){//getting firma admin's company id by email

    global $database;

    $email = $_SESSION["email"];

    if(!$email){
        return null;
    }

    $stmt = $database->prepare("SELECT company_id FROM User WHERE email = :email");
    $stmt->bindValue(":email", $email, SQLITE3_TEXT);

    $result = $stmt->execute();

    $user = $result->fetchArray(SQLITE3_ASSOC);

    return $user["company_id"] ?? null;

}

function getCompany(){ //getting firma admin's company informations

    global $database;

    $companyId = getCompanyId();

    if(!$companyId){
        return null;
    }

     //getting company informations
     $companyStmt = $database->prepare("SELECT * FROM Bus_Company WHERE id = :id");
     $companyStmt->bindValue(":id", $companyId, SQLITE3_TEXT);

     $companyResult = $companyStmt->execute();

     $company = $companyResult->fetchArray(SQLITE3_ASSOC);

     return $company ?: null; //return all rows

}


function getTrips() {

    global $database;

    $companyId = getCompanyId();

    if(!$companyId){
        return [];
    }

    //get trips that belongs to this company
    $tripStmt = $database->prepare("SELECT id, departure_city, destination_city, departure_time, arrival_time, price, capacity 
                                    FROM Trips WHERE company_id = :id");
    $tripStmt->bindValue(":id", $companyId, SQLITE3_TEXT);

    $tripResult = $tripStmt->execute();

    $trips = [];
    while ($row = $tripResult->fetchArray(SQLITE3_ASSOC)) {
        $trips[] = $row;
    }

    return $trips;
    
}

function getCoupon(){

    global $database;

    $companyId = getCompanyId();

    if(!$companyId){
        return [];
    }

    $stmt = $database->prepare("SELECT id, code, discount, usage_limit, expire_date FROM Coupons WHERE company_id = :id");
    $stmt->bindValue(":id", $companyId, SQLITE3_TEXT);

    $result = $stmt->execute();

    $coupons = [];

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $coupons[] = $row;
    }

    return $coupons;

}

$company = getCompany();
$trips = getTrips();
$coupons = getCoupon();

if($company){

   $companyName = htmlspecialchars($company["name"]);
   $companyLogo = htmlspecialchars($company["logo_path"] ?? '', ENT_QUOTES, "UTF-8");

   echo "<h1>{$companyName} Control Panel</h1>";
   echo "<img src='{$companyLogo}' alt='Company Logo' width='150'>";

} 

else{

    $message = "No company found for this admin!";
    $message_type = "error";

}


//ADDING NEW TRIP-----------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["addTrip"])){

    //filtering for XSS
    $departureCity = htmlspecialchars($_POST["departureCity"] ?? '');
    $destinationCity = htmlspecialchars($_POST["destinationCity"] ?? '');
    $departureTimeInput = $_POST["departureTime"] ?? '';
    $arrivalTimeInput = $_POST["arrivalTime"] ?? '';
    $price = filter_var($_POST["price"], FILTER_VALIDATE_INT);
    $capacity = filter_var($_POST["capacity"], FILTER_VALIDATE_INT);

        //converting expire date to correct format (Y-m-d H:i:s)
        $departureTime = !empty($departureTimeInput) ? date('Y-m-d H:i:s', strtotime($departureTimeInput)) : false;
        $arrivalTime = !empty($arrivalTimeInput) ? date('Y-m-d H:i:s', strtotime($arrivalTimeInput)) : false;

       //validating inputs
       if($price === false || $capacity === false || $departureTime == false || $arrivalTime == false){

          $message = "Invalid input!";
          $message_type = "error";

       }
      
       //price and capacity cannot be negative or zero
       elseif($price <= 0 || $capacity <= 0){
        
         $message = "Price and capacity must be bigger than zero!";
         $message_type = "error";

       }

       //making sure arrival time is after the departure time
       elseif(strtotime($arrivalTime) <= strtotime($departureTime)){

         $message = "Arrival time cannot be before departure time!";
         $message_type = "error";

        }

        else{
            
        //insert new company to the database
        $stmt = $database->prepare("INSERT INTO Trips(company_id, destination_city, arrival_time, departure_time, departure_city, price, capacity)
                             VALUES(:company_id, :destination_city, :arrival_time, :departure_time, :departure_city, :price, :capacity)");

        $stmt->bindValue(":company_id", $company["id"], SQLITE3_TEXT);
        $stmt->bindValue(":destination_city", $destinationCity, SQLITE3_TEXT);
        $stmt->bindValue(":arrival_time", $arrivalTime, SQLITE3_TEXT);
        $stmt->bindValue(":departure_time", $departureTime, SQLITE3_TEXT);
        $stmt->bindValue(":departure_city", $departureCity, SQLITE3_TEXT);
        $stmt->bindValue(":price", $price, SQLITE3_INTEGER);
        $stmt->bindValue(":capacity", $capacity, SQLITE3_INTEGER);

        $result = $stmt->execute();
        
        //new trip added successfully
        if($result){

            $message = "New Trip created successfully!";
            $message_type = "success";

            header("refresh:2; url=firmaAdminPanel.php");
        
        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

    }

}

//DELETING TRIP-----------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["deleteTrip"])){

        //filtering for XSS
       $id = htmlspecialchars($_POST["trip_id"] ?? '');
        
       //checking if there is any person bought ticket for this tour
       $checkStmt = $database->prepare("SELECT 1 FROM Tickets WHERE trip_id = :trip_id");
       $checkStmt->bindValue(":trip_id", $id, SQLITE3_TEXT);

       $ticketExists = $checkStmt->execute()->fetchArray(SQLITE3_ASSOC);

       if($ticketExists){//prevent deleting if there is any ticket for this tour

          $message = "You cannot delete this tour since there are people registered to this tour!";
          $message_type = "error";

       }
    
        else{

        //deleting the trip
        $deleteStmt = $database->prepare("DELETE FROM Trips WHERE id = :id  AND company_id = :company_id");
        $deleteStmt->bindValue(":id", $id, SQLITE3_TEXT);
        $deleteStmt->bindValue(":company_id", $company["id"], SQLITE3_TEXT);

        $result = $deleteStmt->execute();

        //delete successfull
        if($result){

            $message = "Trip deleted successfully!";
            $message_type = "success";

            header("refresh:2; url=firmaAdminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

    }

}

//UPDATING TRIP-----------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["updateTrip"])){

    //filtering for XSS
    $id = htmlspecialchars($_POST["trip_id"] ?? '');

    $departureCity = htmlspecialchars($_POST["departureCity"] ?? '');
    $destinationCity = htmlspecialchars($_POST["destinationCity"] ?? '');
    $departureTime = date('Y-m-d H:i:s', strtotime($_POST["departureTime"] ?? ''));
    $arrivalTime = date('Y-m-d H:i:s', strtotime($_POST["arrivalTime"] ?? ''));
    $price = filter_var($_POST["price"], FILTER_VALIDATE_INT);
    $capacity = filter_var($_POST["capacity"], FILTER_VALIDATE_INT);

    //validating inputs
    if($price === false || $capacity === false || $departureTime == false || $arrivalTime == false){

        $message = "Invalid input!";
        $message_type = "error";

    }

    //making sure arrival time is after the departure time
    elseif(strtotime($arrivalTime) <= strtotime($departureTime)){

        $message = "Arrival time cannot be before departure time!";
        $message_type = "error";

    }

    else{

        //updating the trip
        $updateStmt = $database->prepare("UPDATE Trips SET departure_city = :departure_city, destination_city = :destination_city,
            departure_time = :departure_time, arrival_time = :arrival_time, price = :price, capacity = :capacity WHERE id = :id
           AND company_id = :company_id");

        $updateStmt->bindValue(":departure_city", $departureCity, SQLITE3_TEXT);
        $updateStmt->bindValue(":destination_city", $destinationCity, SQLITE3_TEXT);
        $updateStmt->bindValue(":departure_time", $departureTime, SQLITE3_TEXT);
        $updateStmt->bindValue(":arrival_time", $arrivalTime, SQLITE3_TEXT);
        $updateStmt->bindValue(":price", $price, SQLITE3_INTEGER);
        $updateStmt->bindValue(":capacity", $capacity, SQLITE3_INTEGER);
        $updateStmt->bindValue(":id", $id, SQLITE3_TEXT);
        $updateStmt->bindValue(":company_id", $company["id"], SQLITE3_TEXT);

        $result = $updateStmt->execute();


        //update successfull
        if($result){

            $message = "Trip updated successfully!";
            $message_type = "success";

            header("refresh:2; url=firmaAdminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

    }

}

//ADDING NEW COUPON-------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["addNewCoupon"])){

    $companyId = getCompanyId();

    if(!$companyId){

    $message = "Company ID not found!";
    $message_type = "error";

    }

    else{
        
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
        $stmt->bindValue(":company_id", $companyId, SQLITE3_TEXT);
        $stmt->bindValue(":usage_limit", $usageLimit, SQLITE3_INTEGER);
        $stmt->bindValue(":expire_date", $expireDate, SQLITE3_TEXT);

        $result = $stmt->execute();
        
        //new coupon added successfully
        if($result){

            $message = "New Coupon created successfully!";
            $message_type = "success";

            header("refresh:2; url=firmaAdminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

    }

  }

}

//DELETING COUPON-----------------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["deleteCoupon"])){

        //filtering for XSS
        $id = htmlspecialchars($_POST["coupon_id"] ?? '');
   
        //deleting the coupon
        $deleteStmt = $database->prepare("DELETE FROM Coupons WHERE id = :id  AND company_id = :company_id");
        $deleteStmt->bindValue(":id", $id, SQLITE3_TEXT);
        $deleteStmt->bindValue(":company_id", $company["id"], SQLITE3_TEXT);

        $result = $deleteStmt->execute();

        //delete successfull
        if($result){

            $message = "Coupon deleted successfully!";
            $message_type = "success";

            header("refresh:2; url=firmaAdminPanel.php");

        } 

        else{

            $message = "An error occurred!";
            $message_type = "error";

        }

}

//UPDATE EXISTING COUPON--------------------------------------------------------------------------------------------------------
if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["updateCoupon"])){

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
    $updateStmt = $database->prepare("UPDATE Coupons SET code = :code, discount = :discount, 
                              usage_limit = :usage_limit, expire_date = :expire_date WHERE id = :id");
    $updateStmt->bindValue(":code", $newCode, SQLITE3_TEXT);
    $updateStmt->bindValue(":discount", $newDiscount, SQLITE3_FLOAT);
    $updateStmt->bindValue(":usage_limit", $newUsageLimit, SQLITE3_INTEGER);
    $updateStmt->bindValue(":expire_date", $newExpireDate, SQLITE3_TEXT);
    $updateStmt->bindValue(":id", $couponId, SQLITE3_TEXT);

    $result = $updateStmt->execute();

    if($result){

        $message = "Coupon updated successfully!";
        $message_type = "success";

        header("refresh:2; url=firmaAdminPanel.php");

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
    <title>Firma Admin Panel</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

   <?php if (!empty($message)): ?>

      <p class="<?php echo $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
        <?php echo htmlspecialchars($message); ?>
      </p>

    <?php endif; ?>

    <div class="container3">
    <h2>Add  New Trips</h2>
    <!--adding new trips-->
    <form action="firmaAdminPanel.php" method="post">

        <input type="text" name="departureCity" placeholder="Departure City" required>

        <input type="text" name="destinationCity" placeholder="Destination City" required>

        <label id="departureTime">Departure Time:</label>
        <input id="departureTime" type="datetime-local" name="departureTime" required>

        <label id="arrivalTime">Arrival Time:</label>
        <input id="arrivalTime" type="datetime-local" name="arrivalTime" required>

        <input type="number" name="price" placeholder="Price" required>

        <input type="number" name="capacity" placeholder="Capacity" required>

        <input class="green-button" type="submit" name="addTrip" value="Add">

    </form>
   </div>

   <table>
  <thead>
    <tr>
      <th>Departure City</th>
      <th>Destination City</th>
      <th>Departure Time</th>
      <th>Arrival Time</th>
      <th>Price</th>
      <th>Capacity</th>
      <th>Delete</th>
      <th colspan="6">Update Trip</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!empty($trips)): ?>
      <?php foreach ($trips as $trip): ?>
      <tr>
        <!--data-->
        <td><?= htmlspecialchars($trip["departure_city"]) ?></td>
        <td><?= htmlspecialchars($trip["destination_city"]) ?></td>
        <td><?= htmlspecialchars($trip["departure_time"]) ?></td>
        <td><?= htmlspecialchars($trip["arrival_time"]) ?></td>
        <td><?= htmlspecialchars($trip["price"]) ?></td>
        <td><?= htmlspecialchars($trip["capacity"]) ?></td>

        <!--delete-->
        <td>
          <form action="firmaAdminPanel.php" method="post" onsubmit="return confirm('Are you sure you want to delete this trip?');">
            <input type="hidden" name="trip_id" value="<?= htmlspecialchars($trip["id"]) ?>">
            <input class="red-button" type="submit" name="deleteTrip" value="Delete">
          </form>
        </td>

        <!--update-->
        <td colspan="6">
          <form action="firmaAdminPanel.php" method="post" class="update-form">

            <input type="hidden" name="trip_id" value="<?= htmlspecialchars($trip["id"]) ?>">

            <input type="text" name="departureCity" value="<?= htmlspecialchars($trip["departure_city"]) ?>" required>
            <input type="text" name="destinationCity" value="<?= htmlspecialchars($trip["destination_city"]) ?>" required>
            <input type="datetime-local" name="departureTime" value="<?= date('Y-m-d\TH:i', strtotime($trip["departure_time"])) ?>" required>
            <input type="datetime-local" name="arrivalTime" value="<?= date('Y-m-d\TH:i', strtotime($trip["arrival_time"])) ?>" required>
            <input type="number" name="price" value="<?= htmlspecialchars($trip["price"]) ?>" required>
            <input type="number" name="capacity" value="<?= htmlspecialchars($trip["capacity"]) ?>" required>

            <input class="blue-button" type="submit" name="updateTrip" value="Update">
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php else: ?>
      <tr><td colspan="13" style="text-align:center;">No trips found.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

    <div class="container3">
    <h2>Add Coupons</h2>
    <!--adding new coupon-->
    <form action="firmaAdminPanel.php" method="post">

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
     <?php if (!empty($coupons)): ?>
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
      <?php else: ?>
        <?php ?>
        <tr><td colspan="6" style="text-align:center;">No coupons found.</td></tr>
    <?php endif; ?>
</tbody>
</table>

    
</body>
</html>

<?php
include("footer.html");
?>
