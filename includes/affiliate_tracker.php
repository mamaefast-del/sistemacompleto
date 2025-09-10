<?php
/**
 * Sistema de Rastreamento de Afiliados
 * Captura cliques, UTMs e gerencia atribuições
 */

class AffiliateTracker {
    private $pdo;
    private $config;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }
    
    /**
     * Carregar configurações do sistema de afiliados
     */
    private function loadConfig() {
        try {
            $stmt = $this->pdo->query("SELECT config_key, config_value FROM affiliate_config");
            $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $this->config = [
                'cookie_name' => $configs['AFF_COOKIE_NAME'] ?? 'aff_ref',
                'cookie_days' => intval($configs['AFF_COOKIE_DAYS'] ?? 30),
                'attribution_model' => $configs['AFF_ATTR_MODEL'] ?? 'LAST_CLICK',
                'cookie_domain' => $configs['AFF_COOKIE_DOMAIN'] ?? '',
                'commission_rate_l1' => floatval($configs['AFF_COMMISSION_RATE_L1'] ?? 10.00),
                'commission_rate_l2' => floatval($configs['AFF_COMMISSION_RATE_L2'] ?? 5.00),
                'min_payout' => floatval($configs['AFF_MIN_PAYOUT'] ?? 10.00),
                'max_payout' => floatval($configs['AFF_MAX_PAYOUT'] ?? 1000.00)
            ];
        } catch (PDOException $e) {
            // Fallback para configurações padrão
            $this->config = [
                'cookie_name' => 'aff_ref',
                'cookie_days' => 30,
                'attribution_model' => 'LAST_CLICK',
                'cookie_domain' => '',
                'commission_rate_l1' => 10.00,
                'commission_rate_l2' => 5.00,
                'min_payout' => 10.00,
                'max_payout' => 1000.00
            ];
        }
    }
    
    /**
     * Capturar clique de afiliado e UTMs
     */
    public function trackClick() {
        // Verificar se há parâmetros de afiliado
        $refCode = $this->getRefCode();
        if (!$refCode) {
            return false;
        }
        
        // Buscar afiliado pelo código
        $affiliate = $this->getAffiliateByCode($refCode);
        if (!$affiliate) {
            return false;
        }
        
        // Capturar dados do clique
        $clickData = $this->captureClickData($refCode, $affiliate['id']);
        
        // Salvar clique no banco
        $clickId = $this->saveClick($clickData);
        
        // Definir cookies e sessão
        $this->setAffiliateSession($refCode, $clickId, $clickData);
        
        return $clickId;
    }
    
    /**
     * Obter código de referência da URL
     */
    private function getRefCode() {
        // Verificar diferentes parâmetros possíveis
        $params = ['ref', 'codigo', 'affiliate', 'aff', 'referral'];
        
        foreach ($params as $param) {
            if (!empty($_GET[$param])) {
                return $this->sanitizeRefCode($_GET[$param]);
            }
        }
        
        return null;
    }
    
    /**
     * Sanitizar código de referência
     */
    private function sanitizeRefCode($code) {
        // Remover caracteres especiais, manter apenas alfanuméricos
        $code = preg_replace('/[^a-zA-Z0-9]/', '', $code);
        return strtoupper(substr($code, 0, 50));
    }
    
    /**
     * Buscar afiliado pelo código
     */
    private function getAffiliateByCode($refCode) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, nome, email, codigo_afiliado, porcentagem_afiliado, afiliado_ativo 
                FROM usuarios 
                WHERE codigo_afiliado = ? AND afiliado_ativo = 1
            ");
            $stmt->execute([$refCode]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar afiliado: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Capturar dados do clique
     */
    private function captureClickData($refCode, $affiliateId) {
        // Capturar UTMs
        $utms = [
            'utm_source' => $_GET['utm_source'] ?? null,
            'utm_medium' => $_GET['utm_medium'] ?? null,
            'utm_campaign' => $_GET['utm_campaign'] ?? null,
            'utm_content' => $_GET['utm_content'] ?? null,
            'utm_term' => $_GET['utm_term'] ?? null
        ];
        
        // Filtrar UTMs vazios
        $utms = array_filter($utms, function($value) {
            return !empty($value);
        });
        
        // Capturar informações do request
        $url = $_SERVER['REQUEST_URI'] ?? '';
        $fullUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                   '://' . ($_SERVER['HTTP_HOST'] ?? '') . $url;
        
        $subdomain = $this->extractSubdomain($_SERVER['HTTP_HOST'] ?? '');
        
        return [
            'affiliate_id' => $affiliateId,
            'ref_code' => $refCode,
            'url' => $fullUrl,
            'utms_json' => !empty($utms) ? json_encode($utms) : null,
            'utm_source' => $utms['utm_source'] ?? null,
            'utm_medium' => $utms['utm_medium'] ?? null,
            'utm_campaign' => $utms['utm_campaign'] ?? null,
            'utm_content' => $utms['utm_content'] ?? null,
            'utm_term' => $utms['utm_term'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'subdomain' => $subdomain,
            'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
            'session_id' => session_id()
        ];
    }
    
    /**
     * Extrair subdomínio
     */
    private function extractSubdomain($host) {
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            return $parts[0];
        }
        return null;
    }
    
    /**
     * Salvar clique no banco
     */
    private function saveClick($clickData) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO affiliate_clicks (
                    affiliate_id, ref_code, url, utms_json, utm_source, utm_medium, 
                    utm_campaign, utm_content, utm_term, ip_address, user_agent, 
                    subdomain, referrer, session_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $clickData['affiliate_id'],
                $clickData['ref_code'],
                $clickData['url'],
                $clickData['utms_json'],
                $clickData['utm_source'],
                $clickData['utm_medium'],
                $clickData['utm_campaign'],
                $clickData['utm_content'],
                $clickData['utm_term'],
                $clickData['ip_address'],
                $clickData['user_agent'],
                $clickData['subdomain'],
                $clickData['referrer'],
                $clickData['session_id']
            ]);
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Erro ao salvar clique: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Definir sessão e cookies de afiliado
     */
    private function setAffiliateSession($refCode, $clickId, $clickData) {
        // Definir na sessão
        $_SESSION['affiliate_ref'] = $refCode;
        $_SESSION['affiliate_click_id'] = $clickId;
        $_SESSION['affiliate_utms'] = array_filter([
            'utm_source' => $clickData['utm_source'],
            'utm_medium' => $clickData['utm_medium'],
            'utm_campaign' => $clickData['utm_campaign'],
            'utm_content' => $clickData['utm_content'],
            'utm_term' => $clickData['utm_term']
        ]);
        
        // Definir cookie
        $cookieName = $this->config['cookie_name'];
        $cookieDays = $this->config['cookie_days'];
        $cookieDomain = $this->config['cookie_domain'];
        
        $cookieData = json_encode([
            'ref_code' => $refCode,
            'click_id' => $clickId,
            'timestamp' => time(),
            'utms' => $_SESSION['affiliate_utms']
        ]);
        
        $cookieOptions = [
            'expires' => time() + ($cookieDays * 24 * 60 * 60),
            'path' => '/',
            'domain' => $cookieDomain ?: null,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        
        setcookie($cookieName, $cookieData, $cookieOptions);
    }
    
    /**
     * Obter dados de afiliado da sessão/cookie
     */
    public function getAffiliateData() {
        // Primeiro tentar da sessão
        if (!empty($_SESSION['affiliate_ref'])) {
            return [
                'ref_code' => $_SESSION['affiliate_ref'],
                'click_id' => $_SESSION['affiliate_click_id'] ?? null,
                'utms' => $_SESSION['affiliate_utms'] ?? [],
                'source' => 'session'
            ];
        }
        
        // Tentar do cookie
        $cookieName = $this->config['cookie_name'];
        if (!empty($_COOKIE[$cookieName])) {
            $cookieData = json_decode($_COOKIE[$cookieName], true);
            if ($cookieData && !empty($cookieData['ref_code'])) {
                // Verificar se não expirou
                $maxAge = $this->config['cookie_days'] * 24 * 60 * 60;
                if ((time() - ($cookieData['timestamp'] ?? 0)) <= $maxAge) {
                    return [
                        'ref_code' => $cookieData['ref_code'],
                        'click_id' => $cookieData['click_id'] ?? null,
                        'utms' => $cookieData['utms'] ?? [],
                        'source' => 'cookie'
                    ];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Atribuir usuário a afiliado no cadastro
     */
    public function attributeUser($userId) {
        $affiliateData = $this->getAffiliateData();
        if (!$affiliateData) {
            return false;
        }
        
        $refCode = $affiliateData['ref_code'];
        $clickId = $affiliateData['click_id'];
        $utms = $affiliateData['utms'];
        
        // Buscar afiliado
        $affiliate = $this->getAffiliateByCode($refCode);
        if (!$affiliate) {
            return false;
        }
        
        try {
            // Verificar se já existe atribuição
            $stmt = $this->pdo->prepare("SELECT id, attribution_model FROM affiliate_attributions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $existingAttribution = $stmt->fetch();
            
            $shouldUpdate = false;
            
            if (!$existingAttribution) {
                // Primeira atribuição
                $shouldUpdate = true;
            } elseif ($this->config['attribution_model'] === 'LAST_CLICK') {
                // Modelo LAST_CLICK: sempre atualizar
                $shouldUpdate = true;
            }
            // Se for FIRST_CLICK e já existe atribuição, não atualizar
            
            if ($shouldUpdate) {
                // Inserir ou atualizar atribuição
                if ($existingAttribution) {
                    $stmt = $this->pdo->prepare("
                        UPDATE affiliate_attributions 
                        SET affiliate_id = ?, ref_code = ?, attribution_model = ?, click_id = ?,
                            utm_source = ?, utm_medium = ?, utm_campaign = ?, utm_content = ?, utm_term = ?,
                            updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $stmt->execute([
                        $affiliate['id'], $refCode, $this->config['attribution_model'], $clickId,
                        $utms['utm_source'] ?? null, $utms['utm_medium'] ?? null, 
                        $utms['utm_campaign'] ?? null, $utms['utm_content'] ?? null, 
                        $utms['utm_term'] ?? null, $userId
                    ]);
                } else {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO affiliate_attributions (
                            user_id, affiliate_id, ref_code, attribution_model, click_id,
                            utm_source, utm_medium, utm_campaign, utm_content, utm_term
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $userId, $affiliate['id'], $refCode, $this->config['attribution_model'], $clickId,
                        $utms['utm_source'] ?? null, $utms['utm_medium'] ?? null, 
                        $utms['utm_campaign'] ?? null, $utms['utm_content'] ?? null, 
                        $utms['utm_term'] ?? null
                    ]);
                }
                
                // Atualizar tabela de usuários
                $stmt = $this->pdo->prepare("
                    UPDATE usuarios 
                    SET attributed_affiliate_id = ?, ref_code_attributed = ?, attributed_at = NOW(),
                        codigo_afiliado_usado = ?
                    WHERE id = ?
                ");
                $stmt->execute([$affiliate['id'], $refCode, $refCode, $userId]);
                
                // Marcar clique como convertido
                if ($clickId) {
                    $stmt = $this->pdo->prepare("
                        UPDATE affiliate_clicks 
                        SET converteu = 1, usuario_convertido_id = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$userId, $clickId]);
                }
                
                return true;
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Erro ao atribuir usuário: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Processar primeiro depósito confirmado
     */
    public function processFirstDeposit($userId, $transactionId, $amount) {
        try {
            // Verificar se já foi processado (idempotência)
            $stmt = $this->pdo->prepare("
                SELECT id FROM payment_callbacks 
                WHERE transaction_id = ? AND provider = 'expfypay'
            ");
            $stmt->execute([$transactionId]);
            if ($stmt->fetch()) {
                return false; // Já processado
            }
            
            // Verificar se é realmente o primeiro depósito
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM transacoes_pix 
                WHERE usuario_id = ? AND status = 'aprovado'
            ");
            $stmt->execute([$userId]);
            $depositCount = $stmt->fetchColumn();
            
            $isFirstDeposit = ($depositCount <= 1);
            
            // Buscar atribuição do usuário
            $stmt = $this->pdo->prepare("
                SELECT aa.*, ac.id as click_id 
                FROM affiliate_attributions aa
                LEFT JOIN affiliate_clicks ac ON aa.click_id = ac.id
                WHERE aa.user_id = ?
            ");
            $stmt->execute([$userId]);
            $attribution = $stmt->fetch();
            
            if ($attribution && $isFirstDeposit) {
                // Atualizar transação com dados de afiliado
                $stmt = $this->pdo->prepare("
                    UPDATE transacoes_pix 
                    SET affiliate_id = ?, ref_code = ?, attributed_at = NOW(), is_first_deposit = 1
                    WHERE transaction_id = ? OR external_id = ?
                ");
                $stmt->execute([
                    $attribution['affiliate_id'], 
                    $attribution['ref_code'], 
                    $transactionId, 
                    $transactionId
                ]);
                
                // Atualizar usuário
                $stmt = $this->pdo->prepare("
                    UPDATE usuarios 
                    SET first_deposit_confirmed = 1, first_deposit_amount = ?, first_deposit_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $userId]);
                
                // Processar comissão
                $this->processCommission($attribution['affiliate_id'], $userId, $amount, $transactionId, $attribution['ref_code']);
                
                return true;
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Erro ao processar primeiro depósito: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Processar comissão de afiliado
     */
    private function processCommission($affiliateId, $userId, $amount, $transactionId, $refCode) {
        try {
            // Verificar se comissão já foi processada
            $stmt = $this->pdo->prepare("
                SELECT id FROM comissoes 
                WHERE transaction_id = ? AND afiliado_id = ?
            ");
            $stmt->execute([$transactionId, $affiliateId]);
            if ($stmt->fetch()) {
                return false; // Já processada
            }
            
            // Buscar dados do afiliado
            $stmt = $this->pdo->prepare("
                SELECT porcentagem_afiliado, codigo_afiliado_usado 
                FROM usuarios 
                WHERE id = ?
            ");
            $stmt->execute([$affiliateId]);
            $affiliateData = $stmt->fetch();
            
            if (!$affiliateData) {
                return false;
            }
            
            // Calcular comissão nível 1
            $commissionRate = floatval($affiliateData['porcentagem_afiliado'] ?? $this->config['commission_rate_l1']);
            $commissionAmount = ($amount * $commissionRate) / 100;
            
            // Registrar comissão nível 1
            $stmt = $this->pdo->prepare("
                INSERT INTO comissoes (
                    afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, 
                    porcentagem_aplicada, nivel, tipo, status, transaction_id, ref_code, is_first_deposit
                ) VALUES (?, ?, ?, ?, ?, 1, 'deposito', 'pendente', ?, ?, 1)
            ");
            $stmt->execute([
                $affiliateId, $userId, $amount, $commissionAmount, 
                $commissionRate, $transactionId, $refCode
            ]);
            
            // Atualizar saldo de comissão
            $stmt = $this->pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
            $stmt->execute([$commissionAmount, $affiliateId]);
            
            // Verificar comissão nível 2
            if (!empty($affiliateData['codigo_afiliado_usado'])) {
                $this->processLevel2Commission($affiliateData['codigo_afiliado_usado'], $userId, $amount, $transactionId, $refCode);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Erro ao processar comissão: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Processar comissão nível 2
     */
    private function processLevel2Commission($level1RefCode, $userId, $amount, $transactionId, $originalRefCode) {
        try {
            // Buscar afiliado nível 2
            $stmt = $this->pdo->prepare("
                SELECT id FROM usuarios 
                WHERE codigo_afiliado = ? AND afiliado_ativo = 1
            ");
            $stmt->execute([$level1RefCode]);
            $level2Affiliate = $stmt->fetch();
            
            if ($level2Affiliate) {
                $commissionRate = $this->config['commission_rate_l2'];
                $commissionAmount = ($amount * $commissionRate) / 100;
                
                // Registrar comissão nível 2
                $stmt = $this->pdo->prepare("
                    INSERT INTO comissoes (
                        afiliado_id, usuario_indicado_id, valor_transacao, valor_comissao, 
                        porcentagem_aplicada, nivel, tipo, status, transaction_id, ref_code, is_first_deposit
                    ) VALUES (?, ?, ?, ?, ?, 2, 'deposito', 'pendente', ?, ?, 1)
                ");
                $stmt->execute([
                    $level2Affiliate['id'], $userId, $amount, $commissionAmount, 
                    $commissionRate, $transactionId, $originalRefCode
                ]);
                
                // Atualizar saldo de comissão
                $stmt = $this->pdo->prepare("UPDATE usuarios SET comissao = comissao + ? WHERE id = ?");
                $stmt->execute([$commissionAmount, $level2Affiliate['id']]);
            }
            
        } catch (PDOException $e) {
            error_log("Erro ao processar comissão nível 2: " . $e->getMessage());
        }
    }
    
    /**
     * Registrar callback de pagamento
     */
    public function logPaymentCallback($provider, $transactionId, $payload, $status, $signatureOk = false, $userId = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_callbacks (
                    provider, transaction_id, payload_json, status, signature_ok, user_id
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    payload_json = VALUES(payload_json),
                    status = VALUES(status),
                    signature_ok = VALUES(signature_ok),
                    user_id = VALUES(user_id),
                    processed_at = NOW()
            ");
            
            $stmt->execute([
                $provider, $transactionId, json_encode($payload), 
                $status, $signatureOk ? 1 : 0, $userId
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Erro ao registrar callback: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obter estatísticas de afiliado
     */
    public function getAffiliateStats($affiliateId, $dateFrom = null, $dateTo = null) {
        try {
            $dateFilter = '';
            $params = [$affiliateId];
            
            if ($dateFrom && $dateTo) {
                $dateFilter = 'AND DATE(ac.created_at) BETWEEN ? AND ?';
                $params[] = $dateFrom;
                $params[] = $dateTo;
            }
            
            // Estatísticas de cliques
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_clicks,
                    COUNT(DISTINCT ip_address) as unique_visitors,
                    COUNT(CASE WHEN converteu = 1 THEN 1 END) as converted_clicks
                FROM affiliate_clicks ac
                WHERE affiliate_id = ? $dateFilter
            ");
            $stmt->execute($params);
            $clickStats = $stmt->fetch();
            
            // Estatísticas de cadastros
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as total_signups
                FROM affiliate_attributions aa
                WHERE affiliate_id = ? $dateFilter
            ");
            $stmt->execute($params);
            $signupStats = $stmt->fetch();
            
            // Estatísticas de depósitos
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_deposits,
                    SUM(valor) as total_amount,
                    COUNT(CASE WHEN is_first_deposit = 1 THEN 1 END) as first_deposits,
                    SUM(CASE WHEN is_first_deposit = 1 THEN valor ELSE 0 END) as first_deposit_amount
                FROM transacoes_pix t
                WHERE affiliate_id = ? AND status = 'aprovado' $dateFilter
            ");
            $stmt->execute($params);
            $depositStats = $stmt->fetch();
            
            // Estatísticas de comissões
            $stmt = $this->pdo->prepare("
                SELECT 
                    SUM(valor_comissao) as total_commission,
                    SUM(CASE WHEN status = 'pendente' THEN valor_comissao ELSE 0 END) as pending_commission,
                    SUM(CASE WHEN status = 'pago' THEN valor_comissao ELSE 0 END) as paid_commission
                FROM comissoes c
                WHERE afiliado_id = ? $dateFilter
            ");
            $stmt->execute($params);
            $commissionStats = $stmt->fetch();
            
            return [
                'clicks' => $clickStats,
                'signups' => $signupStats,
                'deposits' => $depositStats,
                'commissions' => $commissionStats
            ];
            
        } catch (PDOException $e) {
            error_log("Erro ao obter estatísticas: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Limpar dados antigos (manutenção)
     */
    public function cleanup($daysToKeep = 180) {
        try {
            // Remover cliques antigos sem conversão
            $stmt = $this->pdo->prepare("
                DELETE FROM affiliate_clicks 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) 
                AND converteu = 0
            ");
            $stmt->execute([$daysToKeep]);
            
            // Remover callbacks antigos
            $stmt = $this->pdo->prepare("
                DELETE FROM payment_callbacks 
                WHERE processed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Erro na limpeza: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Função helper para inicializar o tracker
 */
function initAffiliateTracker($pdo) {
    return new AffiliateTracker($pdo);
}

/**
 * Função helper para rastrear clique (usar em todas as páginas públicas)
 */
function trackAffiliateClick($pdo) {
    $tracker = new AffiliateTracker($pdo);
    return $tracker->trackClick();
}

/**
 * Função helper para atribuir usuário no cadastro
 */
function attributeUserToAffiliate($pdo, $userId) {
    $tracker = new AffiliateTracker($pdo);
    return $tracker->attributeUser($userId);
}

/**
 * Função helper para processar primeiro depósito
 */
function processAffiliateFirstDeposit($pdo, $userId, $transactionId, $amount) {
    $tracker = new AffiliateTracker($pdo);
    return $tracker->processFirstDeposit($userId, $transactionId, $amount);
}
?>