<?php
/**
 * Script para criar backups antes da migração
 */

require_once 'db.php';

echo "🔄 Iniciando processo de backup...\n";

// Configurações do banco
$host = '127.0.0.1';
$db = 'caixasupresa';
$user = 'caixasupresa';
$pass = 'caixasupresa';

// 1. Backup completo (estrutura + dados)
echo "📦 Criando backup completo...\n";
$backupCompleto = "backups/backup_pre_merge_" . date('Y-m-d_H-i-s') . ".sql";
$cmdCompleto = "mysqldump -h{$host} -u{$user} -p{$pass} {$db} > {$backupCompleto}";
exec($cmdCompleto, $output, $return);

if ($return === 0) {
    echo "✅ Backup completo criado: {$backupCompleto}\n";
} else {
    echo "❌ Erro ao criar backup completo\n";
    exit(1);
}

// 2. Backup apenas estrutura
echo "🏗️ Criando backup da estrutura...\n";
$backupEstrutura = "backups/backup_structure_pre_merge_" . date('Y-m-d_H-i-s') . ".sql";
$cmdEstrutura = "mysqldump -h{$host} -u{$user} -p{$pass} --no-data {$db} > {$backupEstrutura}";
exec($cmdEstrutura, $output, $return);

if ($return === 0) {
    echo "✅ Backup da estrutura criado: {$backupEstrutura}\n";
} else {
    echo "❌ Erro ao criar backup da estrutura\n";
    exit(1);
}

// 3. Análise do esquema atual
echo "🔍 Analisando esquema atual...\n";

try {
    // Listar todas as tabelas
    $stmt = $pdo->query("SHOW TABLES");
    $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 Tabelas encontradas: " . count($tabelas) . "\n";
    foreach ($tabelas as $tabela) {
        echo "  - {$tabela}\n";
    }
    
    // Verificar tabelas específicas de afiliados
    $tabelasAfiliados = [
        'affiliate_clicks',
        'affiliate_attributions', 
        'payment_callbacks',
        'affiliate_config'
    ];
    
    echo "\n🔍 Verificando tabelas de afiliados existentes:\n";
    foreach ($tabelasAfiliados as $tabela) {
        $existe = in_array($tabela, $tabelas);
        echo "  - {$tabela}: " . ($existe ? "✅ Existe" : "❌ Não existe") . "\n";
    }
    
    // Verificar colunas específicas na tabela usuarios
    echo "\n👤 Verificando colunas na tabela usuarios:\n";
    $stmt = $pdo->query("DESCRIBE usuarios");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $colunasAfiliados = [
        'attributed_affiliate_id',
        'ref_code_attributed',
        'attributed_at',
        'first_deposit_confirmed',
        'first_deposit_amount',
        'first_deposit_at'
    ];
    
    foreach ($colunasAfiliados as $coluna) {
        $existe = in_array($coluna, $colunas);
        echo "  - {$coluna}: " . ($existe ? "✅ Existe" : "❌ Não existe") . "\n";
    }
    
    // Verificar colunas na tabela transacoes_pix
    echo "\n💳 Verificando colunas na tabela transacoes_pix:\n";
    $stmt = $pdo->query("DESCRIBE transacoes_pix");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $colunasTransacao = [
        'affiliate_id',
        'ref_code',
        'attributed_at',
        'is_first_deposit'
    ];
    
    foreach ($colunasTransacao as $coluna) {
        $existe = in_array($coluna, $colunas);
        echo "  - {$coluna}: " . ($existe ? "✅ Existe" : "❌ Não existe") . "\n";
    }
    
    echo "\n✅ Análise do esquema concluída!\n";
    
} catch (PDOException $e) {
    echo "❌ Erro na análise: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎯 Backups criados com sucesso! Pronto para migração.\n";
?>