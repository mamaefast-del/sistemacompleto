          </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Editar Comissão -->
    <div id="editCommissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-percentage"></i>
                    Editar Taxa de Comissão
                </h3>
                <button class="modal-close" onclick="closeEditModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_commission">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="form-group">
                        <label class="form-label">Nova Taxa de Comissão (%)</label>
                        <input type="number" 
                               name="commission_rate" 
                               id="editCommissionRate" 
                               class="form-input" 
                               step="0.1" 
                               max="100"
                               max="50"
                               required>
                        <small style="color: var(--text-muted); font-size: 11px;">
                            Digite a nova taxa de comissão (0% a 100%)
                        </small>
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                        <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Ver Detalhes -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-chart-line"></i>
                    Detalhes do Afiliado
                </h3>
                <button class="modal-close" onclick="closeDetailsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="affiliateDetails">
                    <div style="text-align: center; padding: 20px; color: var(--text-muted);">
                        <i class="fas fa-spinner fa-spin"></i>
                        <p>Carregando detalhes...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Menu do usuário
        function toggleUserMenu() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('show');
        }

        // Modal usuários online
        function showOnlineUsers() {
            // Implementar se necessário
        }

        // Editar comissão
        function editCommission(userId, currentRate) {
            document.getElementById('editUserId').value = userId;
            document.getElementById('editCommissionRate').value = currentRate;
            document.getElementById('editCommissionModal').classList.add('show');
        }

        function closeEditModal() {
            document.getElementById('editCommissionModal').classList.remove('show');
        }

        // Ver detalhes do afiliado
        function viewDetails(affiliateId) {
            document.getElementById('detailsModal').classList.add('show');
            
            fetch(`get_affiliate_details.php?id=${affiliateId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAffiliateDetails(data.affiliate);
                    } else {
                        document.getElementById('affiliateDetails').innerHTML = `
                            <div style="text-align: center; padding: 20px; color: var(--error-color);">
                                <i class="fas fa-exclamation-triangle"></i>
                                <p>Erro ao carregar detalhes: ${data.error}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('affiliateDetails').innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--error-color);">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Erro ao carregar detalhes</p>
                        </div>
                    `;
                });
        }

        function displayAffiliateDetails(affiliate) {
            const html = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--primary-green); margin-bottom: 12px;">Informações Gerais</h4>
                    <div style="background: var(--bg-card); padding: 16px; border-radius: 8px;">
                        <p><strong>Nome:</strong> ${affiliate.nome}</p>
                        <p><strong>Email:</strong> ${affiliate.email}</p>
                        <p><strong>Código:</strong> ${affiliate.codigo_afiliado}</p>
                        <p><strong>Taxa:</strong> ${parseFloat(affiliate.porcentagem_afiliado).toFixed(1)}%</p>
                        <p><strong>Cadastro:</strong> ${new Date(affiliate.data_cadastro).toLocaleDateString('pt-BR')}</p>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--primary-green); margin-bottom: 12px;">Estatísticas</h4>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--primary-gold);">${affiliate.total_referidos}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Indicados</div>
                        </div>
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--success-color);">R$ ${parseFloat(affiliate.volume_total).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Volume</div>
                        </div>
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--warning-color);">R$ ${parseFloat(affiliate.comissao).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Pendente</div>
                        </div>
                        <div style="background: var(--bg-card); padding: 12px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 18px; font-weight: 700; color: var(--success-color);">R$ ${parseFloat(affiliate.saldo_comissao).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                            <div style="font-size: 11px; color: var(--text-muted);">Recebido</div>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('affiliateDetails').innerHTML = html;
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.remove('show');
        }

        // Fechar modais ao clicar fora
        document.addEventListener('click', function(event) {
            const userMenu = document.querySelector('.user-menu');
            
            if (!userMenu.contains(event.target)) {
                document.getElementById('userDropdown').classList.remove('show');
            }

            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        });

        // Tecla ESC para fechar menus
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.getElementById('userDropdown').classList.remove('show');
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });

        // Atualizar estatísticas do header
        async function updateHeaderStats() {
            try {
                const response = await fetch('get_header_stats.php');
                const data = await response.json();
                
                document.getElementById('online-count').textContent = data.online || 0;
                document.getElementById('deposito-count').textContent = data.depositos_pendentes || 0;
                document.getElementById('saque-count').textContent = data.saques_pendentes || 0;
            } catch (error) {
                console.error('Erro ao atualizar estatísticas:', error);
            }
        }

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            updateHeaderStats();
            setInterval(updateHeaderStats, 30000);
        });
    </script>
</body>
</html>