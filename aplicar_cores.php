<?php
// Este arquivo será executado automaticamente quando as cores forem salvas
// através do sistema web, não precisa ser executado via linha de comando

require_once 'conexao.php';

// Função para aplicar as cores padrão se não existirem
function aplicarCoresPadrao() {
    global $pdo;
    
    try {
        // Verificar se já existem configurações de cores
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM configuracoes WHERE chave LIKE 'cor_%'");
        $stmt->execute();
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            // Aplicar cores padrão
            $coresPadrao = [
                'cor_primaria' => '#fbce00',
                'cor_secundaria' => '#ff6b35',
                'cor_azul' => '#007bff',
                'cor_verde' => '#28a745',
                'cor_fundo' => '#f8f9fa',
                'cor_painel' => '#ffffff'
            ];
            
            foreach ($coresPadrao as $chave => $valor) {
                $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?");
                $stmt->execute([$chave, $valor, $valor]);
            }
            
            echo "Cores padrão aplicadas com sucesso!\n";
        } else {
            echo "Configurações de cores já existem.\n";
        }
        
    } catch (PDOException $e) {
        echo "Erro ao aplicar cores: " . $e->getMessage() . "\n";
    }
}

// Executar apenas se chamado diretamente
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    aplicarCoresPadrao();
}
?>