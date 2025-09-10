<?php
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['erro' => 'Usuário não autenticado.']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$valor = isset($_POST['valor']) ? floatval($_POST['valor']) : 0.0;

$sorteio = rand(1, 100);
if ($sorteio <= 20) {
    $premio = $valor * 2;
} elseif ($sorteio <= 50) {
    $premio = $valor;
} else {
    $premio = 0.0;
}

$stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();
if (!$usuario || $usuario['saldo'] < $valor) {
    echo json_encode(['erro' => 'Saldo insuficiente.']);
    exit;
}

$novoSaldo = $usuario['saldo'] - $valor + $premio;
$stmt = $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
$stmt->execute([$novoSaldo, $usuario_id]);

$stmt = $pdo->prepare("INSERT INTO raspadinhas (usuario_id, valor, premio) VALUES (?, ?, ?)");
$stmt->execute([$usuario_id, $valor, $premio]);

echo json_encode([
    'premio' => $premio,
    'novoSaldo' => $novoSaldo
]);
