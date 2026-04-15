<?php
/**
 * API de Gerenciamento de Clientes
 * Endpoint: /api/clientes.php
 */

require_once 'config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

$conn = conectarBD();

switch ($metodo) {
    case 'GET':
        // Listar clientes
        $stmt = $conn->prepare("
            SELECT 
                id,
                nome,
                cpf,
                email,
                telefone,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
            FROM clientes 
            WHERE usuario_id = ?
            ORDER BY nome ASC
        ");
        
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        $clientes = [];
        while ($row = $resultado->fetch_assoc()) {
            $clientes[] = $row;
        }
        
        $stmt->close();
        $conn->close();
        responderSucesso($clientes);
        break;
        
    case 'POST':
        // Cadastrar cliente
        $dados = obterDadosJSON();
        
        if (empty($dados['nome']) || empty($dados['cpf'])) {
            responderErro("Nome e CPF são obrigatórios");
        }
        
        $nome = sanitizar($conn, $dados['nome']);
        $cpf = sanitizar($conn, $dados['cpf']);
        $email = isset($dados['email']) ? sanitizar($conn, $dados['email']) : null;
        $telefone = isset($dados['telefone']) ? sanitizar($conn, $dados['telefone']) : null;
        
        // Verificar se CPF já existe para este usuário
        $stmtCheck = $conn->prepare("SELECT id FROM clientes WHERE cpf = ? AND usuario_id = ?");
        $stmtCheck->bind_param("si", $cpf, $usuario_id);
        $stmtCheck->execute();
        
        if ($stmtCheck->get_result()->num_rows > 0) {
            $stmtCheck->close();
            $conn->close();
            responderErro("CPF já cadastrado");
        }
        $stmtCheck->close();
        
        $stmt = $conn->prepare("
            INSERT INTO clientes (usuario_id, nome, cpf, email, telefone)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("issss", $usuario_id, $nome, $cpf, $email, $telefone);
        
        if ($stmt->execute()) {
            $cliente_id = $stmt->insert_id;
            registrarLog($conn, $usuario_id, 'CLIENTE_CADASTRADO', "Cliente: $nome");
            $stmt->close();
            $conn->close();
            responderSucesso(['id' => $cliente_id], "Cliente cadastrado com sucesso");
        } else {
            $erro = $stmt->error;
            $stmt->close();
            $conn->close();
            responderErro("Erro ao cadastrar cliente: " . $erro, 500);
        }
        break;
        
    case 'PUT':
        // Atualizar cliente
        $dados = obterDadosJSON();
        
        if (empty($dados['id'])) {
            responderErro("ID do cliente é obrigatório");
        }
        
        $cliente_id = intval($dados['id']);
        $nome = sanitizar($conn, $dados['nome']);
        $cpf = sanitizar($conn, $dados['cpf']);
        $email = isset($dados['email']) ? sanitizar($conn, $dados['email']) : null;
        $telefone = isset($dados['telefone']) ? sanitizar($conn, $dados['telefone']) : null;
        
        // Verificar se CPF já existe para outro cliente
        $stmtCheck = $conn->prepare("
            SELECT id FROM clientes 
            WHERE cpf = ? AND usuario_id = ? AND id != ?
        ");
        $stmtCheck->bind_param("sii", $cpf, $usuario_id, $cliente_id);
        $stmtCheck->execute();
        
        if ($stmtCheck->get_result()->num_rows > 0) {
            $stmtCheck->close();
            $conn->close();
            responderErro("CPF já cadastrado para outro cliente");
        }
        $stmtCheck->close();
        
        $stmt = $conn->prepare("
            UPDATE clientes 
            SET nome = ?, cpf = ?, email = ?, telefone = ?
            WHERE id = ? AND usuario_id = ?
        ");
        
        $stmt->bind_param("ssssii", $nome, $cpf, $email, $telefone, $cliente_id, $usuario_id);
        
        if ($stmt->execute()) {
            registrarLog($conn, $usuario_id, 'CLIENTE_ATUALIZADO', "Cliente ID: $cliente_id");
            $stmt->close();
            $conn->close();
            responderSucesso(null, "Cliente atualizado com sucesso");
        } else {
            $erro = $stmt->error;
            $stmt->close();
            $conn->close();
            responderErro("Erro ao atualizar cliente: " . $erro, 500);
        }
        break;
        
    case 'DELETE':
        // Deletar cliente
        $dados = obterDadosJSON();
        
        if (empty($dados['id'])) {
            responderErro("ID do cliente é obrigatório");
        }
        
        $cliente_id = intval($dados['id']);
        
        $stmt = $conn->prepare("DELETE FROM clientes WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param("ii", $cliente_id, $usuario_id);
        
        if ($stmt->execute()) {
            registrarLog($conn, $usuario_id, 'CLIENTE_REMOVIDO', "Cliente ID: $cliente_id");
            $stmt->close();
            $conn->close();
            responderSucesso(null, "Cliente removido com sucesso");
        } else {
            $erro = $stmt->error;
            $stmt->close();
            $conn->close();
            responderErro("Erro ao remover cliente: " . $erro, 500);
        }
        break;
        
    default:
        $conn->close();
        responderErro("Método não suportado", 405);
}