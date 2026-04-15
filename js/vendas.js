const API_URL = 'http://localhost/anglivery/api';

const sidebar = document.getElementById('sidebar');
const toggleBtn = document.getElementById('sidebar-toggle');

let usuarioLogado = null;
try {
    usuarioLogado = JSON.parse(localStorage.getItem('usuarioLogado'));
    if (!usuarioLogado || !usuarioLogado.id) {
        window.location.href = 'login.html';
    }
} catch (e) {
    window.location.href = 'login.html';
}

toggleBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    sidebar.classList.toggle('sidebar--fechada');
    const icon = toggleBtn.querySelector('.material-icons');
    const mobileMenu = document.querySelector('.mobile-menu');
    if (sidebar.classList.contains('sidebar--fechada')) {
        icon.textContent = 'menu';
        mobileMenu.classList.remove('mobile-menu--deslocada');
    } else {
        icon.textContent = 'close';
        mobileMenu.classList.add('mobile-menu--deslocada');
    }
});

// Logout ao clicar em Sair
document.querySelectorAll('a[href*="login.html"]').forEach(link => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        localStorage.removeItem('usuarioLogado');
        localStorage.removeItem('lembrarEmail');
        window.location.href = 'login.html';
    });
});

let vendas = [];
let ordemAtual = { coluna: '', direcao: 'asc' };
let paginaAtual = 1;
let itensPorPagina = 25;
let vendasFiltradas = [];
let notaFiscalTemp = { vendaId: null, event: null };

async function carregarVendas() {
    try {
        console.log('========== CARREGANDO VENDAS ==========');
        const tbody = document.getElementById('corpoTabela');
        
        tbody.innerHTML = `
            <tr id="loadingRow">
                <td colspan="7" class="vendas-empty-state">
                    <div class="vendas-empty-state-content">
                        <span class="material-icons">hourglass_empty</span>
                        <p>Carregando vendas...</p>
                    </div>
                </td>
            </tr>
        `;
        
        const response = await fetch(`${API_URL}/vendas.php?usuario_id=${usuarioLogado.id}`);
        const result = await response.json();
        
        console.log('Resposta completa da API:', result);
        
        if (result.success) {
            vendas = result.data || [];
            console.log('Vendas carregadas:', vendas.length);
            
            if (vendas.length > 0) {
                console.log('Exemplo de venda:', vendas[0]);
            }
            
            renderizarTabela();
            atualizarResumo();
        } else {
            console.error('Erro na API:', result.message);
            renderizarErro('Erro ao carregar vendas: ' + result.message);
        }
        
        console.log('========== FIM DO CARREGAMENTO ==========');
    } catch (error) {
        console.error('Erro ao carregar vendas:', error);
        renderizarErro('Erro de conexão. Verifique sua internet.');
    }
}

function renderizarTabela() {
    const tbody = document.getElementById('corpoTabela');
    tbody.innerHTML = '';
    
    vendasFiltradas = vendas;
    
    console.log('Renderizando', vendasFiltradas.length, 'vendas - Página', paginaAtual);
    
    if (vendasFiltradas.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 3rem 1rem;">
                    <div class="vendas-empty-state-content">
                        <span class="material-icons" style="font-size: 3rem; color: #d1d5db; margin-bottom: 1rem;">shopping_cart</span>
                        <p style="color: #6b7280; font-size: 1.1rem; margin-bottom: 0.5rem;">Nenhuma venda registrada</p>
                        <small style="color: #9ca3af;">Clique em "Atualizar" para buscar vendas</small>
                    </div>
                </td>
            </tr>
        `;
        atualizarPaginacao();
        return;
    }
    
    const totalPaginas = Math.ceil(vendasFiltradas.length / itensPorPagina);
    
    if (paginaAtual > totalPaginas) {
        paginaAtual = totalPaginas;
    }
    
    const inicio = (paginaAtual - 1) * itensPorPagina;
    const fim = inicio + itensPorPagina;
    const vendasPagina = vendasFiltradas.slice(inicio, fim);
    
    console.log(`Mostrando vendas de ${inicio + 1} até ${Math.min(fim, vendasFiltradas.length)} de ${vendasFiltradas.length}`);
    
    vendasPagina.forEach((venda) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${formatarData(venda.data)}</td>
            <td>${venda.cliente || 'Cliente não informado'}</td>
            <td>${venda.funcionario || 'Funcionário não informado'}</td>
            <td class="vendas-valor">R$ ${formatarValor(venda.valor)}</td>
            <td>
                <span class="vendas-pagamento-icon">
                    ${getIconePagamento(venda.metodo)}
                    ${venda.metodo || 'Não informado'}
                </span>
            </td>
            <td>${venda.troco > 0 ? 'R$ ' + formatarValor(venda.troco) : '-'}</td>
            <td style="text-align: center;">
                <button class="btn btn-icon vendas-btn-nota" 
                        onclick="baixarNotaFiscal(${venda.id}, event)" 
                        title="Imprimir Nota Fiscal">
                    <span class="material-icons">receipt</span>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
    
    atualizarPaginacao();
}

function atualizarPaginacao() {
    const totalPaginas = Math.ceil(vendasFiltradas.length / itensPorPagina);
    
    let paginacaoDiv = document.getElementById('paginacao');
    if (!paginacaoDiv) {
        paginacaoDiv = document.createElement('div');
        paginacaoDiv.id = 'paginacao';
        paginacaoDiv.className = 'vendas-paginacao';
        
        const tableWrapper = document.querySelector('.vendas-table-wrapper');
        if (tableWrapper && tableWrapper.parentNode) {
            tableWrapper.parentNode.insertBefore(paginacaoDiv, tableWrapper.nextSibling);
        }
    }
    
    if (vendasFiltradas.length === 0) {
        paginacaoDiv.innerHTML = '';
        return;
    }
    
    if (totalPaginas <= 1) {
        paginacaoDiv.innerHTML = `
            <div class="vendas-paginacao-info">
                Mostrando ${vendasFiltradas.length} ${vendasFiltradas.length === 1 ? 'venda' : 'vendas'}
            </div>
            <div class="vendas-paginacao-controls">
                <label class="vendas-paginacao-label">Itens por página:</label>
                <select onchange="mudarItensPorPagina(this.value)" class="vendas-paginacao-select">
                    <option value="10" ${itensPorPagina === 10 ? 'selected' : ''}>10</option>
                    <option value="25" ${itensPorPagina === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${itensPorPagina === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${itensPorPagina === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        `;
        return;
    }
    
    const inicio = (paginaAtual - 1) * itensPorPagina + 1;
    const fim = Math.min(paginaAtual * itensPorPagina, vendasFiltradas.length);
    
    if (paginaAtual > totalPaginas) {
        paginaAtual = totalPaginas;
    }
    
    paginacaoDiv.innerHTML = `
        <div>
            <div class="vendas-paginacao-info">
                Mostrando ${inicio} até ${fim} de ${vendasFiltradas.length} vendas
            </div>
            
            <div class="vendas-paginacao-controls">
                <label class="vendas-paginacao-label">Itens por página:</label>
                <select onchange="mudarItensPorPagina(this.value)" class="vendas-paginacao-select">
                    <option value="10" ${itensPorPagina === 10 ? 'selected' : ''}>10</option>
                    <option value="25" ${itensPorPagina === 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${itensPorPagina === 50 ? 'selected' : ''}>50</option>
                    <option value="100" ${itensPorPagina === 100 ? 'selected' : ''}>100</option>
                </select>
            </div>
        </div>
        
        <div class="vendas-paginacao-buttons">
            <button onclick="irParaPagina(1)" 
                    ${paginaAtual === 1 ? 'disabled' : ''}
                    class="vendas-btn-paginacao"
                    title="Primeira página">
                <span class="material-icons">first_page</span>
            </button>
            
            <button onclick="irParaPagina(${paginaAtual - 1})" 
                    ${paginaAtual === 1 ? 'disabled' : ''}
                    class="vendas-btn-paginacao"
                    title="Página anterior">
                <span class="material-icons">chevron_left</span>
            </button>
            
            ${gerarBotoesPaginas(paginaAtual, totalPaginas)}
            
            <button onclick="irParaPagina(${paginaAtual + 1})" 
                    ${paginaAtual === totalPaginas ? 'disabled' : ''}
                    class="vendas-btn-paginacao"
                    title="Próxima página">
                <span class="material-icons">chevron_right</span>
            </button>
            
            <button onclick="irParaPagina(${totalPaginas})" 
                    ${paginaAtual === totalPaginas ? 'disabled' : ''}
                    class="vendas-btn-paginacao"
                    title="Última página">
                <span class="material-icons">last_page</span>
            </button>
        </div>
    `;
}

function gerarBotoesPaginas(atual, total) {
    let html = '';
    let inicio = Math.max(1, atual - 2);
    let fim = Math.min(total, atual + 2);
    
    if (fim - inicio < 4) {
        if (atual <= 3) {
            fim = Math.min(5, total);
        } else {
            inicio = Math.max(1, total - 4);
        }
    }
    
    for (let i = inicio; i <= fim; i++) {
        const ativo = i === atual ? 'active' : '';
        html += `
            <button onclick="irParaPagina(${i})" 
                    class="vendas-btn-paginacao ${ativo}">
                ${i}
            </button>
        `;
    }
    
    return html;
}

function irParaPagina(pagina) {
    const totalPaginas = Math.ceil(vendasFiltradas.length / itensPorPagina);
    
    if (pagina < 1 || pagina > totalPaginas) return;
    
    paginaAtual = pagina;
    renderizarTabela();
    
    const tableCard = document.querySelector('.vendas-card');
    if (tableCard) {
        tableCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function mudarItensPorPagina(novosItens) {
    itensPorPagina = parseInt(novosItens);
    paginaAtual = 1;
    renderizarTabela();
}

function renderizarErro(mensagem) {
    const tbody = document.getElementById('corpoTabela');
    tbody.innerHTML = `
        <tr>
            <td colspan="7" style="text-align: center; padding: 3rem 1rem;">
                <div class="vendas-empty-state-content">
                    <span class="material-icons" style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem;">error</span>
                    <p style="color: #ef4444; font-size: 1.1rem; margin-bottom: 1rem;">${mensagem}</p>
                    <button class="btn" onclick="carregarVendas()">
                        <span class="material-icons">refresh</span> Tentar novamente
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function formatarData(dataString) {
    if (!dataString) return 'Data inválida';
    
    try {
        if (dataString.match(/^\d{2}\/\d{2}\/\d{4}/)) {
            return dataString;
        }
        
        const data = new Date(dataString);
        if (isNaN(data.getTime())) {
            return dataString;
        }
        
        const dia = String(data.getDate()).padStart(2, '0');
        const mes = String(data.getMonth() + 1).padStart(2, '0');
        const ano = data.getFullYear();
        const hora = String(data.getHours()).padStart(2, '0');
        const minuto = String(data.getMinutes()).padStart(2, '0');
        
        return `${dia}/${mes}/${ano} ${hora}:${minuto}`;
    } catch (e) {
        console.error('Erro ao formatar data:', dataString, e);
        return dataString;
    }
}

function formatarValor(valor) {
    const num = parseFloat(valor);
    if (isNaN(num)) return '0.00';
    return num.toFixed(2).replace('.', ',');
}

function getIconePagamento(metodo) {
    const icones = {
        'Dinheiro': 'payments',
        'Cartão': 'credit_card',
        'PIX': 'qr_code',
        'Débito': 'account_balance',
        'Crédito': 'credit_score'
    };
    
    const icone = icones[metodo] || 'attach_money';
    return `<span class="material-icons">${icone}</span>`;
}

function ordenarTabela(coluna) {
    if (ordemAtual.coluna === coluna) {
        ordemAtual.direcao = ordemAtual.direcao === 'asc' ? 'desc' : 'asc';
    } else {
        ordemAtual.coluna = coluna;
        ordemAtual.direcao = 'asc';
    }

    vendasFiltradas.sort((a, b) => {
        let valorA = a[coluna] || '';
        let valorB = b[coluna] || '';
        
        if (coluna === 'valor' || coluna === 'troco') {
            valorA = parseFloat(valorA) || 0;
            valorB = parseFloat(valorB) || 0;
        } else if (typeof valorA === 'string') {
            valorA = valorA.toLowerCase();
            valorB = valorB.toLowerCase();
        }

        if (ordemAtual.direcao === 'asc') {
            return valorA > valorB ? 1 : -1;
        } else {
            return valorA < valorB ? 1 : -1;
        }
    });

    renderizarTabela();
}

async function baixarNotaFiscal(vendaId, event) {
    try {
        console.log('Baixando nota fiscal da venda:', vendaId);
        
        const btnElement = event ? event.target.closest('button') : null;
        let originalHTML = '';
        
        if (btnElement) {
            originalHTML = btnElement.innerHTML;
            btnElement.disabled = true;
            btnElement.innerHTML = '<span class="material-icons vendas-loading">hourglass_empty</span>';
        }
        
        const response = await fetch(`${API_URL}/notas_fiscais.php?usuario_id=${usuarioLogado.id}&venda_id=${vendaId}`);
        const result = await response.json();
        
        if (result.success) {
            const nota = result.data;
            const htmlContent = atob(nota.pdf_base64);
            
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            
            document.body.appendChild(iframe);
            
            const iframeDoc = iframe.contentWindow.document;
            iframeDoc.open();
            iframeDoc.write(htmlContent);
            iframeDoc.close();
            
            iframe.onload = () => {
                setTimeout(() => {
                    try {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                        
                        setTimeout(() => {
                            document.body.removeChild(iframe);
                        }, 1000);
                    } catch (e) {
                        console.error('Erro ao imprimir:', e);
                        document.body.removeChild(iframe);
                    }
                }, 500);
            };
            
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = originalHTML;
            }
        } else {
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = originalHTML;
            }
            
            notaFiscalTemp.vendaId = vendaId;
            notaFiscalTemp.event = event;
            
            document.getElementById('modalAviso1').style.display = 'block';
        }
    } catch (error) {
        console.error('Erro ao baixar nota:', error);
        document.getElementById('modalAviso3').style.display = 'block';
        
        if (event) {
            const btnElement = event.target.closest('button');
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = '<span class="material-icons">receipt</span>';
            }
        }
    }
}

async function gerarNotaFiscalVenda(vendaId, event) {
    try {
        console.log('Gerando nota fiscal para venda:', vendaId);
        
        const response = await fetch(`${API_URL}/notas_fiscais.php?usuario_id=${usuarioLogado.id}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ venda_id: vendaId })
        });
        
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('modalAviso4').style.display = 'block';
            
            const nota = result.data;
            const htmlContent = atob(nota.pdf_base64);
            
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            
            document.body.appendChild(iframe);
            
            const iframeDoc = iframe.contentWindow.document;
            iframeDoc.open();
            iframeDoc.write(htmlContent);
            iframeDoc.close();
            
            iframe.onload = () => {
                setTimeout(() => {
                    try {
                        iframe.contentWindow.focus();
                        iframe.contentWindow.print();
                        setTimeout(() => {
                            document.body.removeChild(iframe);
                        }, 1000);
                    } catch (e) {
                        console.error('Erro ao imprimir:', e);
                        document.body.removeChild(iframe);
                    }
                }, 500);
            };
        } else {
            console.error('Erro ao gerar nota: ' + result.message);
            document.getElementById('modalAviso5').style.display = 'block';
        }
    } catch (error) {
        console.error('Erro ao gerar nota:', error);
        document.getElementById('modalAviso5').style.display = 'block';
    }
}

function confirmarGeracaoNota() {
    fecharModal('modalAviso1');
    
    if (notaFiscalTemp.vendaId) {
        gerarNotaFiscalVenda(notaFiscalTemp.vendaId, notaFiscalTemp.event);
        notaFiscalTemp = { vendaId: null, event: null };
    }
}

async function filtrarVendas() {
    const dataInicial = document.getElementById('dataInicial').value;
    const dataFinal = document.getElementById('dataFinal').value;
    const cliente = document.getElementById('filtroCliente').value;
    const funcionario = document.getElementById('filtroFuncionario').value;

    let url = `${API_URL}/vendas.php?usuario_id=${usuarioLogado.id}`;
    
    if (dataInicial) url += `&data_inicial=${dataInicial}`;
    if (dataFinal) url += `&data_final=${dataFinal}`;
    if (cliente) url += `&cliente_id=${cliente}`;
    if (funcionario) url += `&funcionario_id=${funcionario}`;

    try {
        console.log('Filtrando vendas:', url);
        const response = await fetch(url);
        const result = await response.json();
        
        if (result.success) {
            vendas = result.data || [];
            console.log('Vendas filtradas:', vendas.length);
            
            paginaAtual = 1;
            
            renderizarTabela();
            atualizarResumo();
        } else {
            console.error('Erro ao filtrar:', result.message);
        }
    } catch (error) {
        console.error('Erro:', error);
        document.getElementById('modalAviso6').style.display = 'block';
    }
}

async function carregarFiltros() {
    try {
        const responseClientes = await fetch(`${API_URL}/clientes.php?usuario_id=${usuarioLogado.id}`);
        const resultClientes = await responseClientes.json();
        
        if (resultClientes.success) {
            const selectCliente = document.getElementById('filtroCliente');
            selectCliente.innerHTML = '<option value="">Todos</option>';
            
            const clientes = resultClientes.data || [];
            clientes.forEach(cliente => {
                const option = document.createElement('option');
                option.value = cliente.id;
                option.textContent = cliente.nome;
                selectCliente.appendChild(option);
            });
        }
        
        const responseFuncionarios = await fetch(`${API_URL}/funcionarios.php?usuario_id=${usuarioLogado.id}`);
        const resultFuncionarios = await responseFuncionarios.json();
        
        if (resultFuncionarios.success) {
            const selectFuncionario = document.getElementById('filtroFuncionario');
            selectFuncionario.innerHTML = '<option value="">Todos</option>';
            
            const funcionarios = resultFuncionarios.data || [];
            funcionarios.forEach(func => {
                if (func.ativo) {
                    const option = document.createElement('option');
                    option.value = func.id;
                    option.textContent = func.nome;
                    selectFuncionario.appendChild(option);
                }
            });
        }
    } catch (error) {
        console.error('Erro ao carregar filtros:', error);
    }
}

function atualizarResumo() {
    const hoje = new Date();
    const diaHoje = hoje.getDate();
    const mesAtual = hoje.getMonth();
    const anoAtual = hoje.getFullYear();
    
    console.log('========== ATUALIZANDO RESUMO ==========');
    console.log('Data de hoje:', `${diaHoje}/${mesAtual + 1}/${anoAtual}`);
    console.log('Total de vendas:', vendas.length);
    
    const vendasHoje = vendas.filter(v => {
        if (!v.data) return false;
        
        try {
            const partes = v.data.split(' ')[0].split('/');
            if (partes.length !== 3) return false;
            
            const diaVenda = parseInt(partes[0]);
            const mesVenda = parseInt(partes[1]) - 1;
            const anoVenda = parseInt(partes[2]);
            
            return diaVenda === diaHoje && mesVenda === mesAtual && anoVenda === anoAtual;
        } catch (e) {
            return false;
        }
    });
    
    const vendasMes = vendas.filter(v => {
        if (!v.data) return false;
        
        try {
            const partes = v.data.split(' ')[0].split('/');
            if (partes.length !== 3) return false;
            
            const mesVenda = parseInt(partes[1]) - 1;
            const anoVenda = parseInt(partes[2]);
            
            return mesVenda === mesAtual && anoVenda === anoAtual;
        } catch (e) {
            return false;
        }
    });

    console.log('Vendas hoje:', vendasHoje.length);
    console.log('Vendas mês:', vendasMes.length);

    const ganhosHoje = vendasHoje.reduce((sum, v) => sum + (parseFloat(v.valor) || 0), 0);
    const ganhosMes = vendasMes.reduce((sum, v) => sum + (parseFloat(v.valor) || 0), 0);
    
    document.getElementById('ganhosDiaValor').textContent = `R$ ${ganhosHoje.toFixed(2).replace('.', ',')}`;
    document.getElementById('ganhosMesValor').textContent = `R$ ${ganhosMes.toFixed(2).replace('.', ',')}`;

    const produtosHoje = vendasHoje.reduce((sum, v) => sum + (parseInt(v.quantidade_total) || 0), 0);
    const produtosMes = vendasMes.reduce((sum, v) => sum + (parseInt(v.quantidade_total) || 0), 0);
    
    document.getElementById('produtosDia').textContent = produtosHoje;
    document.getElementById('produtosMes').textContent = produtosMes;

    const clientesHoje = vendasHoje.length;
    const clientesMes = vendasMes.length;
    
    document.getElementById('clientesDia').textContent = clientesHoje;
    document.getElementById('clientesMes').textContent = clientesMes;

    const ticketHoje = vendasHoje.length > 0 ? ganhosHoje / vendasHoje.length : 0;
    const ticketMes = vendasMes.length > 0 ? ganhosMes / vendasMes.length : 0;
    
    document.getElementById('ticketDia').textContent = `R$ ${ticketHoje.toFixed(2).replace('.', ',')}`;
    document.getElementById('ticketMes').textContent = `R$ ${ticketMes.toFixed(2).replace('.', ',')}`;

    const lucroHoje = ganhosHoje * 0.3;
    const lucroMes = ganhosMes * 0.3;
    
    document.getElementById('lucroDia').textContent = `R$ ${lucroHoje.toFixed(2).replace('.', ',')}`;
    document.getElementById('lucroMes').textContent = `R$ ${lucroMes.toFixed(2).replace('.', ',')}`;
}

function fecharModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'none';
}

function abrirModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.style.display = 'block';
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('Página carregada, iniciando carregamento de vendas...');
    carregarFiltros();
    carregarVendas();
});