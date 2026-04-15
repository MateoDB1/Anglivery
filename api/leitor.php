<?php
/**
 * API do Leitor de Código de Barras
 * Endpoint: /api/leitor.php
 * 
 * Nota: Esta API é opcional, pois a busca de produtos por código
 * já está implementada em produtos.php com o parâmetro ?codigo=
 * 
 * Este arquivo pode ser usado para logs específicos de leitura de códigos
 */

require_once 'config.php';

$conn = conectarBD();
$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

if ($metodo === 'GET') {
    // Buscar produto por código de barras
    if (!isset($_GET['codigo']) || empty($_GET['codigo'])) {
        responderErro("Código de barras não informado", 400);
    }
    
    $codigo = sanitizar($conn, $_GET['codigo']);
    
    $stmt = $conn->prepare("
        SELECT 
            id, nome, fornecedor, valor_de_venda, quantidade_em_estoque,
            valor_compra, codigo_de_barra, NCM, categoria, quilo
        FROM produtos 
        WHERE usuario_id = ? AND codigo_de_barra = ?
        LIMIT 1
    ");
    
    $stmt->bind_param("is", $usuario_id, $codigo);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        $stmt->close();
        $conn->close();
        responderErro("Produto não encontrado", 404);
    }
    
    $produto = $resultado->fetch_assoc();
    $produto['quilo'] = (bool)$produto['quilo'];
    
    // Registrar log de leitura
    registrarLog($conn, $usuario_id, 'CODIGO_LIDO', "Código: $codigo - Produto: " . $produto['nome']);
    
    $stmt->close();
    $conn->close();
    
    responderSucesso($produto);
    
} else {
    responderErro("Método não suportado", 405);
}