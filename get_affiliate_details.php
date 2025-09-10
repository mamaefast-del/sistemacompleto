<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$affiliate_id = intval($_GET['id'] ?? 0);

if ($affiliate_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

try {
    // Buscar dados detalhados do afiliado
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.email,
            u.nome,
            u.telefone,
            u.codigo_afiliado,
            u.porcentagem_afiliado,
            u.afiliado_ativo,
            u.saldo_comissao,
            u.comissao,
            u.data_cadastro,
            COUNT(DISTINCT r.id) as total_referidos,
            COALESCE(SUM(CASE WHEN t.status = 'aprovado' THEN t.valor ELSE 0 END), 0) as volume_total,
            COALESCE(SUM(c.valor_comissao), 0) as comissoes_geradas,
            COALESCE(SUM(CASE WHEN s.status = 'aprovado' AND s.tipo = 'comissao' THEN s.valor ELSE 0 END), 0) as saques_aprovados
        FROM usuarios u
        LEFT JOIN usuarios r ON r.codigo_afiliado_usado = u.codigo_afiliado
        LEFT JOIN transacoes_pix t ON t.usuario_id = r.id
        LEFT JOIN comissoes c ON c.afiliado_id = u.id
        LEFT JOIN saques s ON s.usuario_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    
    $stmt->execute([$affiliate_id]);
    $affiliate = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$affiliate) {
        echo json_encode(['success' => false, 'error' => 'Affiliate not found']);
        exit;
    }
    
    // Buscar últimos referidos
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.email,
            r.nome,
            r.data_cadastro,
            COALESCE(SUM(CASE WHEN t.status = 'aprovado' THEN t.valor ELSE 0 END), 0) as volume_gerado
        FROM usuarios r
        LEFT JOIN transacoes_pix t ON t.usuario_id = r.id
        WHERE r.codigo_afiliado_usado = ?
        GROUP BY r.id
        ORDER BY r.data_cadastro DESC
        LIMIT 10
    ");
    
    $stmt->execute([$affiliate['codigo_afiliado']]);
    $recent_referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar histórico de comissões
    $stmt = $pdo->prepare("
        SELECT 
            c.*,
            u.nome as indicado_nome,
            u.email as indicado_email
        FROM comissoes c
        LEFT JOIN usuarios u ON c.usuario_indicado_id = u.id
        WHERE c.afiliado_id = ?
        ORDER BY c.data_criacao DESC
        LIMIT 20
    ");
    
    $stmt->execute([$affiliate_id]);
    $commission_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $affiliate['recent_referrals'] = $recent_referrals;
    $affiliate['commission_history'] = $commission_history;
    
    echo json_encode([
        'success' => true,
        'affiliate' => $affiliate
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>