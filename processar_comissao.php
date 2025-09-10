<?php
require 'db.php';

/**
 * Função para processar comissões de afiliados
 * Deve ser chamada sempre que uma transação for aprovada
 */
function processarComissaoAfiliado($usuario_id, $valor_transacao, $pdo) {
    try {
        // Buscar dados do usuário que fez a transação
        $stmt = $pdo->prepare("SELECT codigo_afiliado_usado FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();
        
        if (!$usuario || empty($usuario['codigo_afiliado_usado'])) {
            return false; // Usuário não foi indicado por ninguém
        }
        
        // Buscar afiliado nível 1 (quem indicou diretamente)
        $stmt = $pdo->prepare("
            SELECT id, porcentagem_afiliado, afiliado_ativo, codigo_afiliado_usado as indicado_por_nivel2
            FROM usuarios 
            WHERE codigo_afiliado = ? AND afiliado_ativo = 1
        ");
        $stmt->execute([$usuario['codigo_afiliado_usado']]);
        $afiliado_n1 = $stmt->fetch();
        
        if (!$afiliado_n1) {
            return false; // Afiliado não encontrado ou inativo
        }
        
        // Buscar configurações de comissão
        $config = $pdo->query("SELECT valor_comissao, valor_comissao_n2 FROM configuracoes LIMIT 1")->fetch();
        $porcentagem_n1 = floatval($config['valor_comissao'] ?? 10);
        $porcentagem_n2 = floatval($config['valor_comissao_n2'] ?? 5);
        
        // Calcular e registrar comissão nível 1
        $comissao_n1 = ($valor_transacao * $porcentagem_n1) / 100;
        
        $stmt = $pdo->prepare("
            INSERT INTO comissoes (afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, porcentagem_aplicada, nivel, tipo, status) 
            VALUES (?, ?, ?, ?, ?, 1, 'deposito', 'pendente')
        ");
        $stmt->execute([$afiliado_n1['id'], $usuario_id, $valor_transacao, $comissao_n1, $porcentagem_n1]);
        
        // Atualizar comissão pendente do afiliado nível 1
        $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
        $stmt->execute([$comissao_n1, $afiliado_n1['id']]);
        
        // Verificar se existe afiliado nível 2 e se a comissão N2 está configurada
        if (!empty($afiliado_n1['indicado_por_nivel2']) && $porcentagem_n2 > 0) {
            $stmt = $pdo->prepare("
                SELECT id FROM usuarios 
                WHERE codigo_afiliado = ? AND afiliado_ativo = 1
            ");
            $stmt->execute([$afiliado_n1['indicado_por_nivel2']]);
            $afiliado_n2 = $stmt->fetch();
            
            if ($afiliado_n2) {
                // Calcular e registrar comissão nível 2
                $comissao_n2 = ($valor_transacao * $porcentagem_n2) / 100;
                
                $stmt = $pdo->prepare("
                    INSERT INTO comissoes (afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, porcentagem_aplicada, nivel, tipo, status) 
                    VALUES (?, ?, ?, ?, ?, 2, 'deposito', 'pendente')
                ");
                $stmt->execute([$afiliado_n2['id'], $usuario_id, $valor_transacao, $comissao_n2, $porcentagem_n2]);
                
                // Atualizar comissão pendente do afiliado nível 2
                $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
                $stmt->execute([$comissao_n2, $afiliado_n2['id']]);
            }
        }
        
        // Atualizar estatísticas dos afiliados
        atualizarEstatisticasAfiliado($afiliado_n1['id'], $pdo);
        if (isset($afiliado_n2)) {
            atualizarEstatisticasAfiliado($afiliado_n2['id'], $pdo);
        }
        
        return true;
        
    } catch (PDOException $e) {
        error_log("Erro ao processar comissão: " . $e->getMessage());
        return false;
    }
}

/**
 * Atualizar estatísticas do afiliado
 */
function atualizarEstatisticasAfiliado($afiliado_id, $pdo) {
    try {
        // Contar total de indicados
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM usuarios u1
            JOIN usuarios u2 ON u2.codigo_afiliado = u1.codigo_afiliado
            WHERE u1.id = ? AND u2.codigo_afiliado_usado = u1.codigo_afiliado
        ");
        $stmt->execute([$afiliado_id]);
        $total_indicados = $stmt->fetchColumn();
        
        // Calcular total de comissão gerada
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor_comissao), 0) FROM comissoes WHERE afiliado_id = ?");
        $stmt->execute([$afiliado_id]);
        $total_comissao = $stmt->fetchColumn();
        
        // Atualizar na tabela usuarios
        $stmt = $pdo->prepare("UPDATE usuarios SET total_indicados = ?, total_comissao_gerada = ? WHERE id = ?");
        $stmt->execute([$total_indicados, $total_comissao, $afiliado_id]);
        
    } catch (PDOException $e) {
        error_log("Erro ao atualizar estatísticas do afiliado: " . $e->getMessage());
    }
}

/**
 * Função para ser chamada quando uma transação PIX for aprovada
 * Adicione esta chamada no webhook-pix.php
 */
function onTransacaoAprovada($usuario_id, $valor, $pdo) {
    processarComissaoAfiliado($usuario_id, $valor, $pdo);
}
?>