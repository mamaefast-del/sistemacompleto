<?php
require 'db.php';

/**
 * Função principal para processar comissões de afiliados
 * 100% compatível com phpMyAdmin - SEM stored procedures
 */
function processarComissaoAfiliado($usuario_id, $valor_transacao, $pdo, $tipo = 'deposito') {
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
        $porcentagem_n1 = floatval($afiliado_n1['porcentagem_afiliado'] ?? $config['valor_comissao'] ?? 10);
        $porcentagem_n2 = floatval($config['valor_comissao_n2'] ?? 5);
        
        // Calcular e registrar comissão nível 1
        $comissao_n1 = ($valor_transacao * $porcentagem_n1) / 100;
        
        $stmt = $pdo->prepare("
            INSERT INTO comissoes (afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, porcentagem_aplicada, nivel, tipo, status) 
            VALUES (?, ?, ?, ?, ?, 1, ?, 'pendente')
        ");
        $stmt->execute([$afiliado_n1['id'], $usuario_id, $valor_transacao, $comissao_n1, $porcentagem_n1, $tipo]);
        
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
                    VALUES (?, ?, ?, ?, ?, 2, ?, 'pendente')
                ");
                $stmt->execute([$afiliado_n2['id'], $usuario_id, $valor_transacao, $comissao_n2, $porcentagem_n2, $tipo]);
                
                // Atualizar comissão pendente do afiliado nível 2
                $stmt = $pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
                $stmt->execute([$comissao_n2, $afiliado_n2['id']]);
            }
        }
        
        // Atualizar estatísticas manualmente
        atualizarEstatisticasAfiliado($afiliado_n1['id'], $pdo);
        if (isset($afiliado_n2)) {
            atualizarEstatisticasAfiliado($afiliado_n2['id'], $pdo);
        }
        
        // Marcar conversão no clique se existir
        $stmt = $pdo->prepare("
            UPDATE affiliate_clicks 
            SET converteu = 1, usuario_convertido_id = ? 
            WHERE codigo_afiliado = ? AND converteu = 0 
            ORDER BY data_click DESC 
            LIMIT 1
        ");
        $stmt->execute([$usuario_id, $usuario['codigo_afiliado_usado']]);
        
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
        // Buscar código do afiliado
        $stmt = $pdo->prepare("SELECT codigo_afiliado FROM usuarios WHERE id = ?");
        $stmt->execute([$afiliado_id]);
        $codigo = $stmt->fetchColumn();
        
        if ($codigo) {
            // Contar total de indicados
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE codigo_afiliado_usado = ?");
            $stmt->execute([$codigo]);
            $total_indicados = $stmt->fetchColumn();
            
            // Calcular total de comissão gerada
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor_comissao), 0) FROM comissoes WHERE afiliado_id = ?");
            $stmt->execute([$afiliado_id]);
            $total_comissao = $stmt->fetchColumn();
            
            // Atualizar na tabela usuarios
            $stmt = $pdo->prepare("UPDATE usuarios SET total_indicados = ?, total_comissao_gerada = ? WHERE id = ?");
            $stmt->execute([$total_indicados, $total_comissao, $afiliado_id]);
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao atualizar estatísticas: " . $e->getMessage());
    }
}

/**
 * Função para rastrear clique de afiliado
 */
function rastrearCliqueAfiliado($codigo_afiliado, $pdo) {
    try {
        // Buscar afiliado
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE codigo_afiliado = ? AND afiliado_ativo = 1");
        $stmt->execute([$codigo_afiliado]);
        $afiliado = $stmt->fetch();
        
        if ($afiliado) {
            // Registrar clique
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            
            $stmt = $pdo->prepare("
                INSERT INTO affiliate_clicks (afiliado_id, codigo_afiliado, ip_address, user_agent, referrer) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$afiliado['id'], $codigo_afiliado, $ip_address, $user_agent, $referrer]);
            
            return true;
        }
    } catch (PDOException $e) {
        error_log("Erro ao rastrear clique de afiliado: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Função para gerar código de afiliado único
 */
function gerarCodigoAfiliado($usuario_id, $pdo) {
    do {
        $codigo = strtoupper(substr(md5(uniqid() . $usuario_id . time()), 0, 8));
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE codigo_afiliado = ?");
        $stmt->execute([$codigo]);
    } while ($stmt->fetch());
    
    return $codigo;
}

/**
 * Função para calcular taxa de conversão
 */
function calcularTaxaConversao($afiliado_id, $pdo) {
    try {
        // Buscar total de cliques
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliate_clicks WHERE afiliado_id = ?");
        $stmt->execute([$afiliado_id]);
        $total_clicks = $stmt->fetchColumn();
        
        // Buscar cliques que converteram (viraram indicações)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliate_clicks WHERE afiliado_id = ? AND converteu = 1");
        $stmt->execute([$afiliado_id]);
        $cliques_convertidos = $stmt->fetchColumn();
        
        // Taxa de conversão = (cliques convertidos / total de cliques) * 100
        return $total_clicks > 0 ? ($cliques_convertidos / $total_clicks) * 100 : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Função para ser chamada quando uma transação PIX for aprovada
 * Adicione esta chamada no webhook-pix.php
 */
function onTransacaoAprovada($usuario_id, $valor, $pdo) {
    return processarComissaoAfiliado($usuario_id, $valor, $pdo, 'deposito');
}

/**
 * Função para processar comissão quando usuário joga
 * Adicione esta chamada no sistema de jogos
 */
function onUsuarioJogou($usuario_id, $valor_apostado, $valor_ganho, $pdo) {
    // Processar comissão sobre perdas (valor apostado - valor ganho)
    $valor_perda = $valor_apostado - $valor_ganho;
    if ($valor_perda > 0) {
        return processarComissaoAfiliado($usuario_id, $valor_perda, $pdo, 'jogada');
    }
    return false;
}

/**
 * Função para transformar usuário em afiliado
 */
function criarAfiliado($usuario_id, $porcentagem = 10.00, $pdo) {
    try {
        // Verificar se usuário existe
        $stmt = $pdo->prepare("SELECT id, codigo_afiliado FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch();
        
        if (!$usuario) {
            return false;
        }
        
        // Se já tem código mas está inativo, apenas reativar
        if (!empty($usuario['codigo_afiliado'])) {
            $stmt = $pdo->prepare("UPDATE usuarios SET afiliado_ativo = 1, porcentagem_afiliado = ? WHERE id = ?");
            $stmt->execute([$porcentagem, $usuario_id]);
            
            // Registrar no histórico
            $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes) VALUES (?, 'ativacao', 'Afiliado reativado')");
            $stmt->execute([$usuario_id]);
            
            return $usuario['codigo_afiliado'];
        }
        
        // Gerar código único
        $codigo = gerarCodigoAfiliado($usuario_id, $pdo);
        
        // Atualizar usuário
        $stmt = $pdo->prepare("
            UPDATE usuarios 
            SET codigo_afiliado = ?, afiliado_ativo = 1, porcentagem_afiliado = ?, data_aprovacao_afiliado = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$codigo, $porcentagem, $usuario_id]);
        
        // Registrar no histórico
        $stmt = $pdo->prepare("INSERT INTO historico_afiliados (afiliado_id, acao, detalhes) VALUES (?, 'create_affiliate', ?)");
        $stmt->execute([$usuario_id, "Usuário transformado em afiliado - Código: $codigo"]);
        
        return $codigo;
        
    } catch (PDOException $e) {
        error_log("Erro ao criar afiliado: " . $e->getMessage());
        return false;
    }
}

// Exemplo de uso no webhook-pix.php:
/*
require 'process_affiliate_commission.php';

// Após aprovar uma transação, chame:
if ($status === 'completed') {
    // ... código existente ...
    
    // Processar comissão de afiliado
    onTransacaoAprovada($transacao['usuario_id'], $amount, $pdo);
}
*/

// Exemplo de uso no sistema de jogos:
/*
require 'process_affiliate_commission.php';

// Após uma jogada, chame:
onUsuarioJogou($usuario_id, $valor_apostado, $valor_ganho, $pdo);
*/
?>