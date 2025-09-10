<?php
header('Content-Type: application/json');
require 'db.php';

try {
    // Buscar cores do banco
    $stmt = $pdo->query("SELECT * FROM cores_site ORDER BY id DESC LIMIT 1");
    $cores = $stmt->fetch();
    
    if (!$cores) {
        // Cores padrão se não existir no banco
        $cores = [
            'cor_primaria' => '#fbce00',
            'cor_secundaria' => '#f4c430',
            'cor_azul' => '#007fdb',
            'cor_verde' => '#00e880',
            'cor_fundo' => '#0a0b0f',
            'cor_painel' => '#111318'
        ];
    }
    
    echo json_encode(['success' => true, 'cores' => $cores]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>