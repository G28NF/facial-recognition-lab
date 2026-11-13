<?php
declare(strict_types=1);
/** @var array{database:string,schema_version:?string} $infrastructure */
/** @var bool $authenticated */
$ready = $infrastructure['database'] === 'disponivel';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reconhecimento Facial</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/app.css?v=20261113">
</head>
<body data-authenticated="<?= $authenticated ? 'true' : 'false' ?>">
<main class="container">
    <header class="page-header">
        <div><p class="eyebrow">Reconhecimento Facial</p><h1>Arquiteturas A e B</h1></div>
        <span class="privacy">Imagens não são armazenadas</span>
    </header>

    <?php if (!$authenticated): ?>
    <section class="panel login-panel" aria-labelledby="login-title">
        <h2 id="login-title">Acesso local</h2>
        <p>Informe a chave de acesso configurada para o sistema.</p>
        <form id="loginForm">
            <label for="accessKey">Chave de acesso</label>
            <input id="accessKey" type="password" autocomplete="current-password" required>
            <button type="submit">Entrar</button>
        </form>
        <p id="loginStatus" class="status" aria-live="polite"></p>
    </section>
    <?php else: ?>
    <nav class="rfv2-navigation" aria-label="Áreas do Reconhecimento Facial">
        <div class="nav nav-tabs rfv2-tabs" id="moduleTabs" role="tablist">
            <button class="nav-link active" id="recognition-tab" data-bs-toggle="tab" data-bs-target="#recognition-pane" type="button" role="tab" aria-controls="recognition-pane" aria-selected="true">Reconhecimento</button>
            <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users-pane" type="button" role="tab" aria-controls="users-pane" aria-selected="false">Usuários</button>
            <button class="nav-link" id="import-tab" data-bs-toggle="tab" data-bs-target="#import-pane" type="button" role="tab" aria-controls="import-pane" aria-selected="false">Importação</button>
            <button class="nav-link" id="metrics-tab" data-bs-toggle="tab" data-bs-target="#metrics-pane" type="button" role="tab" aria-controls="metrics-pane" aria-selected="false">Métricas</button>
        </div>
    </nav>

    <div class="tab-content rfv2-tab-content" id="moduleTabsContent">
        <section class="tab-pane fade show active" id="recognition-pane" role="tabpanel" aria-labelledby="recognition-tab" tabindex="0">
            <div class="recognition-layout">
                <section class="panel camera-panel" aria-labelledby="camera-title">
                    <h2 id="camera-title">Captura pela webcam</h2>
                    <div class="video-wrap"><video id="video" autoplay muted playsinline></video><div class="guide"></div></div>
                    <canvas id="capture" hidden></canvas>
                    <div class="actions">
                        <button id="recognizeA" type="button" disabled>Reconhecer com A</button>
                        <button id="recognizeB" type="button" disabled>Reconhecer com B</button>
                        <button id="stopCamera" type="button" class="secondary" disabled>Encerrar câmera</button>
                    </div>
                    <p id="status" class="status" aria-live="polite">Preparando aplicação...</p>
                </section>
                <aside class="panel registration-panel">
                    <h2>Cadastrar pessoa</h2>
                    <p>Uma captura gera embeddings separados Face API.js e SFace.</p>
                    <label for="personName">Nome</label>
                    <input id="personName" maxlength="120" autocomplete="name">
                    <label class="consent"><input id="consent" type="checkbox"> Confirmo o cadastro biométrico local.</label>
                    <button id="registerPerson" type="button" disabled>Cadastrar</button>
                    <button id="logout" type="button" class="link-button">Sair</button>
                </aside>
            </div>

            <section class="panel continuous-panel" aria-labelledby="continuous-title">
                <div class="section-heading">
                    <div><p class="eyebrow">Processamento contínuo</p><h2 id="continuous-title">Sessões de reconhecimento</h2><p>As arquiteturas A e B processam o mesmo quadro quando um rosto está presente.</p></div>
                    <strong id="sessionState" class="session-state idle">Inativa</strong>
                </div>
                <div class="session-controls">
                    <label for="recognitionInterval">Intervalo (ms)</label>
                    <input id="recognitionInterval" type="number" min="500" max="10000" step="100" value="1000">
                    <button id="startSession" type="button" disabled>Iniciar sessão</button>
                    <button id="pauseSession" type="button" class="secondary" disabled>Pausar</button>
                    <button id="resumeSession" type="button" class="secondary" disabled>Retomar</button>
                    <button id="endSession" type="button" class="danger" disabled>Encerrar sessão</button>
                </div>
                <p id="sessionStatus" class="status" aria-live="polite">Aguardando câmera e modelos.</p>
                <div class="scheduler-summary">
                    <span>Quadro <strong id="frameSequence">0</strong></span>
                    <span>Intervalos perdidos <strong id="missedIntervals">0</strong></span>
                    <span>Último atraso <strong id="schedulerDelay">0.000 ms</strong></span>
                </div>
                <section class="results-evaluation" aria-labelledby="evaluation-heading">
                    <div class="section-heading compact-heading"><div><p class="eyebrow">Processamento contínuo</p><h3 id="evaluation-heading">Resultados e avaliação</h3><p>Compare as arquiteturas A e B no mesmo quadro. A identidade real é opcional.</p></div><button id="customizeMetrics" type="button" class="secondary metric-customize-button" data-bs-toggle="modal" data-bs-target="#metricPreferencesModal">Personalizar métricas</button></div>
                    <div class="session-controls"><label for="evaluationIdentitySearch">Identidade real (ground truth)<input id="evaluationIdentitySearch" type="search" placeholder="Pesquisar identidade"></label><label for="evaluationIdentity">Selecionada<select id="evaluationIdentity"><option value="">Nenhuma — reconhecimento normal</option></select></label></div>
                    <section id="result" class="result-panel" hidden aria-live="polite"></section>
                    <div class="continuous-results">
                        <article class="engine-card" data-engine="A"><span>Arquitetura A</span><strong id="continuousResultA">—</strong><dl id="continuousMetricsA"></dl><p id="supervisedA" class="engine-evaluation">Sem avaliação supervisionada.</p></article>
                        <article class="engine-card" data-engine="B"><span>Arquitetura B</span><strong id="continuousResultB">—</strong><dl id="continuousMetricsB"></dl><p id="supervisedB" class="engine-evaluation">Sem avaliação supervisionada.</p></article>
                    </div>
                    <p class="method-note recognition-note">Os resultados contínuos são atualizados durante uma sessão ativa.</p>
                </section>
            </section>
            <section class="panel session-history-panel" aria-labelledby="session-history-title">
                <div class="section-heading"><div><p class="eyebrow">Sessão atual</p><h2 id="session-history-title">Histórico por quadro</h2><p>Eventos persistidos das arquiteturas A e B, do mais recente para o mais antigo.</p></div><span id="sessionHistoryCount" class="session-state idle">0 eventos</span></div>
                <div class="table-wrap"><table><thead><tr><th>Quadro</th><th>Horário</th><th>Arquitetura A</th><th>Tempo A</th><th>Arquitetura B</th><th>Tempo B</th></tr></thead><tbody id="currentSessionHistoryBody"><tr><td colspan="6">Inicie uma sessão para acompanhar os reconhecimentos.</td></tr></tbody></table></div>
            </section>
        </section>

        <section class="tab-pane fade" id="users-pane" role="tabpanel" aria-labelledby="users-tab" tabindex="0">
            <section class="panel people-panel">
                <div class="section-heading"><div><h2>Pessoas cadastradas</h2><p>Consulte modelos e gerencie amostras sem apagar o histórico experimental.</p></div><span id="identityCount">0</span></div>
                <label class="search-field" for="peopleSearch">Pesquisar por nome<input id="peopleSearch" type="search" placeholder="Digite parte do nome" autocomplete="off"></label>
                <p id="peopleStatus" class="status" aria-live="polite"></p>
                <div class="people-pagination" aria-label="Paginação de pessoas">
                    <label for="peoplePageSize">Por página<select id="peoplePageSize"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></label>
                    <span id="peoplePageInfo">Carregando pessoas...</span>
                    <div><button id="peoplePreviousTop" type="button" class="secondary">Anterior</button><button id="peopleNextTop" type="button" class="secondary">Próxima</button></div>
                </div>
                <div id="peopleTableAnchor" class="table-wrap"><table><thead><tr><th>ID</th><th>Nome</th><th>Amostras por modelo</th><th>Cadastro</th><th>Ações</th></tr></thead><tbody id="peopleBody"></tbody></table></div>
                <div class="people-pagination people-pagination-bottom" aria-label="Paginação de pessoas">
                    <span id="peoplePageInfoBottom"></span>
                    <div><button id="peoplePreviousBottom" type="button" class="secondary">Anterior</button><button id="peopleNextBottom" type="button" class="secondary">Próxima</button></div>
                </div>
                <article id="personDetail" class="person-detail" hidden aria-live="polite">
                    <div class="section-heading"><div><p class="eyebrow">Identidade selecionada</p><h3 id="personDetailTitle"></h3></div><button id="closePersonDetail" type="button" class="secondary">Fechar</button></div>
                    <p id="personImpact" class="method-note"></p>
                    <div class="person-edit-row"><label for="personEditName">Nome<input id="personEditName" maxlength="120"></label><button id="renamePerson" type="button">Salvar nome</button><button id="deletePerson" type="button" class="danger">Excluir pessoa</button></div>
                    <p id="personStatus" class="status" aria-live="polite"></p>
                    <h4>Amostras faciais</h4>
                    <div class="table-wrap"><table><thead><tr><th>Modelo</th><th>Amostra</th><th>Origem</th><th>Estado</th><th>Ação</th></tr></thead><tbody id="personSamplesBody"></tbody></table></div>
                </article>
            </section>
        </section>

        <section class="tab-pane fade" id="import-pane" role="tabpanel" aria-labelledby="import-tab" tabindex="0">
            <section class="panel import-panel" aria-labelledby="import-title">
                <div class="section-heading">
                    <div><p class="eyebrow">Importação segura</p><h2 id="import-title">Importar dataset facial</h2><p>Estrutura LFW: <code>raiz/identidade/imagem</code>.</p></div>
                    <strong id="importState" class="session-state idle">Inativa</strong>
                </div>
                <div class="split-guide">
                    <article><strong>Gallery</strong><span>Cria embeddings usados pela busca 1:N.</span></article>
                    <article><strong>Probe</strong><span>Fica criptografada e separada para avaliação.</span></article>
                </div>
                <div class="accordion rfv2-accordion" id="importOptions">
                    <div class="accordion-item">
                        <h3 class="accordion-header" id="import-config-heading"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#import-config-options" aria-expanded="false" aria-controls="import-config-options">1. Configuração do lote</button></h3>
                        <div id="import-config-options" class="accordion-collapse collapse" aria-labelledby="import-config-heading" data-bs-parent="#importOptions">
                            <div class="accordion-body">
                                <div class="import-grid">
                                    <label>Nome do conjunto<input id="datasetName" maxlength="160" value="LFW local autorizado"><small>Identifique a origem autorizada do conjunto.</small></label>
                                    <label>Máximo de pessoas<input id="importMaxPeople" type="number" min="1" value="20"><small>Limite aplicado antes do processamento.</small></label>
                                    <label>Imagens por pessoa<input id="importMaxImages" type="number" min="2" value="3"><small>Total máximo entre gallery e probe.</small></label>
                                    <label>Imagens gallery por pessoa<input id="importGalleryImages" type="number" min="1" value="2"><small>As imagens restantes serão probe.</small></label>
                                    <label>Destino do ZIP<select id="importSourceSplit"><option value="auto">Distribuir por pessoa</option><option value="gallery">Todas como gallery</option><option value="probe">Todas como probe</option></select><small>Selecione gallery ou probe para um ZIP previamente separado.</small></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="import-source-grid">
                    <label class="source-card" for="datasetFolder"><strong>2A. Selecionar pasta</strong><span>Escolha a raiz que contém uma subpasta por identidade.</span><input id="datasetFolder" type="file" accept="image/jpeg,image/png,image/webp" webkitdirectory directory multiple><small id="folderSelection">Nenhuma pasta selecionada.</small></label>
                    <label class="source-card" for="datasetZip"><strong>2B. Selecionar ZIP</strong><span>O ZIP será validado contra caminhos e expansão maliciosos.</span><input id="datasetZip" type="file" accept=".zip,application/zip"><small id="zipSelection">Nenhum ZIP selecionado.</small></label>
                </div>
                <div class="actions import-actions">
                    <button id="startFolderImport" type="button" disabled>Importar pasta</button>
                    <button id="startZipImport" type="button" class="secondary" disabled>Importar ZIP</button>
                    <button id="pauseImport" type="button" class="secondary" disabled>Pausar</button>
                    <button id="resumeImport" type="button" class="secondary" disabled>Retomar</button>
                    <button id="cancelImport" type="button" class="danger" disabled>Cancelar</button>
                </div>
                <div class="progress-heading"><strong>3. Progresso</strong><span>Uma amostra por vez, sem sobreposição.</span></div>
                <progress id="importProgress" max="1" value="0"></progress>
                <p id="importStatus" class="status" aria-live="polite">Selecione um conjunto local cujo uso esteja autorizado.</p>
                <div class="import-summary">
                    <span><small>Pessoas</small><strong id="importPeople">0</strong></span><span><small>Total</small><strong id="importTotal">0</strong></span><span><small>Processadas</small><strong id="importProcessed">0</strong></span>
                    <span><small>Rejeitadas</small><strong id="importRejected">0</strong></span><span><small>Pendentes</small><strong id="importPending">0</strong></span>
                </div>
                <h3>Amostras pendentes e rejeitadas</h3>
                <div class="table-wrap"><table><thead><tr><th>Identidade</th><th>Conjunto</th><th>Estado</th><th>Falha</th></tr></thead><tbody id="importSamplesBody"></tbody></table></div>
                <h3>Conjuntos importados</h3>
                <div class="table-wrap"><table><thead><tr><th>Conjunto</th><th>Origem</th><th>Estado</th><th>Processadas</th><th>Rejeitadas</th><th>Ações</th></tr></thead><tbody id="importsHistoryBody"></tbody></table></div>
                <p class="method-note">O LFW possui condições próprias de uso e não implica consentimento para qualquer finalidade. Confirme licença, base legal e autorização antes da seleção. Imagens são transitórias e eliminadas após processamento.</p>
            </section>
        </section>

        <section class="tab-pane fade" id="metrics-pane" role="tabpanel" aria-labelledby="metrics-tab" tabindex="0">
            <section class="panel dashboard-panel" aria-labelledby="dashboard-title">
                <div class="section-heading">
                    <div><p class="eyebrow">Métricas da sessão</p><h2 id="dashboard-title">Desempenho e métricas</h2></div>
                    <div class="dashboard-actions">
                        <button id="refreshDashboard" type="button" class="secondary">Atualizar</button>
                        <a id="exportMetrics" class="button-link" href="?route=api/metrics-export">CSV da sessão</a>
                        <a id="exportAllMetrics" class="button-link secondary-link" href="?route=api/metrics-export">CSV geral</a>
                        <button id="clearMetrics" type="button" class="danger">Limpar métricas</button>
                    </div>
                </div>
                <p id="dashboardStatus" class="status" aria-live="polite"></p>
                <section class="lifecycle-panel" aria-labelledby="lifecycle-title">
                    <h3 id="lifecycle-title">Inicialização e aquecimento da sessão selecionada</h3>
                    <p class="method-note">Métricas ausentes em sessões históricas permanecem como indisponíveis.</p>
                    <div class="table-wrap"><table><thead><tr><th>Arquitetura</th><th>Fase</th><th>Amostras</th><th>Média</th><th>Mediana</th><th>P95</th><th>Máximo</th></tr></thead><tbody id="lifecycleBody"></tbody></table></div>
                </section>
                <section class="precision-panel" aria-labelledby="precision-title">
                    <div class="section-heading"><div><p class="eyebrow">Avaliação supervisionada</p><h3 id="precision-title">Precisão em amostras Probe</h3></div></div>
                    <p id="precisionMethodology" class="method-note">Selecione uma importação com probes já processadas.</p>
                    <div class="precision-controls">
                        <label for="precisionImport">Importação Probe<select id="precisionImport"></select></label>
                        <label for="precisionMaximumSamples">Máximo de probes<input id="precisionMaximumSamples" type="number" min="1" max="200" value="20"></label>
                        <button id="runProbeEvaluation" type="button">Avaliar A/B</button>
                    </div>
                    <p id="precisionStatus" class="status" aria-live="polite"></p>
                    <div class="table-wrap"><table><thead><tr><th>Arquitetura</th><th>Probes do lote</th><th>Concluídas</th><th>Acertos</th><th>Incorretas</th><th>Não reconhecidas</th><th>Falhas técnicas</th><th>Cobertura</th><th>Taxa de acerto</th><th>Taxa de erro</th><th>Tempo médio 1:N</th></tr></thead><tbody id="precisionBody"></tbody></table></div>
                    <p id="pairedPrecision" class="method-note"></p>
                    <details class="precision-audit"><summary>Resultados individuais recentes da avaliação</summary><div class="table-wrap"><table><thead><tr><th>Probe</th><th>Arquitetura</th><th>Esperada</th><th>Prevista</th><th>Resultado</th><th>Distância²</th><th>Separação dos candidatos</th><th>Tempo 1:N</th><th>Falha</th></tr></thead><tbody id="precisionResultsBody"></tbody></table></div></details>
                </section>
                <div class="section-heading history-heading"><h3>Histórico de sessões</h3><div class="pagination"><button id="previousSessions" type="button" class="secondary">Anterior</button><span id="sessionsPage">Página 1</span><button id="nextSessions" type="button" class="secondary">Próxima</button></div></div>
                <div class="table-wrap"><table><thead><tr><th>Início</th><th>Estado</th><th>Arquiteturas</th><th>Intervalo</th><th>Quadros</th><th>Perdidos</th><th>Eventos</th><th>Falhas</th><th>Ações</th></tr></thead><tbody id="sessionsBody"></tbody></table></div>
            </section>
        </section>
    </div>
    <div class="modal fade metric-preferences-modal" id="metricPreferencesModal" tabindex="-1" aria-labelledby="metricPreferencesTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable metric-preferences-dialog">
            <div class="modal-content">
                <div class="modal-header"><h2 class="modal-title fs-5" id="metricPreferencesTitle">Personalizar métricas</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
                <div class="modal-body"><p class="method-note">A seleção é aplicada aos resultados das arquiteturas A e B. Métricas sem valor não são exibidas.</p><div id="metricPreferencesList"></div></div>
                <div class="modal-footer"><button id="resetMetricPreferences" type="button" class="secondary">Restaurar padrão</button><button type="button" data-bs-dismiss="modal">Concluir</button></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <footer><span class="indicator <?= $ready ? 'ok' : 'warning' ?>"></span> Banco <?= htmlspecialchars($infrastructure['database'], ENT_QUOTES, 'UTF-8') ?> · schema <?= htmlspecialchars($infrastructure['schema_version'] ?? 'não verificado', ENT_QUOTES, 'UTF-8') ?></footer>
</main>
<?php if ($authenticated): ?>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js" integrity="sha384-gzn2n++arkvyhdNLmUf1s6F5NZ8iAbZ7FhIt+Zw7Jlf1n/vNTmZ3+cYr7S4ogyco" crossorigin="anonymous" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous" defer></script>
<?php endif; ?>
<script src="assets/js/scheduler.js" defer></script>
<script src="assets/js/metric-finalizer.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/metric-finalizer.js') ?>" defer></script>
<script src="assets/js/upload-request.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/upload-request.js') ?>" defer></script>
<script src="assets/js/app.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/app.js') ?>" defer></script>
<?php if ($authenticated): ?>
    <script src="assets/js/dashboard.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/dashboard.js') ?>" defer></script>
    <script src="assets/js/import.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/import.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
