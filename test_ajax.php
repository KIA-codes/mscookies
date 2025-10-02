<?php
// Simple test to verify AJAX endpoint is working
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'message' => 'AJAX endpoint is working',
    'time' => date('Y-m-d H:i:s'),
    'url' => $_SERVER['REQUEST_URI']
]);
?>
