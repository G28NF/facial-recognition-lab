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
    <title>Reconhecimento Facial V2</title>
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body data-authenticated="<?= $authenticated ? 'true' : 'false' ?>">
<main class="container">
    <header class="page-header">
        <div><p class="eyebrow">Reconhecimento Facial V2</p><h1>Arquiteturas A e B</h1></div>
        <span class="privacy">Imagens não são armazenadas</span>
    </header>

    <?php if (!$authenticated): ?>
    <section class="panel login-panel" aria-labelledby="login-title">
        <h2 id="login-title">Acesso local</h2>
        <p>Informe a chave configurada em <code>RFV2_ACCESS_KEY</code>.</p>
        <form id="loginForm">
            <label for="accessKey">Chave de acesso</label>
            <input id="accessKey" type="password" autocomplete="current-password" required>
            <button type="submit">Entrar</button>
        </form>
        <p id="loginStatus" class="status" aria-live="polite"></p>
    </section>
    <?php else: ?>
    <section class="workspace">
        <div class="panel camera-panel">
            <h2>Captura pela webcam</h2>
            <div class="video-wrap"><video id="video" autoplay muted playsinline></video><div class="guide"></div></div>
            <canvas id="capture" hidden></canvas>
            <div class="actions">
                <button id="recognizeA" type="button" disabled>Reconhecer com A</button>
                <button id="recognizeB" type="button" disabled>Reconhecer com B</button>
                <button id="stopCamera" type="button" class="secondary" disabled>Encerrar câmera</button>
            </div>
            <p id="status" class="status" aria-live="polite">Preparando aplicação...</p>
        </div>

        <aside class="panel registration-panel">
            <h2>Cadastrar pessoa</h2>
            <p>Uma captura gera embeddings separados Face API.js e SFace.</p>
            <label for="personName">Nome</label>
            <input id="personName" maxlength="120" autocomplete="name">
            <label class="consent"><input id="consent" type="checkbox"> Confirmo o cadastro biométrico local.</label>
            <button id="registerPerson" type="button" disabled>Cadastrar</button>
            <button id="logout" type="button" class="link-button">Sair</button>
        </aside>
    </section>

    <section class="panel continuous-panel" aria-labelledby="continuous-title">
        <div class="section-heading">
            <div><h2 id="continuous-title">Reconhecimento contínuo</h2><p>As arquiteturas A e B processam o mesmo quadro quando um rosto está presente.</p></div>
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
        <div class="session-controls"><label for="evaluationIdentitySearch">Identidade real (ground truth)<input id="evaluationIdentitySearch" type="search" placeholder="Pesquisar identidade"></label><label for="evaluationIdentity">Selecionada<select id="evaluationIdentity"><option value="">Nenhuma — reconhecimento normal</option></select></label></div>
        <p id="sessionStatus" class="status" aria-live="polite">Aguardando câmera e modelos.</p>
        <div class="continuous-results">
            <article class="engine-card" data-engine="A"><span>Arquitetura A</span><strong id="continuousResultA">—</strong><dl id="continuousMetricsA"></dl></article>
            <article class="engine-card" data-engine="B"><span>Arquitetura B</span><strong id="continuousResultB">—</strong><dl id="continuousMetricsB"></dl></article>
        </div>
        <div class="scheduler-summary">
            <span>Quadro <strong id="frameSequence">0</strong></span>
            <span>Intervalos perdidos <strong id="missedIntervals">0</strong></span>
            <span>Último atraso <strong id="schedulerDelay">0.000 ms</strong></span>
        </div>
        <div class="continuous-results"><article class="engine-card"><span>Avaliação A</span><strong id="supervisedA">Selecione uma identidade</strong></article><article class="engine-card"><span>Avaliação B</span><strong id="supervisedB">Selecione uma identidade</strong></article></div>
    </section>

    <section id="result" class="panel result-panel" hidden aria-live="polite"></section>

    <section class="panel import-panel" aria-labelledby="import-title">
        <div class="section-heading">
            <div><p class="eyebrow">Importação segura</p><h2 id="import-title">Importar dataset facial</h2><p>Estrutura LFW: <code>raiz/identidade/imagem</code>.</p></div>
            <strong id="importState" class="session-state idle">Inativa</strong>
        </div>
        <div class="split-guide">
            <article><strong>Gallery</strong><span>Cria embeddings usados pela busca 1:N.</span></article>
            <article><strong>Probe</strong><span>Fica criptografada e separada para avaliação.</span></article>
        </div>
        <div class="import-config-card">
            <h3>1. Configuração do lote</h3>
            <div class="import-grid">
                <label>Nome do conjunto<input id="datasetName" maxlength="160" value="LFW local autorizado"><small>Identifique a origem autorizada do conjunto.</small></label>
                <label>Máximo de pessoas<input id="importMaxPeople" type="number" min="1" value="20"><small>Limite aplicado antes do processamento.</small></label>
                <label>Imagens por pessoa<input id="importMaxImages" type="number" min="2" value="3"><small>Total máximo entre gallery e probe.</small></label>
                <label>Imagens gallery por pessoa<input id="importGalleryImages" type="number" min="1" value="2"><small>As imagens restantes serão probe.</small></label>
                <label>Destino do ZIP<select id="importSourceSplit"><option value="auto">Distribuir por pessoa</option><option value="gallery">Todas como gallery</option><option value="probe">Todas como probe</option></select><small>Selecione gallery ou probe para um ZIP previamente separado.</small></label>
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

    <section class="panel people-panel">
        <div class="section-heading"><div><h2>Pessoas cadastradas</h2><p>Consulte modelos e gerencie amostras sem apagar o histórico experimental.</p></div><span id="identityCount">0</span></div>
        <label class="search-field" for="peopleSearch">Pesquisar por nome<input id="peopleSearch" type="search" placeholder="Digite parte do nome" autocomplete="off"></label>
        <p id="peopleStatus" class="status" aria-live="polite"></p>
        <div class="people-pagination" aria-label="Paginação de pessoas">
            <label for="peoplePageSize">Por página<select id="peoplePageSize"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select></label>
            <span id="peoplePageInfo">Carregando pessoas...</span>
            <div><button id="peoplePreviousTop" type="button" class="secondary">Anterior</button><button id="peopleNextTop" type="button" class="secondary">Próxima</button></div>
        </div>
        <div id="peopleTableAnchor" class="table-wrap"><table><thead><tr><th>ID</th><th>Nome</th><th>Modelos disponíveis</th><th>Cadastro</th><th>Ações</th></tr></thead><tbody id="peopleBody"></tbody></table></div>
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

    <section class="panel dashboard-panel" aria-labelledby="dashboard-title">
        <div class="section-heading">
            <div><h2 id="dashboard-title">Desempenho e métricas</h2><p id="dashboardMethodology">Carregando metodologia...</p></div>
            <div class="dashboard-actions">
                <button id="refreshDashboard" type="button" class="secondary">Atualizar</button>
                <a id="exportMetrics" class="button-link" href="?route=api/metrics-export">CSV da sessão</a>
                <a id="exportAllMetrics" class="button-link secondary-link" href="?route=api/metrics-export">CSV geral</a>
                <button id="clearMetrics" type="button" class="danger">Limpar métricas</button>
            </div>
        </div>
        <p id="dashboardStatus" class="status" aria-live="polite"></p>
        <div class="table-wrap"><table><thead><tr><th>Escopo</th><th>Arquitetura</th><th>Válidas</th><th>Execuções</th><th>Falhas</th><th>Média</th><th>Mediana</th><th>P95</th><th>Mínimo</th><th>Máximo</th><th>Desvio populacional</th></tr></thead><tbody id="statisticsBody"></tbody></table></div>
        <section class="lifecycle-panel" aria-labelledby="lifecycle-title">
            <h3 id="lifecycle-title">Inicialização e aquecimento da sessão selecionada</h3>
            <p class="method-note">Inicialização não integra a latência oficial por quadro. Métricas ausentes em sessões históricas permanecem como indisponíveis.</p>
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
        <div class="chart-grid">
            <article><h3>Evolução por quadro pareado</h3><canvas id="latencyChart" width="700" height="280"></canvas></article>
            <article><h3>Etapas médias</h3><canvas id="stagesChart" width="700" height="280"></canvas></article>
        </div>

        <h3>Comparação do mesmo quadro</h3>
        <div class="table-wrap"><table><thead><tr><th>Sessão</th><th>Quadro</th><th>Data</th><th>Resultado A</th><th>Separação A</th><th>Total A</th><th>Resultado B</th><th>Separação B</th><th>Total B</th><th>Diferença B−A</th><th>Integridade</th></tr></thead><tbody id="pairedFramesBody"></tbody></table></div>

        <div class="section-heading history-heading"><h3>Histórico de sessões</h3><div class="pagination"><button id="previousSessions" type="button" class="secondary">Anterior</button><span id="sessionsPage">Página 1</span><button id="nextSessions" type="button" class="secondary">Próxima</button></div></div>
        <div class="table-wrap"><table><thead><tr><th>Início</th><th>Estado</th><th>Arquiteturas</th><th>Intervalo</th><th>Quadros</th><th>Perdidos</th><th>Eventos</th><th>Falhas</th><th>Ações</th></tr></thead><tbody id="sessionsBody"></tbody></table></div>
    </section>
    <?php endif; ?>

    <footer><span class="indicator <?= $ready ? 'ok' : 'warning' ?>"></span> Banco <?= htmlspecialchars($infrastructure['database'], ENT_QUOTES, 'UTF-8') ?> · schema <?= htmlspecialchars($infrastructure['schema_version'] ?? 'não verificado', ENT_QUOTES, 'UTF-8') ?></footer>
</main>
<?php if ($authenticated): ?><script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js" integrity="sha384-gzn2n++arkvyhdNLmUf1s6F5NZ8iAbZ7FhIt+Zw7Jlf1n/vNTmZ3+cYr7S4ogyco" crossorigin="anonymous" defer></script><?php endif; ?>
<script src="assets/js/scheduler.js" defer></script>
<script src="assets/js/app.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/app.js') ?>" defer></script>
<?php if ($authenticated): ?><script src="assets/js/dashboard.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/dashboard.js') ?>" defer></script><?php endif; ?>
<?php if ($authenticated): ?><script src="assets/js/import.js?v=<?= (int) filemtime(dirname(__DIR__, 2).'/public/assets/js/import.js') ?>" defer></script><?php endif; ?>
</body>
</html>
