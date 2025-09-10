<?php
/**
 * Script Principal de Migração
 * Executa todo o processo de fusão de esquemas
 */

echo "🚀 INICIANDO MIGRAÇÃO DO SISTEMA DE AFILIADOS\n";
echo "===============================================\n\n";

// Verificar se os scripts existem
$scripts = [
    'scripts/create_backups.php',
    'scripts/merge_schema.php', 
    'scripts/post_merge_validation.php',
    'scripts/show_create_tables.php'
];

foreach ($scripts as $script) {
    if (!file_exists($script)) {
        echo "❌ Script não encontrado: {$script}\n";
        exit(1);
    }
}

echo "📋 Todos os scripts encontrados. Iniciando processo...\n\n";

try {
    // Passo 1: Criar backups
    echo "=== PASSO 1: CRIANDO BACKUPS ===\n";
    include 'scripts/create_backups.php';
    echo "\n";
    
    // Passo 2: Aplicar migração
    echo "=== PASSO 2: APLICANDO MIGRAÇÃO ===\n";
    include 'scripts/merge_schema.php';
    echo "\n";
    
    // Passo 3: Validar resultado
    echo "=== PASSO 3: VALIDANDO RESULTADO ===\n";
    include 'scripts/post_merge_validation.php';
    echo "\n";
    
    // Passo 4: Gerar SHOW CREATE TABLE
    echo "=== PASSO 4: GERANDO DOCUMENTAÇÃO ===\n";
    include 'scripts/show_create_tables.php';
    echo "\n";
    
    echo "🎉 MIGRAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo "===============================================\n";
    echo "📁 Arquivos gerados:\n";
    echo "  - backups/backup_pre_merge_*.sql (backup completo)\n";
    echo "  - backups/backup_structure_pre_merge_*.sql (estrutura)\n";
    echo "  - bd/_merged_apply_*.sql (script aplicado)\n";
    echo "  - backups/merge_report_*.txt (relatório)\n";
    echo "  - backups/post_merge_show_create_*.txt (estruturas)\n\n";
    
    echo "🔧 Próximos passos:\n";
    echo "  1. Verificar os relatórios em backups/\n";
    echo "  2. Testar o sistema com test_affiliate_system.php\n";
    echo "  3. Configurar webhook ExpfyPay para /webhook_expfypay.php\n";
    echo "  4. Acessar relatórios em admin_affiliate_reports.php\n\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO DURANTE A MIGRAÇÃO: " . $e->getMessage() . "\n";
    echo "🔄 Verifique os logs e considere restaurar o backup se necessário.\n";
    exit(1);
}
?>