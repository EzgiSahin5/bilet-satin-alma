<?php

if(session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

if(!isset($_SESSION["email"])){

    header("Location: login.php");
    exit;

}

if($_SESSION["role"] === "company" || $_SESSION["role"] === "admin"){

    header("Location: index.php");
    exit;

}

$message = "";       
$message_type = ""; 

if (isset($_SESSION["message"])) {

    $message = $_SESSION["message"];
    $message_type = $_SESSION["message_type"];
    
    unset($_SESSION["message"]);
    unset($_SESSION["message_type"]);

}

//getting trip id
$tripId = $_POST["trip_id"] ?? $_GET["trip_id"] ?? '';

if(!$tripId){

    echo "<p style='color:red;'>No trip selected!</p>";
    header("refresh:2; url=trips.php");
    exit;

}

//getting user information
$email = $_SESSION["email"];
$stmt = $database->prepare("SELECT * FROM User WHERE email = :email");
$stmt->bindValue(":email", $email, SQLITE3_TEXT);

$user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if(!$user){

    echo "<p style='color:red;'>User not found!</p>";
    header("refresh:2; url=trips.php");
    exit;

}

//getting trip information
$stmtTrip = $database->prepare("SELECT t.*, c.id as company_id, c.name as company_name FROM Trips t, Bus_Company c WHERE t.company_id = c.id AND t.id = :trip_id");
$stmtTrip->bindValue(":trip_id", $tripId, SQLITE3_TEXT);

$trip = $stmtTrip->execute()->fetchArray(SQLITE3_ASSOC);

if(!$trip){

    echo "<p style='color:red;'>Trip not found!</p>";
    header("refresh:2; url=trips.php");
    exit;

}

//getting trip's capacity
function getCapacity($tripId){

    global $database;

    $stmtCapacity = $database->prepare("SELECT capacity FROM Trips WHERE id = :trip_id");
    $stmtCapacity->bindValue(":trip_id", $tripId, SQLITE3_TEXT);

    $result = $stmtCapacity->execute()->fetchArray(SQLITE3_ASSOC);

    return $result ? (int)$result["capacity"] : 0;

}

//getting all empty seats
function getEmptySeats($tripId, $capacity){

    global $database;

    //getting booked seats
    $stmtSeat = $database->prepare("SELECT s.seat_number FROM Booked_Seats s, Tickets t WHERE s.ticket_id = t.id AND t.trip_id = :trip_id");
    $stmtSeat->bindValue(":trip_id", $tripId, SQLITE3_TEXT);

    $result = $stmtSeat->execute();

    $bookedSeats = [];

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $bookedSeats[] = (int)$row["seat_number"];
    }

    $emptySeats = [];

    for($i = 1; $i <= $capacity; $i++){
        if(!in_array($i, $bookedSeats)){
            $emptySeats[] = $i;
        }
    }

    return $emptySeats;

}

function getCoupon(){

    global $database;
    global $trip;

    $companyId = $trip["company_id"] ?? null;

    if (!$companyId) return;

    //getting non-expired coupons
    $stmt = $database->prepare("SELECT * FROM Coupons WHERE (company_id IS NULL OR company_id = :id) AND expire_date >= datetime('now')");
    $stmt->bindValue(":id", $companyId, SQLITE3_TEXT);

    $result = $stmt->execute();

    //getting non-expired coupon's data
    while($couponRow = $result->fetchArray(SQLITE3_ASSOC)){

        $couponId = htmlspecialchars($couponRow["id"]);
        $code = htmlspecialchars($couponRow["code"]);
        $discount = htmlspecialchars($couponRow["discount"]);
        $usageLimit = htmlspecialchars($couponRow["usage_limit"]);
        $expire_date = htmlspecialchars($couponRow["expire_date"]);

        echo '<option value="' . $couponId . '" data-code="' . $code . '" data-discount="' . $discount . '" data-usagelimit="' . $usageLimit . '" 
            data-expire="' . $expire_date . '">' . $code . ' (' . $discount . '% discount)</option>';

    }

}

$capacity = getCapacity($tripId);
$emptySeats = getEmptySeats($tripId, $capacity);

//BUY TICKET-----------------------------------------------------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["buyTicketHere"])) {

    //filtering
    $seatNumber = htmlspecialchars($_POST["seat_number"] ?? '');
    $couponId = htmlspecialchars($_POST["select_coupon"] ?? '');
    $userId = $user["id"];
    $price = (int)$trip["price"];
    $balance = (int)$user["balance"];
    $currentDate = date("Y-m-d H:i:s");
    $finalPrice = $price; 
    $couponResult = null; 

    //seat number cannot be empty
    if(empty($seatNumber)){

        $_SESSION["message"] = "Please select a seat!"; 
        $_SESSION["message_type"] = "error"; 

        header("Location: buyTicket.php?trip_id=" . $tripId); 
        exit; 

    }

    //if a coupon is selected
    if(!empty($couponId)){

        //getting the usage limit
        $checkStmt = $database->prepare("SELECT COUNT(*) as used_count FROM User_Coupons WHERE user_id = :user_id AND coupon_id = :coupon_id");
        $checkStmt->bindValue(":user_id", $userId, SQLITE3_TEXT);
        $checkStmt->bindValue(":coupon_id", $couponId, SQLITE3_TEXT);

        $usedRow = $checkStmt->execute()->fetchArray(SQLITE3_ASSOC);

        $usedCount = (int)$usedRow["used_count"];

        //check if the coupon is valid and not expired
        $couponStmt = $database->prepare("SELECT * FROM Coupons WHERE id = :id AND (company_id IS NULL OR company_id = :company_id) AND expire_date >= :current_date");
        $couponStmt->bindValue(":id", $couponId, SQLITE3_TEXT);
        $couponStmt->bindValue(":company_id", $trip["company_id"], SQLITE3_TEXT);
        $couponStmt->bindValue(":current_date", $currentDate, SQLITE3_TEXT);

        $couponResult = $couponStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if(!$couponResult){

            $_SESSION["message"] = "Invalid or expired coupon!"; 
            $_SESSION["message_type"] = "error"; 

            header("Location: buyTicket.php?trip_id=" . $tripId); 
            exit; 

        }

        $usageLimit = (int)$couponResult["usage_limit"];
        $discount = (int)$couponResult["discount"];

        if($usedCount >= $usageLimit){

            $_SESSION["message"] = "You have already reached the usage limit for this coupon!";
            $_SESSION["message_type"] = "error";

            header("Location: buyTicket.php?trip_id=" . $tripId); 
            exit; 

        }

        //apply coupon discount
        $finalPrice = $price - ($price * ($discount / 100));

    }

    
    $finalBalance = $balance - $finalPrice;

    if($finalBalance < 0){//if balance is not enough

        $_SESSION["message"] = "Insufficient balance!";
        $_SESSION["message_type"] = "error"; 

        header("Location: buyTicket.php?trip_id=" . $tripId); 
        exit;

    }

    try{
       
        //begin transaction
        $database->exec('BEGIN TRANSACTION');

        //checking if the seat is booked
        $checkStmt = $database->prepare("SELECT 1 FROM Booked_Seats s, Tickets t WHERE s.ticket_id = t.id AND t.trip_id = :trip_id AND seat_number = :seat_number");
        $checkStmt->bindValue(":trip_id", $tripId, SQLITE3_TEXT);
        $checkStmt->bindValue(":seat_number", $seatNumber, SQLITE3_INTEGER);

        if ($checkStmt->execute()->fetchArray()) {
            throw new Exception("This seat is already booked!");
        }

        //insert into Tickets
        $stmt = $database->prepare("INSERT INTO Tickets(trip_id, user_id, total_price) VALUES(:trip_id, :user_id, :total_price)");
        $stmt->bindValue(":trip_id", $tripId, SQLITE3_TEXT);
        $stmt->bindValue(":user_id", $userId, SQLITE3_TEXT);
        $stmt->bindValue(":total_price", $finalPrice, SQLITE3_INTEGER);

        if (!$stmt->execute()) throw new Exception("Error creating Ticket!");

        //getting ticket id
        $stmtTicketId = $database->prepare("SELECT id FROM Tickets WHERE rowid = last_insert_rowid()");

        $ticketIdRow = $stmtTicketId->execute()->fetchArray(SQLITE3_ASSOC);

        $ticketId = $ticketIdRow["id"];

        //insert into Booked_Seats
        $stmtSeat = $database->prepare("INSERT INTO Booked_Seats(ticket_id, seat_number) VALUES(:ticket_id, :seat_number)");
        $stmtSeat->bindValue(":ticket_id", $ticketId, SQLITE3_TEXT);
        $stmtSeat->bindValue(":seat_number", $seatNumber, SQLITE3_INTEGER);

        if (!$stmtSeat->execute()) throw new Exception("Error booking seat.");

        //upddate user's balance
        $stmtBalance = $database->prepare("UPDATE User SET balance = :finalBalance WHERE id = :user_id");
        $stmtBalance->bindvalue(":finalBalance", $finalBalance, SQLITE3_INTEGER);
        $stmtBalance->bindValue(":user_id", $userId, SQLITE3_TEXT);

        if (!$stmtBalance->execute()) throw new Exception("Error updating balance.");

        //if coupon is used, insert into User_Coupons
        if(!empty($couponId) && $couponResult){

            $insertCouponUse = $database->prepare("INSERT INTO User_Coupons(coupon_id, user_id) VALUES(:coupon_id, :user_id)");
            $insertCouponUse->bindValue(":coupon_id", $couponId, SQLITE3_TEXT);
            $insertCouponUse->bindValue(":user_id", $userId, SQLITE3_TEXT);

            if (!$insertCouponUse->execute()) throw new Exception("Error saving coupon usage.");

        }

        //all successfull
        $database->exec('COMMIT');

        $message = "Ticket bought successfully!"; 
        $message_type = "success"; 

        header("refresh:2; url=profile.php"); 

    } 
    
    catch(Exception $e){

        //reverse all changes
        $database->exec('ROLLBACK');

        $_SESSION["message"] = "An error occurred during purchase: " . $e->getMessage();
        $_SESSION["message_type"] = "error"; 

        header("Location: buyTicket.php?trip_id=" . $tripId); 
        exit;

    }

}

include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buy Ticket</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="content">
    <div class= "container">
    <h2>Buy Tickets</h2>

        <?php if (!empty($message)): ?>

      <p class="<?php echo $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
        <?php echo htmlspecialchars($message); ?>
      </p>

    <?php endif; ?>
    <form method="POST" action="buyTicket.php">
        <?php if ($trip): ?>
            
            <input type="hidden" name="trip_id" value="<?= htmlspecialchars($tripId) ?>">
            <p><strong>Company Name:</strong> <?= htmlspecialchars($trip['company_name']) ?></p>
            <p><strong>Departure City:</strong> <?= htmlspecialchars($trip['departure_city']) ?></p>
            <p><strong>Destination City:</strong> <?= htmlspecialchars($trip['destination_city']) ?></p>
            <p><strong>Departure Time:</strong> <?= htmlspecialchars($trip['departure_time']) ?></p>
            <p><strong>Arrival Time:</strong> <?= htmlspecialchars($trip['arrival_time']) ?></p>
            <p><strong>Price:</strong> <?= htmlspecialchars($trip['price']) ?></p>
            <p><strong>Capacity:</strong> <?= htmlspecialchars($trip['capacity']) ?></p>

            <label for="selectSeat">Select Seat:</label>
            <select id="selectSeat" name="seat_number" required>
                <option value="">--Select Seat--</option>
                <?php foreach ($emptySeats as $seat): ?>
                    <option value="<?= $seat ?>"><?= $seat ?></option>
                <?php endforeach; ?>
            </select>

        <label for="searchCoupon">Select Coupon:</label>
        <select id="searchCoupon" name="select_coupon">
             <option value="">--Select Coupon--</option>
                 <?php getCoupon(); ?>
        </select>

            <input class="green-button" type="submit" name="buyTicketHere" value="Buy Ticket">
        <?php else: ?>
            <p style="color:red; text-align:center;">No trip found.</p>
        <?php endif; ?>
    </form>
        </div>
        </div>
</body>
</html>

<?php
include("footer.html");
?>