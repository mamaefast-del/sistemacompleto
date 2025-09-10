<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    echo json_encode([]);
    exit;
}

try {
    // Buscar usuários que jogaram nos últimos 5 minutos
    $stmt = $pdo->query("
        SELECT DISTINCT u.id, u.nome, u.email, MAX(h.data_jogo) as ultima_atividade
        FROM usuarios u
        JOIN historico_jogos h ON h.usuario_id = u.id
        WHERE h.data_jogo >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        GROUP BY u.id, u.nome, u.email
        ORDER BY ultima_atividade DESC
        LIMIT 20
    ");
    
    $usuarios_online = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($usuarios_online);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar usuários online: " . $e->getMessage());
    echo json_encode([]);
}
?>