<?php
/**
 * API de Gerenciamento de Perfil
 * Endpoint: /api/perfil.php
 */

// Limpar buffer
if (ob_get_level()) ob_end_clean();
ob_start();

require_once 'config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

$conn = conectarBD();

switch ($metodo) {
    case 'GET':
        // Obter dados do perfil
        $stmt = $conn->prepare("
            SELECT id, nome, email, DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
            FROM usuarios 
            WHERE id = ?
        ");
        
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        if ($resultado->num_rows === 0) {
            $stmt->close();
            $conn->close();
            responderErro("Usuário não encontrado", 404);
        }
        
        $usuario = $resultado->fetch_assoc();
        $stmt->close();
        $conn->close();
        responderSucesso($usuario);
        break;
        
    case 'PUT':
        // Atualizar perfil
        $dados = obterDadosJSON();
        
        // Atualizar nome e email
        if (isset($dados['nome']) && isset($dados['email'])) {
            $nome = sanitizar($conn, trim($dados['nome']));
            $email = sanitizar($conn, trim($dados['email']));
            
            // Validar email
            if (!validarEmail($email)) {
                $conn->close();
                responderErro("Email inválido", 400);
            }
            
            // Verificar se email já existe para outro usuário
            $stmtCheck = $conn->prepare("
                SELECT id FROM usuarios 
                WHERE email = ? AND id != ?
            ");
            $stmtCheck->bind_param("si", $email, $usuario_id);
            $stmtCheck->execute();
            
            if ($stmtCheck->get_result()->num_rows > 0) {
                $stmtCheck->close();
                $conn->close();
                responderErro("Este email já está em uso por outro usuário", 409);
            }
            $stmtCheck->close();
            
            // Atualizar
            $stmt = $conn->prepare("
                UPDATE usuarios 
                SET nome = ?, email = ?
                WHERE id = ?
            ");
            $stmt->bind_param("ssi", $nome, $email, $usuario_id);
            
            if ($stmt->execute()) {
                registrarLog($conn, $usuario_id, 'PERFIL_ATUALIZADO', "Nome e email atualizados");
                $stmt->close();
                $conn->close();
                responderSucesso(['nome' => $nome, 'email' => $email], "Perfil atualizado com sucesso");
            } else {
                $erro = $stmt->error;
                $stmt->close();
                $conn->close();
                responderErro("Erro ao atualizar perfil: " . $erro, 500);
            }
        }
        
        // Atualizar senha
        if (isset($dados['senha_atual']) && isset($dados['senha_nova'])) {
            $senha_atual = $dados['senha_atual'];
            $senha_nova = $dados['senha_nova'];
            
            // Validar comprimento da nova senha
            if (strlen($senha_nova) < 6) {
                $conn->close();
                responderErro("A nova senha deve ter pelo menos 6 caracteres", 400);
            }
            
            // Verificar senha atual
            $stmtVerif = $conn->prepare("SELECT senha FROM usuarios WHERE id = ?");
            $stmtVerif->bind_param("i", $usuario_id);
            $stmtVerif->execute();
            $resultado = $stmtVerif->get_result();
            
            if ($resultado->num_rows === 0) {
                $stmtVerif->close();
                $conn->close();
                responderErro("Usuário não encontrado", 404);
            }
            
            $usuario = $resultado->fetch_assoc();
            $stmtVerif->close();
            
            if (!password_verify($senha_atual, $usuario['senha'])) {
                $conn->close();
                responderErro("Senha atual incorreta", 401);
            }
            
            // Atualizar senha
            $senha_hash = password_hash($senha_nova, PASSWORD_BCRYPT);
            $stmtUpdate = $conn->prepare("UPDATE usuarios SET senha = ? WHERE id = ?");
            $stmtUpdate->bind_param("si", $senha_hash, $usuario_id);
            
            if ($stmtUpdate->execute()) {
                registrarLog($conn, $usuario_id, 'SENHA_ALTERADA', "Senha alterada pelo usuário");
                $stmtUpdate->close();
                $conn->close();
                responderSucesso(null, "Senha atualizada com sucesso");
            } else {
                $erro = $stmtUpdate->error;
                $stmtUpdate->close();
                $conn->close();
                responderErro("Erro ao atualizar senha: " . $erro, 500);
            }
        }
        break;
        
    case 'DELETE':
        try {
            // Obter dados JSON
            $dados = obterDadosJSON();
            
            // Log para debug
            error_log("DELETE - Usuario ID: $usuario_id");
            error_log("DELETE - Dados recebidos: " . json_encode($dados));
            
            if (empty($dados['senha'])) {
                if (isset($conn)) $conn->close();
                responderErro("Senha é obrigatória para deletar a conta", 400);
            }
            
            $senha = $dados['senha'];
            
            // Verificar se usuário existe e pegar senha
            $stmtVerif = $conn->prepare("SELECT id, senha FROM usuarios WHERE id = ?");
            if (!$stmtVerif) {
                error_log("DELETE - Erro ao preparar statement: " . $conn->error);
                $conn->close();
                responderErro("Erro ao verificar usuário", 500);
            }
            
            $stmtVerif->bind_param("i", $usuario_id);
            
            if (!$stmtVerif->execute()) {
                error_log("DELETE - Erro ao executar query: " . $stmtVerif->error);
                $stmtVerif->close();
                $conn->close();
                responderErro("Erro ao verificar usuário", 500);
            }
            
            $resultado = $stmtVerif->get_result();
            
            if ($resultado->num_rows === 0) {
                error_log("DELETE - Usuário não encontrado: $usuario_id");
                $stmtVerif->close();
                $conn->close();
                responderErro("Usuário não encontrado", 404);
            }
            
            $usuario = $resultado->fetch_assoc();
            $stmtVerif->close();
            
            // Verificar senha
            if (!password_verify($senha, $usuario['senha'])) {
                error_log("DELETE - Senha incorreta para usuário: $usuario_id");
                $conn->close();
                responderErro("Senha incorreta", 401);
            }
            
            error_log("DELETE - Iniciando deleção do usuário: $usuario_id");
            
            // Deletar usuário (CASCADE vai cuidar do resto)
            $stmtDelete = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
            if (!$stmtDelete) {
                error_log("DELETE - Erro ao preparar deleção: " . $conn->error);
                $conn->close();
                responderErro("Erro ao preparar deleção", 500);
            }
            
            $stmtDelete->bind_param("i", $usuario_id);
            
            if ($stmtDelete->execute()) {
                error_log("DELETE - Usuário deletado com sucesso: $usuario_id");
                $stmtDelete->close();
                $conn->close();
                responderSucesso(null, "Conta deletada com sucesso");
            } else {
                $erro = $stmtDelete->error;
                error_log("DELETE - Erro ao executar deleção: $erro");
                $stmtDelete->close();
                $conn->close();
                responderErro("Erro ao deletar conta: " . $erro, 500);
            }
            
        } catch (Exception $e) {
            error_log("DELETE - Exception: " . $e->getMessage());
            if (isset($conn) && $conn->ping()) {
                $conn->close();
            }
            responderErro("Erro ao deletar conta: " . $e->getMessage(), 500);
        }
        break;
        
    default:
        if (isset($conn)) $conn->close();
        responderErro("Método não suportado", 405);
}
?>