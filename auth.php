<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin() {
    if (empty($_SESSION["logged_in"])) {
        header("Location: index.php");
        exit;
    }
}
?>