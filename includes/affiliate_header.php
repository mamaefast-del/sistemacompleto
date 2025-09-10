<?php
/**
 * Header de Rastreamento de Afiliados
 * Incluir no início de todas as páginas públicas
 */

// Verificar se já foi inicializado nesta requisição
if (!defined('AFFILIATE_TRACKER_INITIALIZED')) {
    define('AFFILIATE_TRACKER_INITIALIZED', true);
    
    // Inicializar sessão se não estiver ativa
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Rastrear clique de afiliado se houver parâmetros
    if (!empty($_GET['ref']) || !empty($_GET['codigo']) || !empty($_GET['affiliate']) || 
        !empty($_GET['utm_source']) || !empty($_GET['utm_medium'])) {
        
        try {
            // Verificar se $pdo está disponível
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                // Incluir dependências apenas quando $pdo estiver disponível
                require_once __DIR__ . '/affiliate_tracker.php';
                
                $clickId = trackAffiliateClick($GLOBALS['pdo']);
                if ($clickId) {
                    // Log do clique capturado
                    error_log("Clique de afiliado capturado: ID $clickId");
                }
            } else {
                // Salvar parâmetros na sessão para processar depois
                $_SESSION['pending_affiliate_tracking'] = [
                    'ref' => $_GET['ref'] ?? $_GET['codigo'] ?? $_GET['affiliate'] ?? '',
                    'utm_source' => $_GET['utm_source'] ?? '',
                    'utm_medium' => $_GET['utm_medium'] ?? '',
                    'utm_campaign' => $_GET['utm_campaign'] ?? '',
                    'utm_content' => $_GET['utm_content'] ?? '',
                    'utm_term' => $_GET['utm_term'] ?? '',
                    'url' => $_SERVER['REQUEST_URI'] ?? '',
                    'timestamp' => time()
                ];
            }
        } catch (Exception $e) {
            error_log("Erro ao rastrear clique de afiliado: " . $e->getMessage());
        }
    }
}

/**
 * Função para processar rastreamento pendente
 * Chamar após $pdo estar disponível
 */
function processPendingAffiliateTracking($pdo) {
    if (isset($_SESSION['pending_affiliate_tracking'])) {
        $data = $_SESSION['pending_affiliate_tracking'];
        
        // Verificar se não é muito antigo (máximo 5 minutos)
        if ((time() - $data['timestamp']) <= 300) {
            try {
                // Simular parâmetros GET
                $originalGet = $_GET;
                $_GET['ref'] = $data['ref'];
                $_GET['utm_source'] = $data['utm_source'];
                $_GET['utm_medium'] = $data['utm_medium'];
                $_GET['utm_campaign'] = $data['utm_campaign'];
                $_GET['utm_content'] = $data['utm_content'];
                $_GET['utm_term'] = $data['utm_term'];
                
                require_once __DIR__ . '/affiliate_tracker.php';
                $clickId = trackAffiliateClick($pdo);
                
                if ($clickId) {
                    error_log("Clique de afiliado processado (pendente): ID $clickId");
                }
                
                // Restaurar $_GET original
                $_GET = $originalGet;
                
            } catch (Exception $e) {
                error_log("Erro ao processar rastreamento pendente: " . $e->getMessage());
            }
        }
        
        // Limpar dados pendentes
        unset($_SESSION['pending_affiliate_tracking']);
    }
}
?>