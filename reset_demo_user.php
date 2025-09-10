<?php
session_start();
require 'db.php';

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    echo 'Acesso negado';
    exit;
}

$user_id = intval($_POST['user_id'] ?? 0);

if ($user_id <= 0) {
    echo 'ID de usuário inválido';
    exit;
}

try {
    // Verificar se é conta demo
    $stmt = $pdo->prepare("SELECT conta_demo FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['conta_demo']) {
        echo 'Usuário não é conta demo';
        exit;
    }
    
    // Buscar configurações de demo
    $config_demo = $pdo->query("SELECT * FROM demo_config LIMIT 1")->fetch();
    $saldo_inicial = floatval($config_demo['saldo_inicial_demo'] ?? 1000);
    
    // Resetar saldo para valor inicial
    $stmt = $pdo->prepare("UPDATE usuarios SET saldo = ? WHERE id = ?");
    $stmt->execute([$saldo_inicial, $user_id]);
    
    // Registrar no log de admin
    $stmt = $pdo->prepare("INSERT INTO log_admin (acao, detalhes, data) VALUES (?, ?, NOW())");
    $stmt->execute(['Reset Demo', "Conta demo ID: $user_id resetada. Saldo restaurado para R$ " . number_format($saldo_inicial, 2, ',', '.')]);
    
    echo 'success';
    
} catch (PDOException $e) {
    error_log("Erro ao resetar conta demo: " . $e->getMessage());
    echo 'Erro interno';
}
?>