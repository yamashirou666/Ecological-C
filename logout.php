<?php
/**
 * EcoCycle — logout.
 */
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed.';
    exit;
}

if (!verifyCsrf()) {
    http_response_code(403);
    echo 'Your session expired. Please try again.';
    exit;
}

logoutUser();
header('Location: index.php');
exit;
