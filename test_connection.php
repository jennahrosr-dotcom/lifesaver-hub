<?php
include 'db_connection.php'; // Include your connection file

$sql = "SELECT * FROM student LIMIT 5";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        print_r($row);
        echo "<br>";
    }
} else {
    echo "No results found";
}
$conn->close();
?>