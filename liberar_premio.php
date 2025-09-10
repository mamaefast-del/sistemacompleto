<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['raspadinha_resultado'])) {
    http_response_code(403);
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

$dados = $_SESSION['raspadinha_resultado'];
if ($dados['liberado']) {
    echo json_encode(['status' => 'já liberado']);
    exit;
}

$premio = floatval($dados['premio_valor']);
$usuarioId = $_SESSION['usuario_id'];

if ($premio > 0) {
    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo + ? WHERE id = ?");
    $stmt->execute([$premio, $usuarioId]);
}

// Marcar como liberado
$_SESSION['raspadinha_resultado']['liberado'] = true;

echo json_encode([
    'status' => 'ok',
    'novo_saldo' => $premio
]);
