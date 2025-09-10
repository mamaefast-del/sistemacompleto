<?php
/**
 * Script de Validação Pós-Migração
 */

require_once 'db.php';

echo "🔍 Iniciando validação pós-migração...\n";

$report = [];
$report[] = "=== RELATÓRIO DE VALIDAÇÃO PÓS-MIGRAÇÃO ===";
$report[] = "Data: " . date('Y-m-d H:i:s');
$report[] = "";

try {
    // =====================================================
    // 1. VERIFICAR TABELAS CRIADAS
    // =====================================================
    
    echo "📋 Verificando tabelas criadas...\n";
    $report[] = "1. TABELAS VERIFICADAS:";
    
    $tabelasEsperadas = [
        'affiliate_clicks' => 'Rastreamento de cliques',
        'affiliate_attributions' => 'Atribuições de usuários',
        'payment_callbacks' => 'Logs de callbacks',
        'affiliate_config' => 'Configurações do sistema'
    ];
    
    foreach ($tabelasEsperadas as $tabela => $descricao) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$tabela}`");
            $count = $stmt->fetchColumn();
            echo "  ✅ {$tabela}: {$count} registros\n";
            $report[] = "  ✅ {$tabela}: {$count} registros - {$descricao}";
        } catch (PDOException $e) {
            echo "  ❌ {$tabela}: ERRO - {$e->getMessage()}\n";
            $report[] = "  ❌ {$tabela}: ERRO - {$e->getMessage()}";
        }
    }
    
    // =====================================================
    // 2. VERIFICAR COLUNAS ADICIONADAS
    // =====================================================
    
    echo "\n🔧 Verificando colunas adicionadas...\n";
    $report[] = "";
    $report[] = "2. COLUNAS VERIFICADAS:";
    
    $colunasEsperadas = [
        'usuarios' => [
            'attributed_affiliate_id',
            'ref_code_attributed',
            'attributed_at',
            'first_deposit_confirmed',
            'first_deposit_amount',
            'first_deposit_at'
        ],
        'transacoes_pix' => [
            'affiliate_id',
            'ref_code',
            'attributed_at',
            'is_first_deposit'
        ]
    ];
    
    foreach ($colunasEsperadas as $tabela => $colunas) {
        echo "  📊 Tabela {$tabela}:\n";
        $report[] = "  📊 Tabela {$tabela}:";
        
        foreach ($colunas as $coluna) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = ? 
                    AND COLUMN_NAME = ?
                ");
                $stmt->execute([$tabela, $coluna]);
                $existe = $stmt->fetchColumn() > 0;
                
                if ($existe) {
                    echo "    ✅ {$coluna}\n";
                    $report[] = "    ✅ {$coluna}";
                } else {
                    echo "    ❌ {$coluna} - NÃO ENCONTRADA\n";
                    $report[] = "    ❌ {$coluna} - NÃO ENCONTRADA";
                }
            } catch (PDOException $e) {
                echo "    ❌ {$coluna} - ERRO: {$e->getMessage()}\n";
                $report[] = "    ❌ {$coluna} - ERRO: {$e->getMessage()}";
            }
        }
    }
    
    // =====================================================
    // 3. VERIFICAR ÍNDICES
    // =====================================================
    
    echo "\n📊 Verificando índices criados...\n";
    $report[] = "";
    $report[] = "3. ÍNDICES VERIFICADOS:";
    
    $indicesEsperados = [
        'affiliate_clicks' => ['idx_affiliate_id', 'idx_ref_code', 'idx_created_at'],
        'affiliate_attributions' => ['unique_user_attribution', 'idx_affiliate_id', 'idx_ref_code'],
        'payment_callbacks' => ['unique_transaction', 'idx_external_id', 'idx_user_id'],
        'usuarios' => ['idx_attributed_affiliate', 'idx_ref_code_attributed'],
        'transacoes_pix' => ['idx_affiliate_id', 'idx_ref_code']
    ];
    
    foreach ($indicesEsperados as $tabela => $indices) {
        echo "  📊 Tabela {$tabela}:\n";
        $report[] = "  📊 Tabela {$tabela}:";
        
        foreach ($indices as $indice) {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM INFORMATION_SCHEMA.STATISTICS 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = ? 
                    AND INDEX_NAME = ?
                ");
                $stmt->execute([$tabela, $indice]);
                $existe = $stmt->fetchColumn() > 0;
                
                if ($existe) {
                    echo "    ✅ {$indice}\n";
                    $report[] = "    ✅ {$indice}";
                } else {
                    echo "    ❌ {$indice} - NÃO ENCONTRADO\n";
                    $report[] = "    ❌ {$indice} - NÃO ENCONTRADO";
                }
            } catch (PDOException $e) {
                echo "    ❌ {$indice} - ERRO: {$e->getMessage()}\n";
                $report[] = "    ❌ {$indice} - ERRO: {$e->getMessage()}";
            }
        }
    }
    
    // =====================================================
    // 4. TESTE DE INSERÇÃO
    // =====================================================
    
    echo "\n🧪 Testando inserções nas novas tabelas...\n";
    $report[] = "";
    $report[] = "4. TESTES DE INSERÇÃO:";
    
    try {
        // Teste affiliate_clicks
        $stmt = $pdo->prepare("
            INSERT INTO affiliate_clicks (affiliate_id, ref_code, url, utm_source, ip_address, session_id) 
            VALUES (NULL, 'TEST001', '/test', 'test', '127.0.0.1', 'test_session')
        ");
        $stmt->execute();
        $clickId = $pdo->lastInsertId();
        
        // Remover teste
        $pdo->prepare("DELETE FROM affiliate_clicks WHERE id = ?")->execute([$clickId]);
        
        echo "  ✅ affiliate_clicks: INSERT/DELETE funcionando\n";
        $report[] = "  ✅ affiliate_clicks: INSERT/DELETE funcionando";
        
    } catch (PDOException $e) {
        echo "  ❌ affiliate_clicks: ERRO - {$e->getMessage()}\n";
        $report[] = "  ❌ affiliate_clicks: ERRO - {$e->getMessage()}";
    }
    
    try {
        // Teste affiliate_config
        $stmt = $pdo->prepare("
            INSERT INTO affiliate_config (config_key, config_value, description) 
            VALUES ('TEST_KEY', 'test_value', 'Teste de inserção')
            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
        ");
        $stmt->execute();
        
        // Remover teste
        $pdo->prepare("DELETE FROM affiliate_config WHERE config_key = 'TEST_KEY'")->execute();
        
        echo "  ✅ affiliate_config: INSERT/DELETE funcionando\n";
        $report[] = "  ✅ affiliate_config: INSERT/DELETE funcionando";
        
    } catch (PDOException $e) {
        echo "  ❌ affiliate_config: ERRO - {$e->getMessage()}\n";
        $report[] = "  ❌ affiliate_config: ERRO - {$e->getMessage()}";
    }
    
    // =====================================================
    // 5. VERIFICAR VIEWS
    // =====================================================
    
    echo "\n👁️ Verificando views criadas...\n";
    $report[] = "";
    $report[] = "5. VIEWS VERIFICADAS:";
    
    try {
        $stmt = $pdo->query("SELECT * FROM view_affiliate_report LIMIT 1");
        $result = $stmt->fetch();
        echo "  ✅ view_affiliate_report: Funcionando\n";
        $report[] = "  ✅ view_affiliate_report: Funcionando";
    } catch (PDOException $e) {
        echo "  ❌ view_affiliate_report: ERRO - {$e->getMessage()}\n";
        $report[] = "  ❌ view_affiliate_report: ERRO - {$e->getMessage()}";
    }
    
    // =====================================================
    // 6. ESTATÍSTICAS FINAIS
    // =====================================================
    
    echo "\n📈 Coletando estatísticas finais...\n";
    $report[] = "";
    $report[] = "6. ESTATÍSTICAS FINAIS:";
    
    $stats = [
        'Total de usuários' => "SELECT COUNT(*) FROM usuarios",
        'Usuários com atribuição' => "SELECT COUNT(*) FROM usuarios WHERE attributed_affiliate_id IS NOT NULL",
        'Afiliados ativos' => "SELECT COUNT(*) FROM usuarios WHERE afiliado_ativo = 1",
        'Primeiros depósitos marcados' => "SELECT COUNT(*) FROM transacoes_pix WHERE is_first_deposit = 1",
        'Configurações de afiliados' => "SELECT COUNT(*) FROM affiliate_config"
    ];
    
    foreach ($stats as $label => $query) {
        try {
            $stmt = $pdo->query($query);
            $value = $stmt->fetchColumn();
            echo "  📊 {$label}: {$value}\n";
            $report[] = "  📊 {$label}: {$value}";
        } catch (PDOException $e) {
            echo "  ❌ {$label}: ERRO - {$e->getMessage()}\n";
            $report[] = "  ❌ {$label}: ERRO - {$e->getMessage()}";
        }
    }
    
    $report[] = "";
    $report[] = "=== VALIDAÇÃO CONCLUÍDA ===";
    
    // Salvar relatório
    $reportPath = "backups/merge_report_" . date('Y-m-d_H-i-s') . ".txt";
    file_put_contents($reportPath, implode("\n", $report));
    echo "\n📄 Relatório salvo em: {$reportPath}\n";
    
    echo "\n🎉 Validação concluída com sucesso!\n";
    
} catch (Exception $e) {
    echo "\n❌ Erro na validação: " . $e->getMessage() . "\n";
    $report[] = "❌ ERRO NA VALIDAÇÃO: " . $e->getMessage();
    
    $reportPath = "backups/merge_report_error_" . date('Y-m-d_H-i-s') . ".txt";
    file_put_contents($reportPath, implode("\n", $report));
    
    exit(1);
}
?>