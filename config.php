<?php
$host = "0.0.0.0";
$user = "root";
$password = "root";
$db = "rizkidfix";

$conn = mysqli_connect($host, $user, $password, $db);

if (!$conn) {
  die("<script>alert('Koneksi database gagal!');</script>");
}
?>