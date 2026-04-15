<?php
/**
 * API de Gerenciamento de Produtos
 * Endpoint: /api/produtos.php
 */

// Limpar qualquer output anterior
if (ob_get_level()) ob_end_clean();
ob_start();

// Headers primeiro
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once 'config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

$conn = conectarBD();

switch ($metodo) {
    case 'GET':
        try {
            // Verificar se está buscando por código de barras
            if (isset($_GET['codigo']) && !empty($_GET['codigo'])) {
                $codigo = sanitizar($conn, $_GET['codigo']);
                
                $stmt = $conn->prepare("
                    SELECT 
                        id, nome, fornecedor, valor_de_venda, quantidade_em_estoque,
                        valor_compra, codigo_de_barra, NCM, categoria, por_quilo
                    FROM produtos 
                    WHERE usuario_id = ? AND codigo_de_barra = ?
                    LIMIT 1
                ");
                
                if (!$stmt) {
                    throw new Exception("Erro ao preparar consulta: " . $conn->error);
                }
                
                $stmt->bind_param("is", $usuario_id, $codigo);
            } else {
                // Listar todos os produtos
                $stmt = $conn->prepare("
                    SELECT 
                        id, nome, fornecedor, valor_de_venda, quantidade_em_estoque,
                        valor_compra, codigo_de_barra, NCM, categoria, por_quilo,
                        DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
                    FROM produtos 
                    WHERE usuario_id = ?
                    ORDER BY nome ASC
                ");
                
                if (!$stmt) {
                    throw new Exception("Erro ao preparar consulta: " . $conn->error);
                }
                
                $stmt->bind_param("i", $usuario_id);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Erro ao executar consulta: " . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            
            if (isset($_GET['codigo']) && !empty($_GET['codigo'])) {
                // Retornar um único produto
                if ($resultado->num_rows === 0) {
                    $stmt->close();
                    $conn->close();
                    responderErro("Produto não encontrado", 404);
                }
                $produto = $resultado->fetch_assoc();
                $produto['por_quilo'] = (bool)$produto['por_quilo'];
                $stmt->close();
                $conn->close();
                responderSucesso($produto);
            } else {
                // Retornar lista de produtos
                $produtos = [];
                while ($row = $resultado->fetch_assoc()) {
                    $row['por_quilo'] = (bool)$row['por_quilo'];
                    $produtos[] = $row;
                }
                $stmt->close();
                $conn->close();
                responderSucesso($produtos);
            }
        } catch (Exception $e) {
            if (isset($stmt)) $stmt->close();
            if (isset($conn)) $conn->close();
            responderErro("Erro: " . $e->getMessage(), 500);
        }
        break;
        
    case 'POST':
        try {
            // Cadastrar produto
            $dados = obterDadosJSON();
            
            if (empty($dados['nome']) || empty($dados['valor_de_venda'])) {
                responderErro("Nome e valor de venda são obrigatórios");
            }
            
            $nome = sanitizar($conn, $dados['nome']);
            $fornecedor = isset($dados['fornecedor']) ? sanitizar($conn, $dados['fornecedor']) : null;
            $valor_venda = floatval($dados['valor_de_venda']);
            $estoque = isset($dados['quantidade_em_estoque']) ? intval($dados['quantidade_em_estoque']) : 0;
            $valor_compra = isset($dados['valor_compra']) ? floatval($dados['valor_compra']) : null;
            $codigo_barra = isset($dados['codigo_de_barra']) ? sanitizar($conn, $dados['codigo_de_barra']) : null;
            $ncm = isset($dados['NCM']) ? sanitizar($conn, $dados['NCM']) : null;
            $categoria = isset($dados['categoria']) ? sanitizar($conn, $dados['categoria']) : null;
            $por_quilo = isset($dados['por_quilo']) ? (int)(bool)$dados['por_quilo'] : 0;
            
            // Se código de barras não foi informado, gerar automaticamente
            if (empty($codigo_barra)) {
                $conn->begin_transaction();
                
                try {
                    $stmtSeq = $conn->prepare("
                        SELECT ultimo_codigo FROM sequencia_codigos 
                        WHERE usuario_id = ? FOR UPDATE
                    ");
                    $stmtSeq->bind_param("i", $usuario_id);
                    $stmtSeq->execute();
                    $resultSeq = $stmtSeq->get_result();
                    
                    if ($resultSeq->num_rows === 0) {
                        $stmtInsert = $conn->prepare("
                            INSERT INTO sequencia_codigos (usuario_id, ultimo_codigo) 
                            VALUES (?, 1)
                        ");
                        $stmtInsert->bind_param("i", $usuario_id);
                        $stmtInsert->execute();
                        $novo_sequencia = 1;
                    } else {
                        $rowSeq = $resultSeq->fetch_assoc();
                        $novo_sequencia = $rowSeq['ultimo_codigo'] + 1;
                        
                        $stmtUpdate = $conn->prepare("
                            UPDATE sequencia_codigos 
                            SET ultimo_codigo = ? 
                            WHERE usuario_id = ?
                        ");
                        $stmtUpdate->bind_param("ii", $novo_sequencia, $usuario_id);
                        $stmtUpdate->execute();
                    }
                    
                    $codigo_barra = str_pad($novo_sequencia, 13, '0', STR_PAD_LEFT);
                    $conn->commit();
                    $stmtSeq->close();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    throw new Exception("Erro ao gerar código: " . $e->getMessage());
                }
            }
        
            // Verificar se código de barras já existe
            if ($codigo_barra) {
                $stmtCheck = $conn->prepare("
                    SELECT id FROM produtos 
                    WHERE codigo_de_barra = ? AND usuario_id = ?
                ");
                $stmtCheck->bind_param("si", $codigo_barra, $usuario_id);
                $stmtCheck->execute();
                
                if ($stmtCheck->get_result()->num_rows > 0) {
                    $stmtCheck->close();
                    $conn->close();
                    responderErro("Código de barras já cadastrado");
                }
                $stmtCheck->close();
            }
            
            $stmt = $conn->prepare("
                INSERT INTO produtos 
                (usuario_id, nome, fornecedor, valor_de_venda, quantidade_em_estoque, 
                 valor_compra, codigo_de_barra, NCM, categoria, por_quilo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar inserção: " . $conn->error);
            }
            
            $stmt->bind_param("issdiisssi", 
                $usuario_id, $nome, $fornecedor, $valor_venda, $estoque,
                $valor_compra, $codigo_barra, $ncm, $categoria, $por_quilo
            );
            
            if ($stmt->execute()) {
                $produto_id = $stmt->insert_id;
                registrarLog($conn, $usuario_id, 'PRODUTO_CADASTRADO', "Produto: $nome - Código: $codigo_barra");
                $stmt->close();
                $conn->close();
                responderSucesso(['id' => $produto_id, 'codigo_de_barra' => $codigo_barra], "Produto cadastrado com sucesso");
            } else {
                throw new Exception("Erro ao inserir: " . $stmt->error);
            }
        } catch (Exception $e) {
            if (isset($stmt)) $stmt->close();
            if (isset($conn)) $conn->close();
            responderErro("Erro: " . $e->getMessage(), 500);
        }
        break;
        
    case 'PUT':
        try {
            // Atualizar produto
            $dados = obterDadosJSON();
            
            if (empty($dados['id'])) {
                responderErro("ID do produto é obrigatório");
            }
            
            $produto_id = intval($dados['id']);
            $nome = sanitizar($conn, $dados['nome']);
            $fornecedor = isset($dados['fornecedor']) ? sanitizar($conn, $dados['fornecedor']) : null;
            $valor_venda = floatval($dados['valor_de_venda']);
            $estoque = isset($dados['quantidade_em_estoque']) ? intval($dados['quantidade_em_estoque']) : 0;
            $valor_compra = isset($dados['valor_compra']) ? floatval($dados['valor_compra']) : null;
            $codigo_barra = isset($dados['codigo_de_barra']) ? sanitizar($conn, $dados['codigo_de_barra']) : null;
            $ncm = isset($dados['NCM']) ? sanitizar($conn, $dados['NCM']) : null;
            $categoria = isset($dados['categoria']) ? sanitizar($conn, $dados['categoria']) : null;
            $por_quilo = isset($dados['por_quilo']) ? (int)(bool)$dados['por_quilo'] : 0;
            
            // Verificar se código já existe para outro produto
            if ($codigo_barra) {
                $stmtCheck = $conn->prepare("
                    SELECT id FROM produtos 
                    WHERE codigo_de_barra = ? AND usuario_id = ? AND id != ?
                ");
                $stmtCheck->bind_param("sii", $codigo_barra, $usuario_id, $produto_id);
                $stmtCheck->execute();
                
                if ($stmtCheck->get_result()->num_rows > 0) {
                    $stmtCheck->close();
                    $conn->close();
                    responderErro("Código de barras já cadastrado para outro produto");
                }
                $stmtCheck->close();
            }
            
            $stmt = $conn->prepare("
                UPDATE produtos 
                SET nome = ?, fornecedor = ?, valor_de_venda = ?, 
                    quantidade_em_estoque = ?, valor_compra = ?, 
                    codigo_de_barra = ?, NCM = ?, categoria = ?, por_quilo = ?
                WHERE id = ? AND usuario_id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar atualização: " . $conn->error);
            }
            
            $stmt->bind_param("ssdidsssiii", 
                $nome, $fornecedor, $valor_venda, $estoque, $valor_compra,
                $codigo_barra, $ncm, $categoria, $por_quilo, $produto_id, $usuario_id
            );
            
            if ($stmt->execute()) {
                registrarLog($conn, $usuario_id, 'PRODUTO_ATUALIZADO', "Produto ID: $produto_id");
                $stmt->close();
                $conn->close();
                responderSucesso(null, "Produto atualizado com sucesso");
            } else {
                throw new Exception("Erro ao atualizar: " . $stmt->error);
            }
        } catch (Exception $e) {
            if (isset($stmt)) $stmt->close();
            if (isset($conn)) $conn->close();
            responderErro("Erro: " . $e->getMessage(), 500);
        }
        break;
        
    case 'DELETE':
        try {
            // Deletar produto
            $dados = obterDadosJSON();
            
            if (empty($dados['id'])) {
                responderErro("ID do produto é obrigatório");
            }
            
            $produto_id = intval($dados['id']);
            
            $stmt = $conn->prepare("DELETE FROM produtos WHERE id = ? AND usuario_id = ?");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar deleção: " . $conn->error);
            }
            
            $stmt->bind_param("ii", $produto_id, $usuario_id);
            
            if ($stmt->execute()) {
                registrarLog($conn, $usuario_id, 'PRODUTO_REMOVIDO', "Produto ID: $produto_id");
                $stmt->close();
                $conn->close();
                responderSucesso(null, "Produto removido com sucesso");
            } else {
                throw new Exception("Erro ao deletar: " . $stmt->error);
            }
        } catch (Exception $e) {
            if (isset($stmt)) $stmt->close();
            if (isset($conn)) $conn->close();
            responderErro("Erro: " . $e->getMessage(), 500);
        }
        break;
        
    default:
        $conn->close();
        responderErro("Método não suportado", 405);
}
?>