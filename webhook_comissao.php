<?php
require 'db.php';

// Função para calcular e registrar comissões
function calcularComissao($usuario_id, $valor_transacao, $pdo) {
    try {
        // Buscar dados do usuário
        $stmt = $pdo->prepare("SELECT codigo_afiliado_usado FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();
        
        if (!$usuario || empty($usuario['codigo_afiliado_usado'])) {
            return; // Usuário não foi indicado por ninguém
        }
        
        // Buscar afiliado que indicou
        $stmt = $pdo->prepare("
            SELECT id, porcentagem_afiliado, afiliado_ativo, codigo_afiliado_usado as afiliado_nivel2
            FROM usuarios 
            WHERE codigo_afiliado = ? AND afiliado_ativo = 1
        ");
        $stmt->execute([$usuario['codigo_afiliado_usado']]);
        $afiliado_nivel1 = $stmt->fetch();
        
        if (!$afiliado_nivel1) {
            return; // Afiliado não encontrado ou inativo
        }
        
        // Buscar configurações de comissão
        $config = $pdo->query("SELECT valor_comissao, valor_comissao_n2 FROM configuracoes LIMIT 1")->fetch();
        $porcentagem_n1 = floatval($config['valor_comissao'] ?? 10);
        $porcentagem_n2 = floatval($config['valor_comissao_n2'] ?? 5);
        
        // Calcular comissão nível 1
        $comissao_n1 = ($valor_transacao * $porcentagem_n1) / 100;
        
        // Registrar comissão nível 1
        $stmt = $pdo->prepare("
            INSERT INTO comissoes (afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, porcentagem_aplicada, nivel, tipo, status) 
            VALUES (?, ?, ?, ?, ?, 1, 'deposito', 'pendente')
        ");
        $stmt->execute([$afiliado_nivel1['id'], $usuario_id, $valor_transacao, $comissao_n1, $porcentagem_n1]);
        
        // Atualizar saldo de comissão do afiliado nível 1
        $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
        $stmt->execute([$comissao_n1, $afiliado_nivel1['id']]);
        
        // Verificar se existe afiliado nível 2
        if (!empty($afiliado_nivel1['afiliado_nivel2']) && $porcentagem_n2 > 0) {
            $stmt = $pdo->prepare("
                SELECT id FROM usuarios 
                WHERE codigo_afiliado = ? AND afiliado_ativo = 1
            ");
            $stmt->execute([$afiliado_nivel1['afiliado_nivel2']]);
            $afiliado_nivel2 = $stmt->fetch();
            
            if ($afiliado_nivel2) {
                // Calcular comissão nível 2
                $comissao_n2 = ($valor_transacao * $porcentagem_n2) / 100;
                
                // Registrar comissão nível 2
                $stmt = $pdo->prepare("
                    INSERT INTO comissoes (afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, porcentagem_aplicada, nivel, tipo, status) 
                    VALUES (?, ?, ?, ?, ?, 2, 'deposito', 'pendente')
                ");
                $stmt->execute([$afiliado_nivel2['id'], $usuario_id, $valor_transacao, $comissao_n2, $porcentagem_n2]);
                
                // Atualizar saldo de comissão do afiliado nível 2
                $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
                $stmt->execute([$comissao_n2, $afiliado_nivel2['id']]);
            }
        }
        
        // Log da operação
        error_log("Comissão calculada - Usuário: $usuario_id, Valor: $valor_transacao, Comissão N1: $comissao_n1");
        
    } catch (PDOException $e) {
        error_log("Erro ao calcular comissão: " . $e->getMessage());
    }
}

// Esta função pode ser chamada de outros arquivos quando necessário
// Por exemplo, no webhook-pix.php quando uma transação for aprovada
?>