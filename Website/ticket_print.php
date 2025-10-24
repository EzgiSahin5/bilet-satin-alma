<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . "/databaseConnection.php");

if(!isset($_SESSION["email"]) || ($_SESSION["role"] ?? '') === "admin"){
    echo "Access Denied.";
    exit;
}

$ticketId = htmlspecialchars($_GET["ticket_id"] ?? '', ENT_QUOTES, 'UTF-8');
if (!$ticketId) {
    echo "Ticket ID missing.";
    exit;
}

$email = $_SESSION["email"];
$userStmt = $database->prepare("SELECT id FROM User WHERE email = :email");
$userStmt->bindValue(":email", $email, SQLITE3_TEXT);
$userResult = $userStmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$userResult) {
    echo "User not found."; 
    exit;
}

$userId = $userResult['id'];


$stmt = $database->prepare("SELECT name, departure_city, destination_city, departure_time, arrival_time, seat_number,
                                   total_price, created_at, status
                            FROM TICKETS_V
                            WHERE ticket_id = :ticket_id AND user_id = :user_id");
$stmt->bindValue(":ticket_id", $ticketId, SQLITE3_TEXT);
$stmt->bindValue(":user_id", $userId, SQLITE3_TEXT);
$ticket = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if(!$ticket){
    echo "Ticket not found or you don't have permission to view it.";
    exit;
}


 if($ticket['status'] === 'cancelled' || $ticket['status'] === 'expired'){
    echo "This ticket cannot be printed as it is " . $ticket['status'] . ".";
    exit;
 }

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Details - <?= htmlspecialchars($ticketId) ?></title>
    <style>
        body {
            font-family: sans-serif;
            line-height: 1.6;
            margin: 20px;
        }
        .ticket-container {
            border: 1px solid #ccc;
            padding: 20px;
            max-width: 600px;
            margin: auto;
            background-color: #f9f9f9;
        }
        h1 {
            text-align: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        p {
            margin: 10px 0;
        }
        strong {
            display: inline-block;
            width: 150px; 
        }
        .print-button {
             display: block;
             margin: 20px auto;
             padding: 10px 20px;
             cursor: pointer;
        }

      
        @media print {
            .print-button {
                display: none;
            }
            body {
                 margin: 0; 
            }
           .ticket-container {
                 border: none;
                 box-shadow: none;
                 max-width: 100%;
                 background-color: white;
            }
        }
    </style>
</head>
<body>

<div class="ticket-container">
    <h1>Ticket Details</h1>

    <?php foreach ($ticket as $key => $value): ?>
        <p>
            <strong><?= htmlspecialchars(ucfirst(str_replace("_", " ", $key))) ?>:</strong>
            <?= htmlspecialchars($value ?? 'N/A') ?>
        </p>
    <?php endforeach; ?>

     <button class="print-button" onclick="window.print();">Print Ticket</button>

</div>

<script>

    window.onload = function() {
        window.print();
    };
</script>

</body>
</html>