<?php
/**
 * Script para gerar SHOW CREATE TABLE das tabelas modificadas
 */

require_once 'db.php';

echo "📋 Gerando SHOW CREATE TABLE...\n";

$output = [];
$output[] = "=== SHOW CREATE TABLE - PÓS MIGRAÇÃO ===";
$output[] = "Gerado em: " . date('Y-m-d H:i:s');
$output[] = "";

$tabelas = [
    'usuarios',
    'transacoes_pix',
    'comissoes',
    'affiliate_clicks',
    'affiliate_attributions',
    'payment_callbacks',
    'affiliate_config'
];

foreach ($tabelas as $tabela) {
    try {
        $stmt = $pdo->query("SHOW CREATE TABLE `{$tabela}`");
        $result = $stmt->fetch();
        
        if ($result) {
            $output[] = "-- =====================================================";
            $output[] = "-- TABELA: {$tabela}";
            $output[] = "-- =====================================================";
            $output[] = $result['Create Table'];
            $output[] = "";
            
            echo "  ✅ {$tabela}\n";
        }
    } catch (PDOException $e) {
        $output[] = "-- ERRO na tabela {$tabela}: " . $e->getMessage();
        $output[] = "";
        echo "  ❌ {$tabela}: {$e->getMessage()}\n";
    }
}

// Verificar views
try {
    $stmt = $pdo->query("SHOW CREATE VIEW view_affiliate_report");
    $result = $stmt->fetch();
    
    if ($result) {
        $output[] = "-- =====================================================";
        $output[] = "-- VIEW: view_affiliate_report";
        $output[] = "-- =====================================================";
        $output[] = $result['Create View'];
        $output[] = "";
        
        echo "  ✅ view_affiliate_report\n";
    }
} catch (PDOException $e) {
    $output[] = "-- ERRO na view view_affiliate_report: " . $e->getMessage();
    echo "  ❌ view_affiliate_report: {$e->getMessage()}\n";
}

$outputPath = "backups/post_merge_show_create_" . date('Y-m-d_H-i-s') . ".txt";
file_put_contents($outputPath, implode("\n", $output));

echo "\n💾 SHOW CREATE TABLE salvo em: {$outputPath}\n";
echo "✅ Geração concluída!\n";
?>