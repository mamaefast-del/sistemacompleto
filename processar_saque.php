<?php
header('Content-Type: application/json');
session_start();
require 'db.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'erro', 'mensagem' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$valor = floatval($_POST['valor'] ?? 0);
$chave_pix = trim($_POST['chave_pix'] ?? '');
$tipo_chave = strtoupper(trim($_POST['tipo_chave'] ?? ''));
$nome = trim($_POST['nome'] ?? '');
$taxId = preg_replace('/\D/', '', trim($_POST['cpf_cnpj'] ?? ''));

// Ajuste tipos de chave para API Ellitium
$tipos_validos = ['CPF', 'CNPJ', 'EMAIL', 'PHONE', 'RANDOM'];
if (!in_array($tipo_chave, $tipos_validos)) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Tipo de chave Pix inválido.']);
    exit;
}

// Buscar limites
$config = $pdo->query("SELECT min_saque, max_saque FROM configuracoes LIMIT 1")->fetch();
$min_saque = floatval($config['min_saque']);
$max_saque = floatval($config['max_saque']);

// Verificar saldo
$stmt = $pdo->prepare("SELECT saldo FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();
$saldo = floatval($usuario['saldo'] ?? 0);

// Verificar rollover
$stmt = $pdo->prepare("SELECT SUM(valor_necessario - valor_acumulado) AS restante FROM rollover WHERE usuario_id = ? AND finalizado = 0");
$stmt->execute([$usuario_id]);
$restante = $stmt->fetchColumn();

if ($restante > 0.01) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Você ainda precisa apostar R$ ' . number_format($restante, 2, ',', '.') . ' para liberar o saque.']);
    exit;
}

if ($valor < $min_saque || $valor > $max_saque) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Valor fora dos limites permitidos.']);
    exit;
}

if ($valor > $saldo) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Saldo insuficiente.']);
    exit;
}

if (empty($chave_pix)) {
    echo json_encode(['status' => 'erro', 'mensagem' => '❌ Chave Pix inválida.']);
    exit;
}

// Gerar external_id único
$external_id = uniqid("saque_user{$usuario_id}_");

// Inserir o saque no banco com external_id
$stmt = $pdo->prepare("INSERT INTO saques (usuario_id, valor, chave_pix, tipo_chave, status, nome, taxId, external_id, data) VALUES (?, ?, ?, ?, 'pendente', ?, ?, ?, NOW())");
$stmt->execute([$usuario_id, $valor, $chave_pix, $tipo_chave, $nome, $taxId, $external_id]);


// Descontar o saldo
$stmt = $pdo->prepare("UPDATE usuarios SET saldo = saldo - ? WHERE id = ?");
$stmt->execute([$valor, $usuario_id]);

echo json_encode(['status' => 'sucesso', 'mensagem' => '✅ Saque solicitado com sucesso!']);
exit;
