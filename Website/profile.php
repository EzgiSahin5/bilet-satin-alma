<?php
if(session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

//checking if user is logged in or admin
if(!isset($_SESSION["email"]) || $_SESSION["role"] === "admin"){

    header("Location: index.php");
    exit;

}

$message = "";       
$message_type = ""; 

function getUser(){//getting user information

    global $database;

    $email = $_SESSION["email"];

    if(!$email){
        return null;
    }

    $stmt = $database->prepare("SELECT * FROM User WHERE email = :email");
    $stmt->bindValue(":email", $email, SQLITE3_TEXT);

    $result = $stmt->execute();

    $user = $result->fetchArray(SQLITE3_ASSOC);

    return $user ?? null;

}

$user = getUser();

function getTickets(){

    global $database;
    global $user;

    $userId = $user["id"];

    if(!$userId){
        return [];
    }

    $stmt = $database->prepare("SELECT * FROM TICKETS_V WHERE user_id = :id");
    $stmt->bindValue(":id", $userId, SQLITE3_TEXT);

    $result = $stmt->execute();

    $tickets = [];

    while($row = $result->fetchArray(SQLITE3_ASSOC)){
        $tickets[] = $row;
    }

    return $tickets;

}

$tickets = getTickets();

//updating expired tickets
$updateExpired = $database->prepare("UPDATE Tickets SET status = 'expired' WHERE trip_id IN 
                                   (SELECT id FROM Trips WHERE departure_time < CURRENT_TIMESTAMP) AND status = 'active' ");

$updateExpired->execute();

//CANCELLING TICKET -----------------------------------------------------------------------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["cancelTicket"])) {

    $userId = $user["id"];
    $ticketId = htmlspecialchars($_POST["ticket_id"] ?? '', ENT_QUOTES, 'UTF-8');

    try {
      
        //begin transaction
        $database->exec('BEGIN TRANSACTION');

        //get active ticket informations
        $stmt = $database->prepare("SELECT total_price, trip_id FROM Tickets WHERE id = :ticket_id AND user_id = :user_id AND status = 'active'");
        $stmt->bindValue(":ticket_id", $ticketId, SQLITE3_TEXT);
        $stmt->bindValue(":user_id", $userId, SQLITE3_TEXT);

        $ticketResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if(!$ticketResult){
            throw new Exception("Ticket not found or inactive!");
        }

        $tripId = $ticketResult['trip_id'];

        //get departure time
        $tripStmt = $database->prepare("SELECT departure_time FROM Trips WHERE id = :trip_id");
        $tripStmt->bindValue(":trip_id", $tripId, SQLITE3_TEXT);

        $tripResult = $tripStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if(!$tripResult){
            throw new Exception("Trip not found!");
        }

        $departureTime = $tripResult["departure_time"];

        $departureTimestamp = strtotime($departureTime); 
        $currentTimestamp = time(); 
        $oneHour = 3600; //one hour in seconds

        if(($departureTimestamp - $currentTimestamp) <= $oneHour){
            throw new Exception("Ticket cannot be cancelled less than 1 hour before departure!");
        }

        $price = (int)$ticketResult["total_price"];
        $newBalance = $user["balance"] + $price;

        //updating user balance
        $updateStmt = $database->prepare("UPDATE User SET balance = :newBalance WHERE id = :id");
        $updateStmt->bindValue(":newBalance", $newBalance, SQLITE3_INTEGER);
        $updateStmt->bindValue(":id", $userId, SQLITE3_TEXT);

        if(!$updateStmt->execute()) throw new Exception("Error updating balance");

        //updating ticket status
        $updateTicket = $database->prepare("UPDATE Tickets SET status = 'cancelled' WHERE id = :id");
        $updateTicket->bindValue(":id", $ticketId, SQLITE3_TEXT);

        if(!$updateTicket->execute()) throw new Exception("Error updating ticket status");

        //unsetting seat
        $unsetSeat = $database->prepare("DELETE FROM Booked_Seats WHERE ticket_id = :ticket_id");
        $unsetSeat->bindValue(":ticket_id", $ticketId, SQLITE3_TEXT);

        if(!$unsetSeat->execute()) throw new Exception("Error unsetting seat!");

        //no problem occurred
        $database->exec('COMMIT');

        $message = "Ticket cancelled successfully!"; 
        $message_type = "success";

        header("refresh:2; url=profile.php"); 

    } 
    
    catch(Exception $e){

        //reverse changes
        $database->exec('ROLLBACK');

        $message = "Error cancelling ticket: " . $e->getMessage();
        $message_type = "error";

    }

}

include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>

<div class="container2">
    <h1>Profile</h1>

    <?php if($user): ?>
        <p><strong>Full Name:</strong> <?= htmlspecialchars($user["full_name"]) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars($user["email"]) ?></p>
        <p><strong>Role:</strong> <?= htmlspecialchars($user["role"]) ?></p>
        <p><strong>Balance:</strong> <?= htmlspecialchars($user["balance"]) ?></p>
        <p><strong>Created At:</strong> <?= htmlspecialchars($user["created_at"]) ?></p>
    <?php else: ?>
        <p style="color:red; text-align:center;">No user found.</p>
    <?php endif; ?>
</div>

    <?php if (!empty($message)): ?>

      <p class="<?php echo $message_type === 'success' ? 'success-message' : 'error-message'; ?>">
        <?php echo htmlspecialchars($message); ?>
      </p>

    <?php endif; ?>

 <table>
    <thead>
        <tr>
            <th>Company</th>
            <th>Departure City</th>
            <th>Destination City</th>
            <th>Departure Time</th>
            <th>Arrival Time</th>
            <th>Seat Number</th>
            <th>Price</th>
            <th>Bought At</th>
            <th>Status</th>
            <th>Cancel</th>
            <th>Download</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($tickets)): ?>
            <?php foreach ($tickets as $ticket): ?>
                <tr class="<?= $ticket['status'] === 'cancelled' || $ticket['status'] === 'expired' ? 'inactive' : 'active' ?>">
                    <td><?= htmlspecialchars($ticket["name"] ?? "—") ?></td>
                    <td><?= htmlspecialchars($ticket["departure_city"]) ?></td>
                    <td><?= htmlspecialchars($ticket["destination_city"]) ?></td>
                    <td><?= htmlspecialchars($ticket["departure_time"]) ?></td>
                    <td><?= htmlspecialchars($ticket["arrival_time"]) ?></td>
                    <td><?= htmlspecialchars($ticket["seat_number"] ?? "—") ?></td>
                    <td><?= htmlspecialchars($ticket["total_price"]) ?></td>
                    <td><?= htmlspecialchars($ticket["created_at"]) ?></td>
                    <td><?= htmlspecialchars($ticket["status"]) ?></td>
                    <td>
                        <?php if ($ticket["status"] == "active"): ?>
                            <form action="profile.php" method="post" onsubmit="return confirm('Are you sure you want to cancel this ticket?');">
                                <input type="hidden" name="ticket_id" value="<?= htmlspecialchars($ticket["ticket_id"]) ?>">
                                <input class="red-button" type="submit" name="cancelTicket" value="Cancel">
                            </form>
                        <?php else: ?>
                            <span style="color: gray;">Cancelled</span>
                        <?php endif; ?>
                    </td>
        <td>
    <?php if ($ticket["status"] == "active"): ?>
        <a href="ticket_print.php?ticket_id=<?= htmlspecialchars($ticket["ticket_id"]) ?>"
           target="_blank"
           class="green-button">
           Download
        </a>
        <?php else: ?>
        <span style="color: gray;">Unavailable</span>
    <?php endif; ?>
</td>
                </tr>
                    <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" style="text-align:center;">No tickets found.</td></tr>
                    <?php endif; ?>
    </tbody>
</table>

</body>
</html>

<?php
include("footer.html");
?>