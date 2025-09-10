<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $stats = [
        'online' => $pdo->query("SELECT COUNT(DISTINCT usuario_id) FROM historico_jogos WHERE data_jogo >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn(),
        'depositos_pendentes' => $pdo->query("SELECT COUNT(*) FROM transacoes_pix WHERE status = 'pendente'")->fetchColumn(),
        'saques_pendentes' => $pdo->query("SELECT COUNT(*) FROM saques WHERE status = 'pendente'")->fetchColumn(),
        'comissoes_pendentes' => $pdo->query("SELECT COALESCE(SUM(valor_comissao), 0) FROM comissoes WHERE status = 'pendente'")->fetchColumn()
    ];
    
    echo json_encode($stats);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar estatísticas do header: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>