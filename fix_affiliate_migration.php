<?php
/**
 * Script para corrigir dados de afiliados existentes
 * Migra dados do sistema antigo para o novo
 */

require 'db.php';

echo "🔄 Iniciando correção dos dados de afiliados...\n";

try {
    // 1. Verificar se as novas tabelas existem
    $tables_needed = ['affiliate_clicks', 'affiliate_attributions', 'payment_callbacks', 'affiliate_config'];
    
    foreach ($tables_needed as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        $exists = $stmt->fetchColumn() > 0;
        
        if (!$exists) {
            echo "❌ Tabela $table não existe. Execute primeiro a migração SQL.\n";
            exit(1);
        }
    }
    
    echo "✅ Todas as tabelas necessárias existem.\n";
    
    // 2. Verificar se as colunas foram adicionadas
    $columns_needed = [
        'usuarios' => ['attributed_affiliate_id', 'ref_code_attributed', 'attributed_at', 'first_deposit_confirmed'],
        'transacoes_pix' => ['affiliate_id', 'ref_code', 'is_first_deposit']
    ];
    
    foreach ($columns_needed as $table => $columns) {
        foreach ($columns as $column) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            $exists = $stmt->fetchColumn() > 0;
            
            if (!$exists) {
                echo "❌ Coluna $table.$column não existe. Execute primeiro a migração SQL.\n";
                exit(1);
            }
        }
    }
    
    echo "✅ Todas as colunas necessárias existem.\n";
    
    // 3. Migrar dados existentes
    echo "🔄 Migrando dados existentes...\n";
    
    // Migrar atribuições de codigo_afiliado_usado para attributed_affiliate_id
    $stmt = $pdo->prepare("
        UPDATE usuarios u1 
        SET attributed_affiliate_id = (
            SELECT u2.id 
            FROM usuarios u2 
            WHERE u2.codigo_afiliado = u1.codigo_afiliado_usado 
            LIMIT 1
        ),
        ref_code_attributed = u1.codigo_afiliado_usado,
        attributed_at = u1.data_cadastro
        WHERE u1.codigo_afiliado_usado IS NOT NULL 
        AND u1.codigo_afiliado_usado != ''
        AND u1.attributed_affiliate_id IS NULL
    ");
    $stmt->execute();
    $migrated_users = $stmt->rowCount();
    echo "✅ $migrated_users usuários migrados para nova estrutura.\n";
    
    // Criar atribuições para usuários já existentes
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO affiliate_attributions (user_id, affiliate_id, ref_code, attribution_model, created_at)
        SELECT 
            u1.id,
            u1.attributed_affiliate_id,
            u1.ref_code_attributed,
            'LAST_CLICK',
            u1.attributed_at
        FROM usuarios u1
        WHERE u1.attributed_affiliate_id IS NOT NULL 
        AND u1.ref_code_attributed IS NOT NULL
    ");
    $stmt->execute();
    $created_attributions = $stmt->rowCount();
    echo "✅ $created_attributions atribuições criadas.\n";
    
    // Marcar primeiros depósitos
    $stmt = $pdo->prepare("
        UPDATE transacoes_pix t
        SET is_first_deposit = 1,
            affiliate_id = (SELECT attributed_affiliate_id FROM usuarios WHERE id = t.usuario_id),
            ref_code = (SELECT ref_code_attributed FROM usuarios WHERE id = t.usuario_id),
            attributed_at = t.criado_em
        WHERE t.status = 'aprovado'
        AND t.is_first_deposit IS NULL
        AND t.id = (
            SELECT MIN(t2.id) 
            FROM transacoes_pix t2 
            WHERE t2.usuario_id = t.usuario_id 
            AND t2.status = 'aprovado'
        )
    ");
    $stmt->execute();
    $marked_deposits = $stmt->rowCount();
    echo "✅ $marked_deposits primeiros depósitos marcados.\n";
    
    // Atualizar usuários com primeiro depósito confirmado
    $stmt = $pdo->prepare("
        UPDATE usuarios u
        SET first_deposit_confirmed = 1,
            first_deposit_amount = (
                SELECT t.valor 
                FROM transacoes_pix t 
                WHERE t.usuario_id = u.id 
                AND t.is_first_deposit = 1 
                AND t.status = 'aprovado'
                LIMIT 1
            ),
            first_deposit_at = (
                SELECT t.criado_em 
                FROM transacoes_pix t 
                WHERE t.usuario_id = u.id 
                AND t.is_first_deposit = 1 
                AND t.status = 'aprovado'
                LIMIT 1
            )
        WHERE EXISTS (
            SELECT 1 FROM transacoes_pix t 
            WHERE t.usuario_id = u.id 
            AND t.is_first_deposit = 1 
            AND t.status = 'aprovado'
        )
        AND u.first_deposit_confirmed = 0
    ");
    $stmt->execute();
    $updated_users = $stmt->rowCount();
    echo "✅ $updated_users usuários atualizados com primeiro depósito.\n";
    
    // 4. Verificar estatísticas finais
    echo "\n📊 Estatísticas finais:\n";
    
    $stats = [
        'Total de usuários' => $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn(),
        'Usuários com atribuição' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE attributed_affiliate_id IS NOT NULL")->fetchColumn(),
        'Afiliados ativos' => $pdo->query("SELECT COUNT(*) FROM usuarios WHERE afiliado_ativo = 1")->fetchColumn(),
        'Atribuições criadas' => $pdo->query("SELECT COUNT(*) FROM affiliate_attributions")->fetchColumn(),
        'Primeiros depósitos marcados' => $pdo->query("SELECT COUNT(*) FROM transacoes_pix WHERE is_first_deposit = 1")->fetchColumn(),
        'Cliques registrados' => $pdo->query("SELECT COUNT(*) FROM affiliate_clicks")->fetchColumn()
    ];
    
    foreach ($stats as $label => $value) {
        echo "  📈 $label: $value\n";
    }
    
    echo "\n🎉 Migração de dados concluída com sucesso!\n";
    echo "🔗 Acesse debug_affiliate_data.php para verificar os dados do seu afiliado.\n";
    
} catch (PDOException $e) {
    echo "❌ Erro durante a migração: " . $e->getMessage() . "\n";
    exit(1);
}
?>