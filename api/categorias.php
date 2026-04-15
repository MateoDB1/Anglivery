<?php
/**
 * API de Gerenciamento de Categorias
 * Endpoint: /api/categorias.php
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

$conn = conectarBD();
$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

switch ($metodo) {
    case 'GET':
        try {
            $stmt = $conn->prepare("
                SELECT id, nome, DATE_FORMAT(data_cadastro, '%d/%m/%Y') as data_cadastro
                FROM categorias 
                WHERE usuario_id = ?
                ORDER BY nome
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar consulta: " . $conn->error);
            }
            
            $stmt->bind_param("i", $usuario_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Erro ao executar consulta: " . $stmt->error);
            }
            
            $resultado = $stmt->get_result();
            
            $categorias = [];
            while ($row = $resultado->fetch_assoc()) {
                $categorias[] = $row;
            }
            
            $stmt->close();
            $conn->close();
            responderSucesso($categorias);
        } catch (Exception $e) {
            if (isset($stmt)) $stmt->close();
            if (isset($conn)) $conn->close();
            responderErro("Erro: " . $e->getMessage(), 500);
        }
        break;
        
    case 'POST':
        try {
            $dados = obterDadosJSON();
            
            if (empty($dados['nome'])) {
                responderErro("Nome da categoria é obrigatório");
            }
            
            $nome = sanitizar($conn, trim($dados['nome']));
            
            // Verificar se já existe
            $stmtCheck = $conn->prepare("
                SELECT id FROM categorias 
                WHERE nome = ? AND usuario_id = ?
            ");
            $stmtCheck->bind_param("si", $nome, $usuario_id);
            $stmtCheck->execute();
            
            if ($stmtCheck->get_result()->num_rows > 0) {
                $stmtCheck->close();
                $conn->close();
                responderErro("Categoria já existe");
            }
            $stmtCheck->close();
            
            // Inserir categoria
            $stmt = $conn->prepare("
                INSERT INTO categorias (usuario_id, nome) 
                VALUES (?, ?)
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar inserção: " . $conn->error);
            }
            
            $stmt->bind_param("is", $usuario_id, $nome);
            
            if ($stmt->execute()) {
                $categoria_id = $stmt->insert_id;
                registrarLog($conn, $usuario_id, 'CATEGORIA_CADASTRADA', "Categoria: $nome");
                $stmt->close();
                $conn->close();
                responderSucesso(['id' => $categoria_id, 'nome' => $nome], "Categoria cadastrada com sucesso");
            } else {
                throw new Exception("Erro ao inserir: " . $stmt->error);
            }
        } catch (Exception $e) {
            if (isset($stmt)) $stmt->close();
            if (isset($conn)) $conn->close();
            responderErro("Erro: " . $e->getMessage(), 500);
        }
        break;
        
    case 'DELETE':
        try {
            $dados = obterDadosJSON();
            
            if (empty($dados['nome'])) {
                responderErro("Nome da categoria é obrigatório");
            }
            
            $nome = sanitizar($conn, $dados['nome']);
            
            // Verificar se está em uso
            $stmtCheck = $conn->prepare("
                SELECT id FROM produtos 
                WHERE categoria = ? AND usuario_id = ?
            ");
            $stmtCheck->bind_param("si", $nome, $usuario_id);
            $stmtCheck->execute();
            
            if ($stmtCheck->get_result()->num_rows > 0) {
                $stmtCheck->close();
                $conn->close();
                responderErro("Categoria está em uso e não pode ser removida");
            }
            $stmtCheck->close();
            
            // Deletar
            $stmt = $conn->prepare("
                DELETE FROM categorias 
                WHERE nome = ? AND usuario_id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Erro ao preparar deleção: " . $conn->error);
            }
            
            $stmt->bind_param("si", $nome, $usuario_id);
            
            if ($stmt->execute()) {
                registrarLog($conn, $usuario_id, 'CATEGORIA_REMOVIDA', "Categoria: $nome");
                $stmt->close();
                $conn->close();
                responderSucesso(null, "Categoria removida com sucesso");
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
        responderErro("Método não suportado", 405);
}
?>