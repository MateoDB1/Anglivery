<?php
/**
 * API de Gerenciamento de Funcionários
 * Endpoint: /api/funcionarios.php
 */

require_once 'config.php';

$conn = conectarBD();
$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

switch ($metodo) {
    case 'GET':
        // Listar funcionários
        $stmt = $conn->prepare("
            SELECT 
                id, 
                nome, 
                cargo, 
                DATE_FORMAT(data_admissao, '%d/%m/%Y') as data_admissao,
                ativo
            FROM funcionarios 
            WHERE usuario_id = ?
            ORDER BY nome
        ");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        $funcionarios = [];
        while ($row = $resultado->fetch_assoc()) {
            $row['ativo'] = (bool)$row['ativo'];
            $funcionarios[] = $row;
        }
        
        $stmt->close();
        $conn->close();
        responderSucesso($funcionarios);
        break;
        
    case 'POST':
        // Criar funcionário
        $dados = obterDadosJSON();
        
        if (empty($dados['nome']) || empty($dados['cargo'])) {
            responderErro("Nome e cargo são obrigatórios");
        }
        
        $nome = sanitizar($conn, $dados['nome']);
        $cargo = sanitizar($conn, $dados['cargo']);
        $data_admissao = date('Y-m-d');
        
        // Inserir funcionário
        $stmt = $conn->prepare("
            INSERT INTO funcionarios (usuario_id, nome, cargo, data_admissao) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isss", $usuario_id, $nome, $cargo, $data_admissao);
        
        if ($stmt->execute()) {
            $funcionario_id = $stmt->insert_id;
            registrarLog($conn, $usuario_id, 'FUNCIONARIO_CADASTRADO', "Funcionário: $nome");
            $stmt->close();
            $conn->close();
            responderSucesso(['id' => $funcionario_id], "Funcionário cadastrado com sucesso");
        } else {
            $erro = $stmt->error;
            $stmt->close();
            $conn->close();
            responderErro("Erro ao cadastrar funcionário: " . $erro, 500);
        }
        break;
        
    case 'PUT':
        // Atualizar funcionário
        $dados = obterDadosJSON();
        
        if (empty($dados['id'])) {
            responderErro("ID do funcionário é obrigatório");
        }
        
        $funcionario_id = intval($dados['id']);
        $nome = sanitizar($conn, $dados['nome']);
        $cargo = sanitizar($conn, $dados['cargo']);
        
        $stmt = $conn->prepare("
            UPDATE funcionarios 
            SET nome = ?, cargo = ?
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->bind_param("ssii", $nome, $cargo, $funcionario_id, $usuario_id);
        
        if ($stmt->execute()) {
            registrarLog($conn, $usuario_id, 'FUNCIONARIO_ATUALIZADO', "Funcionário ID: $funcionario_id");
            $stmt->close();
            $conn->close();
            responderSucesso(null, "Funcionário atualizado com sucesso");
        } else {
            $erro = $stmt->error;
            $stmt->close();
            $conn->close();
            responderErro("Erro ao atualizar funcionário: " . $erro, 500);
        }
        break;
        
    case 'DELETE':
        // Deletar funcionário
        $dados = obterDadosJSON();
        
        if (empty($dados['id'])) {
            responderErro("ID do funcionário é obrigatório");
        }
        
        $funcionario_id = intval($dados['id']);
        
        // Marcar como inativo ao invés de deletar
        $stmt = $conn->prepare("
            UPDATE funcionarios 
            SET ativo = 0
            WHERE id = ? AND usuario_id = ?
        ");
        $stmt->bind_param("ii", $funcionario_id, $usuario_id);
        
        if ($stmt->execute()) {
            registrarLog($conn, $usuario_id, 'FUNCIONARIO_REMOVIDO', "Funcionário ID: $funcionario_id");
            $stmt->close();
            $conn->close();
            responderSucesso(null, "Funcionário removido com sucesso");
        } else {
            $erro = $stmt->error;
            $stmt->close();
            $conn->close();
            responderErro("Erro ao remover funcionário: " . $erro, 500);
        }
        break;
        
    default:
        responderErro("Método não suportado", 405);
}