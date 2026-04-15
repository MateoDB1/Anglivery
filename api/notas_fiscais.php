<?php
/**
 * API de Gerenciamento de Notas Fiscais
 * Endpoint: /api/notas_fiscais.php
 */

require_once 'config.php';

// Biblioteca para geração de PDF (usando FPDF simplificado)
class NotaFiscalPDF {
    private $venda;
    private $itens;
    private $usuario;
    
    public function __construct($venda, $itens, $usuario) {
        $this->venda = $venda;
        $this->itens = $itens;
        $this->usuario = $usuario;
    }
    
    public function gerarPDF() {
        // Criar conteúdo HTML da nota fiscal
        $html = $this->gerarHTML();
        
        // Converter para base64 para armazenar no banco
        return base64_encode($html);
    }
    
    private function gerarHTML() {
        $numeroNota = str_pad($this->venda['numero_nota'], 9, '0', STR_PAD_LEFT);
        $serie = $this->venda['serie'];
        $dataEmissao = date('d/m/Y H:i:s', strtotime($this->venda['data_venda']));
        
        $html = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='utf-8'>
    <title>Nota Fiscal - {$numeroNota}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .info-section { margin-bottom: 15px; }
        .info-label { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f0f0f0; }
        .total { text-align: right; font-size: 18px; font-weight: bold; margin-top: 20px; }
        .footer { margin-top: 30px; text-align: center; font-size: 12px; border-top: 1px solid #000; padding-top: 10px; }
    </style>
</head>
<body>
    <div class='header'>
        <h1>NOTA FISCAL SIMPLIFICADA</h1>
        <h2>{$this->usuario['nome']}</h2>
        <p>Número: {$numeroNota} | Série: {$serie}</p>
    </div>
    
    <div class='info-section'>
        <p><span class='info-label'>Data de Emissão:</span> {$dataEmissao}</p>
        <p><span class='info-label'>Cliente:</span> " . ($this->venda['cliente_nome'] ?: 'Consumidor Final') . "</p>
        <p><span class='info-label'>Funcionário:</span> " . ($this->venda['funcionario_nome'] ?: 'Não informado') . "</p>
        <p><span class='info-label'>Método de Pagamento:</span> {$this->venda['metodo_pagamento']}</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Descrição</th>
                <th>Qtd</th>
                <th>Valor Unit.</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>";
        
        foreach ($this->itens as $item) {
            $html .= "
            <tr>
                <td>" . ($item['codigo_de_barra'] ?: 'N/A') . "</td>
                <td>{$item['produto_nome']}</td>
                <td>{$item['quantidade']}</td>
                <td>R$ " . number_format($item['valor_unitario'], 2, ',', '.') . "</td>
                <td>R$ " . number_format($item['subtotal'], 2, ',', '.') . "</td>
            </tr>";
        }
        
        $html .= "
        </tbody>
    </table>
    
    <div class='total'>
        Total: R$ " . number_format($this->venda['valor_total'], 2, ',', '.') . "
    </div>";
    
    if ($this->venda['metodo_pagamento'] == 'Dinheiro' && $this->venda['valor_pago']) {
        $html .= "
    <div style='text-align: right; margin-top: 10px;'>
        <p>Valor Pago: R$ " . number_format($this->venda['valor_pago'], 2, ',', '.') . "</p>
        <p>Troco: R$ " . number_format($this->venda['troco'], 2, ',', '.') . "</p>
    </div>";
    }
    
    $html .= "
    <div class='footer'>
        <p>Documento gerado eletronicamente pelo sistema Anglivery</p>
        <p>Emitido em " . date('d/m/Y H:i:s') . "</p>
    </div>
</body>
</html>";
        
        return $html;
    }
}

$conn = conectarBD();
$metodo = $_SERVER['REQUEST_METHOD'];

$usuario_id = isset($_GET['usuario_id']) ? intval($_GET['usuario_id']) : 0;

if ($usuario_id === 0) {
    responderErro("Usuário não identificado", 401);
}

switch ($metodo) {
    case 'GET':
        // Obter nota fiscal por venda_id
        if (!isset($_GET['venda_id'])) {
            responderErro("ID da venda não informado");
        }
        
        $venda_id = intval($_GET['venda_id']);
        
        $stmt = $conn->prepare("
            SELECT nf.*, v.valor_total, v.metodo_pagamento, v.valor_pago, v.troco,
                   v.data_venda, c.nome as cliente_nome, f.nome as funcionario_nome
            FROM notas_fiscais nf
            INNER JOIN vendas v ON nf.venda_id = v.id
            LEFT JOIN clientes c ON v.cliente_id = c.id
            LEFT JOIN funcionarios f ON v.funcionario_id = f.id
            WHERE nf.venda_id = ? AND nf.usuario_id = ?
        ");
        $stmt->bind_param("ii", $venda_id, $usuario_id);
        $stmt->execute();
        $resultado = $stmt->get_result();
        
        if ($resultado->num_rows === 0) {
            $stmt->close();
            $conn->close();
            responderErro("Nota fiscal não encontrada", 404);
        }
        
        $nota = $resultado->fetch_assoc();
        
        // Converter PDF de base64 para enviar
        if ($nota['pdf_content']) {
            $nota['pdf_base64'] = $nota['pdf_content'];
            unset($nota['pdf_content']); // Não enviar o blob direto
        }
        
        $stmt->close();
        $conn->close();
        responderSucesso($nota);
        break;
        
    case 'POST':
        // Gerar nota fiscal para uma venda
        $dados = obterDadosJSON();
        
        if (empty($dados['venda_id'])) {
            responderErro("ID da venda é obrigatório");
        }
        
        $venda_id = intval($dados['venda_id']);
        
        $conn->begin_transaction();
        
        try {
            // Buscar dados da venda
            $stmtVenda = $conn->prepare("
                SELECT v.*, c.nome as cliente_nome, c.cpf as cliente_cpf,
                       f.nome as funcionario_nome, u.nome as empresa_nome
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_id = c.id
                LEFT JOIN funcionarios f ON v.funcionario_id = f.id
                LEFT JOIN usuarios u ON v.usuario_id = u.id
                WHERE v.id = ? AND v.usuario_id = ?
            ");
            $stmtVenda->bind_param("ii", $venda_id, $usuario_id);
            $stmtVenda->execute();
            $resultVenda = $stmtVenda->get_result();
            
            if ($resultVenda->num_rows === 0) {
                throw new Exception("Venda não encontrada");
            }
            
            $venda = $resultVenda->fetch_assoc();
            $stmtVenda->close();
            
            // Buscar itens da venda
            $stmtItens = $conn->prepare("
                SELECT iv.*, p.nome as produto_nome, p.codigo_de_barra
                FROM itens_venda iv
                INNER JOIN produtos p ON iv.produto_id = p.id
                WHERE iv.venda_id = ?
            ");
            $stmtItens->bind_param("i", $venda_id);
            $stmtItens->execute();
            $resultItens = $stmtItens->get_result();
            
            $itens = [];
            while ($row = $resultItens->fetch_assoc()) {
                $itens[] = $row;
            }
            $stmtItens->close();
            
            // Obter ou criar sequência de nota
            $stmtSeq = $conn->prepare("
                SELECT ultimo_numero, serie FROM sequencia_notas 
                WHERE usuario_id = ? FOR UPDATE
            ");
            $stmtSeq->bind_param("i", $usuario_id);
            $stmtSeq->execute();
            $resultSeq = $stmtSeq->get_result();
            
            if ($resultSeq->num_rows === 0) {
                $stmtInsertSeq = $conn->prepare("
                    INSERT INTO sequencia_notas (usuario_id, ultimo_numero, serie) 
                    VALUES (?, 1, '001')
                ");
                $stmtInsertSeq->bind_param("i", $usuario_id);
                $stmtInsertSeq->execute();
                $numero_nota = 1;
                $serie = '001';
            } else {
                $rowSeq = $resultSeq->fetch_assoc();
                $numero_nota = $rowSeq['ultimo_numero'] + 1;
                $serie = $rowSeq['serie'];
                
                $stmtUpdateSeq = $conn->prepare("
                    UPDATE sequencia_notas 
                    SET ultimo_numero = ? 
                    WHERE usuario_id = ?
                ");
                $stmtUpdateSeq->bind_param("ii", $numero_nota, $usuario_id);
                $stmtUpdateSeq->execute();
            }
            $stmtSeq->close();
            
            // Preparar dados para PDF
            $venda['numero_nota'] = $numero_nota;
            $venda['serie'] = $serie;
            
            $usuario = ['nome' => $venda['empresa_nome']];
            
            // Gerar PDF
            $geradorPDF = new NotaFiscalPDF($venda, $itens, $usuario);
            $pdfContent = $geradorPDF->gerarPDF();
            
            // Gerar chave de acesso simplificada (44 dígitos)
            $chave_acesso = date('Ymd') . str_pad($usuario_id, 6, '0', STR_PAD_LEFT) . 
                           str_pad($numero_nota, 9, '0', STR_PAD_LEFT) . 
                           str_pad($serie, 3, '0', STR_PAD_LEFT) . 
                           sprintf("%015d", rand(1, 999999999999999));
            
            // Inserir nota fiscal
            $stmtNota = $conn->prepare("
                INSERT INTO notas_fiscais 
                (venda_id, usuario_id, numero_nota, serie, chave_acesso, pdf_content, valor_total)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $numero_nota_str = str_pad($numero_nota, 9, '0', STR_PAD_LEFT);
            $stmtNota->bind_param("iissssd", 
                $venda_id, $usuario_id, $numero_nota_str, $serie, 
                $chave_acesso, $pdfContent, $venda['valor_total']
            );
            
            if (!$stmtNota->execute()) {
                throw new Exception("Erro ao salvar nota fiscal");
            }
            
            $nota_id = $stmtNota->insert_id;
            $stmtNota->close();
            
            registrarLog($conn, $usuario_id, 'NOTA_FISCAL_GERADA', "Nota: $numero_nota_str - Venda: $venda_id");
            
            $conn->commit();
            $conn->close();
            
            responderSucesso([
                'nota_id' => $nota_id,
                'numero_nota' => $numero_nota_str,
                'serie' => $serie,
                'chave_acesso' => $chave_acesso,
                'pdf_base64' => $pdfContent
            ], "Nota fiscal gerada com sucesso");
            
        } catch (Exception $e) {
            $conn->rollback();
            $conn->close();
            responderErro($e->getMessage(), 500);
        }
        break;
        
    default:
        responderErro("Método não suportado", 405);
}
?>