<?php

try{
    $database = new SQLite3(__DIR__ . "/database.db");
} 

catch(Exception $error){
    die($error->getMessage());
}

?>
