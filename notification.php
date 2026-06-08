<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use KsfCommon\Notification\NotificationService;

header('Content-Type: application/json');

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$service = new NotificationService();

if ($action === 'ack') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['id']) || empty($data['ack_token'])) {
        http_response_code(400);
        echo json_encode(['error' => 'id and ack_token are required']);
        exit;
    }

    $ok = $service->acknowledge((int) $data['id'], (string) $data['ack_token']);
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'get') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $notif = $service->getById($id);
    echo json_encode(['notification' => $notif ? $notif->toArray() : null]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unsupported action']);
