<?php
session_start();
header('Content-Type: application/json');

// Verificar se é admin
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}

try {
    $type = $_POST['type'] ?? '';
    
    // Validar tipo
    $allowedTypes = ['logo', 'banner', 'menu'];
    if (!in_array($type, $allowedTypes)) {
        throw new Exception('Tipo de imagem inválido');
    }
    
    // Verificar se arquivo foi enviado
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erro no upload do arquivo');
    }
    
    $file = $_FILES['image'];
    
    // Validar tipo de arquivo
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        throw new Exception('Tipo de arquivo não permitido. Use JPG, PNG, GIF ou WebP.');
    }
    
    // Validar tamanho (máximo 5MB)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('Arquivo muito grande. Máximo 5MB.');
    }
    
    // Criar diretório se não existir
    $uploadDir = 'images/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Definir nome do arquivo baseado no tipo
    $fileName = '';
    switch ($type) {
        case 'logo':
            $fileName = 'logo.png';
            break;
        case 'banner':
            $fileName = 'banner.png';
            break;
        case 'menu':
            $fileName = 'menu-bg.png';
            break;
    }
    
    $targetPath = $uploadDir . $fileName;
    
    // Fazer backup do arquivo anterior se existir
    if (file_exists($targetPath)) {
        $backupPath = $uploadDir . 'backup_' . $fileName;
        copy($targetPath, $backupPath);
    }
    
    // Processar imagem se necessário (redimensionar, otimizar)
    if (extension_loaded('gd')) {
        $processedImage = processImage($file['tmp_name'], $mimeType, $type);
        if ($processedImage) {
            imagepng($processedImage, $targetPath, 9);
            imagedestroy($processedImage);
        } else {
            // Fallback: mover arquivo original
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('Erro ao salvar arquivo');
            }
        }
    } else {
        // GD não disponível: mover arquivo original
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new Exception('Erro ao salvar arquivo');
        }
    }
    
    // Definir mensagem de sucesso
    $messages = [
        'logo' => 'Logo atualizado com sucesso!',
        'banner' => 'Banner atualizado com sucesso!',
        'menu' => 'Fundo do menu atualizado com sucesso!'
    ];
    
    echo json_encode([
        'success' => true,
        'message' => $messages[$type],
        'url' => $targetPath,
        'type' => $type
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function processImage($sourcePath, $mimeType, $type) {
    // Dimensões recomendadas por tipo
    $dimensions = [
        'logo' => ['width' => 200, 'height' => 60],
        'banner' => ['width' => 1200, 'height' => 400],
        'menu' => ['width' => 800, 'height' => 600]
    ];
    
    $targetWidth = $dimensions[$type]['width'];
    $targetHeight = $dimensions[$type]['height'];
    
    // Criar imagem source baseada no tipo MIME
    $sourceImage = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $sourceImage = imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$sourceImage) {
        return false;
    }
    
    // Obter dimensões originais
    $originalWidth = imagesx($sourceImage);
    $originalHeight = imagesy($sourceImage);
    
    // Calcular dimensões mantendo proporção
    $ratio = min($targetWidth / $originalWidth, $targetHeight / $originalHeight);
    $newWidth = intval($originalWidth * $ratio);
    $newHeight = intval($originalHeight * $ratio);
    
    // Criar imagem redimensionada
    $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preservar transparência para PNG
    imagealphablending($resizedImage, false);
    imagesavealpha($resizedImage, true);
    $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
    imagefill($resizedImage, 0, 0, $transparent);
    
    // Redimensionar
    imagecopyresampled(
        $resizedImage, $sourceImage,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $originalWidth, $originalHeight
    );
    
    // Limpar memória
    imagedestroy($sourceImage);
    
    return $resizedImage;
}
?>