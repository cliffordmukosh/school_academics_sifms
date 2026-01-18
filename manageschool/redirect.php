<?php
// redirect.php
function redirectToLogin() {
    // Absolute path from domain root
    header("Location: /schoolacademics/index.php");
    exit;
}
