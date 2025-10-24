<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . "/databaseConnection.php");
   
function getTrips(){ //get trips that meets the requirement

    global $database;

    $departureCity_raw = htmlspecialchars($_POST["departureCity"] ?? '');
    $destinationCity_raw = htmlspecialchars($_POST["destinationCity"] ?? '');

    if(!$departureCity_raw || !$destinationCity_raw){
        return [];
    }

    
    $turkish = ['ı', 'İ', 'ö', 'Ö', 'ü', 'Ü', 'ş', 'Ş', 'ç', 'Ç', 'ğ', 'Ğ'];
    $ascii   = ['i', 'i', 'o', 'o', 'u', 'u', 's', 's', 'c', 'c', 'g', 'g'];

    //replace turkish letters
    $departureCity = str_replace($turkish, $ascii, $departureCity_raw);
    $destinationCity = str_replace($turkish, $ascii, $destinationCity_raw);

    //turn every letter to lowercase
    $departureCity = strtolower($departureCity);
    $destinationCity = strtolower($destinationCity);

    $currentTime = date("Y-m-d H:i:s");//get current time

    $sql = "SELECT t.*, c.name as company_name FROM Trips t, Bus_Company c WHERE c.id = t.company_id 
                        AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE
                        (REPLACE(destination_city,'ı', 'i'), 'İ', 'i'), 'ö', 'o'), 'Ö', 'o'), 'ü', 'u'), 'Ü', 'u'), 'ş', 's'),
                         'Ş', 's'), 'ç', 'c'), 'Ç', 'c'), 'ğ', 'g'), 'Ğ', 'g')) = :destination_city
                        AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE
                        (REPLACE(departure_city, 'ı', 'i'), 'İ', 'i'), 'ö', 'o'), 'Ö', 'o'), 'ü', 'u'), 'Ü', 'u'), 'ş', 's'),
                         'Ş', 's'), 'ç', 'c'), 'Ç', 'c'), 'ğ', 'g'), 'Ğ', 'g')) = :departure_city AND t.departure_time >= :current_time";
                        
    $tripStmt = $database->prepare($sql);
    $tripStmt->bindValue(":destination_city", $destinationCity, SQLITE3_TEXT);
    $tripStmt->bindValue(":departure_city", $departureCity, SQLITE3_TEXT);
    $tripStmt->bindValue(":current_time", $currentTime, SQLITE3_TEXT);

    $tripResult = $tripStmt->execute();

    $trips = [];

    while ($row = $tripResult->fetchArray(SQLITE3_ASSOC)) {
        $trips[] = $row;
    }

    return $trips;

}

$trips = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["search"])) {
    $trips = getTrips();
}


include("header.php");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trips</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>
    

<div class="container2">
    <form action="trips.php" method="post">
        <input type="text" name="departureCity" placeholder="Departure City" required>
        <input type="text" name="destinationCity" placeholder="Destination City" required>
        <input class="green-button" type="submit" name="search" value="Search">
    </form>
</div>

    <?php if(!empty($trips)): ?>
        <h2>Available Trips</h2>
        <table>
            <thead>
                <tr>
                    <th>Company</th>
                    <th>Departure City</th>
                    <th>Destination City</th>
                    <th>Departure Time</th>
                    <th>Arrival Time</th>
                    <th>Price</th>
                    <th>Capacity</th>
                    <th>Buy Ticket</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trips as $trip): ?>
                    <tr>
                        <td><?= htmlspecialchars($trip["company_name"]) ?></td>
                        <td><?= htmlspecialchars($trip["departure_city"]) ?></td>
                        <td><?= htmlspecialchars($trip["destination_city"]) ?></td>
                        <td><?= htmlspecialchars($trip["departure_time"]) ?></td>
                        <td><?= htmlspecialchars($trip["arrival_time"]) ?></td>
                        <td><?= htmlspecialchars($trip["price"]) ?></td>
                        <td><?= htmlspecialchars($trip["capacity"]) ?></td>

                         <td>
                             <form action="buyTicket.php" method="post">
                                  <input type="hidden" name="trip_id" value="<?= htmlspecialchars($trip["id"]) ?>">
                                  <input class="green-button" type="submit" name="buyTicket" value="Buy Ticket">
                             </form>
                         </td>

                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST"): ?>
        <p class = "error-message">No trips found for the selected cities.</p>
    <?php endif; ?>

</body>
</html>

<?php
include("footer.html");
?>