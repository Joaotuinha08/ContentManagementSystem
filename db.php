<?php
function db_connect() {
    $host = "localhost";
    $port = 3306;
    $username = "root";
    $password = "";
    $dbname = "projeto";

    $conn = new mysqli($host, $username, $password, $dbname, $port);
    if ($conn->connect_error) {
        die("Error: " . $conn->connect_error);
    }
    return $conn;
}
?>