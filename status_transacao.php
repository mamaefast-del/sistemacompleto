<?php
require 'db.php';

header('Content-Type: application/json; charset=utf-8');

// Permitir acesso sem sessão para verificação de status
$external_id = $_GET['external_id'] ?? '';
$usuario_id = $_GET['usuario_id'] ?? '';

if (!$external_id || !$usuario_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros ausentes']);
    exit;
}

// Verificar se a transação existe e pertence ao usuário
$stmt = $pdo->prepare("SELECT status FROM transacoes_pix WHERE external_id = ? AND usuario_id = ?");
$stmt->execute([$external_id, $usuario_id]);
$status = $stmt->fetchColumn();

if ($status === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Transação não encontrada']);
    exit;
}

// Buscar saldo do usuário
$stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$saldo = floatval($stmt->fetchColumn());

echo json_encode([
    'status' => $status,
    'saldo' => number_format($saldo, 2, ',', '.')
]);
?>
