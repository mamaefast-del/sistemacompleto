<?php
session_start();
header('Content-Type: application/json');
error_log('get_package_products.php chamado');

// Verificar se é admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    error_log('Acesso negado - não é admin');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rawInput = file_get_contents('php://input');
        error_log('Raw input recebido: ' . $rawInput);
        
        $input = json_decode($rawInput, true);
        error_log('Input recebido: ' . print_r($input, true));
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erro ao decodificar JSON: ' . json_last_error_msg());
        }
        
        $packageId = intval($input['packageId'] ?? 0);
        error_log('Package ID processado: ' . $packageId);
        
        if ($packageId <= 0) {
            error_log('ID do pacote inválido: ' . $packageId);
            throw new Exception('ID do pacote inválido: ' . $packageId . ' (original: ' . ($input['packageId'] ?? 'não definido') . ')');
        }
        
        // Buscar produtos da caixa
        $stmt = $pdo->prepare("SELECT premios_json FROM raspadinhas_config WHERE id = ?");
        $stmt->execute([$packageId]);
        $package = $stmt->fetch();
        
        error_log('Package encontrado: ' . print_r($package, true));
        
        if (!$package) {
            error_log('Pacote não encontrado para ID: ' . $packageId);
            throw new Exception('Pacote não encontrado');
        }
        
        // Decodificar JSON dos prêmios
        $products = json_decode($package['premios_json'], true);
        if (!is_array($products)) {
            $products = [];
        }
        
        error_log('Produtos decodificados: ' . print_r($products, true));
        
        // Verificar se é o formato antigo {valor: chance} e converter se necessário
        if (!empty($products) && !isset($products[0])) {
            // Formato antigo - converter para novo formato
            $convertedProducts = [];
            foreach ($products as $valor => $chance) {
                $convertedProducts[] = [
                    'nome' => $valor == 0 ? 'Não Ganhou' : "Prêmio R$ " . number_format($valor, 2, ',', '.'),
                    'valor' => floatval($valor),
                    'chance' => floatval($chance),
                    'imagem' => "images/caixa/{$valor}.webp"
                ];
            }
            $products = $convertedProducts;
            error_log('Produtos convertidos para novo formato: ' . print_r($products, true));
        }
        
        echo json_encode([
            'success' => true,
            'products' => $products
        ]);
        
    } catch (Exception $e) {
        error_log('Erro em get_package_products: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    error_log('Método não permitido: ' . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}
?>