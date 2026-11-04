(() => {
    'use strict';

    const dashboardStatus = document.querySelector('#dashboardStatus');
    if (!dashboardStatus) return;
    let csrfToken = '';
    let page = 1;
    let pages = 1;
    let selectedSession = null;
    let refreshTimer = null;
    let selectedImport = '';

    const api = async (action, options = {}) => {
        const headers = new Headers(options.headers || {});
        if (csrfToken && (options.method || 'GET') !== 'GET') headers.set('X-CSRF-Token', csrfToken);
        const response = await fetch(`?route=api/${action}`, {...options, headers});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || 'Falha ao consultar métricas.');
        return data;
    };

    const format = (value, suffix = '') => value === null || value === undefined ? '—' : `${Number(value).toFixed(3)}${suffix}`;
    const shortToken = token => token ? token.slice(0, 8) : '—';
    const emptyRow = (body, columns, message) => {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = columns;
        cell.textContent = message;
        row.append(cell);
        body.append(row);
    };

    const renderStatistics = data => {
        const body = document.querySelector('#statisticsBody');
        body.replaceChildren();
        for (const [scope, statistics] of [['Geral', data.overall], ['Sessão', data.session_statistics]]) {
            for (const architecture of ['A', 'B']) {
                const item = statistics[architecture];
                const row = document.createElement('tr');
                for (const value of [scope, architecture, item.count, item.executions, item.failures, format(item.mean), format(item.median), format(item.p95), format(item.minimum), format(item.maximum), format(item.population_stddev)]) {
                    const cell = document.createElement('td');
                    cell.textContent = value;
                    row.append(cell);
                }
                body.append(row);
            }
        }
    };

    const renderLifecycle = lifecycle => {
        const body = document.querySelector('#lifecycleBody');
        body.replaceChildren();
        const labels = {
            model_load_ms: 'Carregamento dos arquivos do modelo', runtime_ready_ms: 'Runtime TensorFlow pronto',
            initialization_ms: 'Inicialização total', first_inference_ms: 'Primeira inferência', warmup_ms: 'Aquecimento'
        };
        let rows = 0;
        for (const architecture of ['A', 'B']) {
            for (const [metric, item] of Object.entries(lifecycle?.[architecture] || {})) {
                const row = document.createElement('tr');
                [architecture, labels[metric] || metric, item.count, format(item.mean, ' ms'), format(item.median, ' ms'), format(item.p95, ' ms'), format(item.maximum, ' ms')]
                    .forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
                body.append(row); rows++;
            }
        }
        if (!rows) emptyRow(body, 7, 'Não há métricas de inicialização para esta sessão. Sessões históricas podem registrar apenas a latência por quadro.');
    };

    const percent = value => value === null || value === undefined ? '—' : `${Number(value).toFixed(2)}%`;

    const renderPrecision = precision => {
        const imports = precision.imports || [];
        const select = document.querySelector('#precisionImport');
        const run = document.querySelector('#runProbeEvaluation');
        const maximum = document.querySelector('#precisionMaximumSamples');
        const method = precision.methodology || {};
        document.querySelector('#precisionMethodology').textContent = method.denominator || 'Não há avaliação de probes disponível.';
        select.replaceChildren();
        for (const item of imports) {
            const option = document.createElement('option');
            option.value = item.import_token;
            option.textContent = `${item.dataset_name} · ${item.eligible_samples} probe(s) elegível(is)`;
            select.append(option);
        }
        if (selectedImport && [...select.options].some(option => option.value === selectedImport)) select.value = selectedImport;
        selectedImport = select.value || '';
        run.disabled = !selectedImport;
        maximum.disabled = !selectedImport;
        const body = document.querySelector('#precisionBody');
        body.replaceChildren();
        const evaluation = precision.evaluation;
        if (!evaluation) {
            emptyRow(body, 11, 'Nenhuma avaliação registrada. Selecione uma importação com probes válidas para iniciar uma avaliação explícita.');
            document.querySelector('#pairedPrecision').textContent = 'Não há resultados pareados disponíveis.';
            document.querySelector('#precisionResultsBody').replaceChildren();
            return;
        }
        for (const architecture of ['A', 'B']) {
            const item = precision.architectures[architecture];
            const row = document.createElement('tr');
            const values = [
                architecture, evaluation.selected_samples, item.completed, item.correct, item.incorrect, item.not_recognized,
                item.technical_failure, percent(item.coverage_percent), percent(item.accuracy_percent), percent(item.error_percent),
                format(item.comparison_time.mean, ' ms')
            ];
            values.forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
            body.append(row);
        }
        document.querySelector('#pairedPrecision').textContent = `${precision.paired_completed} probe(s) concluída(s) sem falha técnica nas duas arquiteturas; comparação pareada A/B. ${method.time || ''}`;
        const results = document.querySelector('#precisionResultsBody');
        results.replaceChildren();
        for (const item of precision.results || []) {
            const row = document.createElement('tr');
            const values = [item.sample_id, item.architecture_id, item.expected_identity_id, item.predicted_identity_id ?? '—', item.outcome,
                format(item.distance_squared), ambiguitySummary(item), format(item.comparison_total_ms, ' ms'), item.failure_code || '—'];
            values.forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
            results.append(row);
        }
        if (!(precision.results || []).length) emptyRow(results, 9, 'Esta avaliação ainda não possui resultados individuais.');
    };

    const ambiguitySummary = item => {
        const reason = item.decision_reason || item.recognition_result;
        const best = item.best_candidate_identity_id == null ? null : `1º ID ${item.best_candidate_identity_id}: ${format(item.best_candidate_distance_squared)}`;
        const second = item.second_candidate_identity_id == null ? null : `2º ID ${item.second_candidate_identity_id}: ${format(item.second_candidate_distance_squared)}`;
        const gap = item.candidate_distance_delta_squared == null ? null : `Δ ${format(item.candidate_distance_delta_squared)}`;
        const margin = item.ambiguity_margin_squared == null ? null : `margem ${format(item.ambiguity_margin_squared)}`;
        const values = [reason ? `Decisão: ${reason}` : null, best, second, gap, margin].filter(Boolean);
        return values.length ? values.join(' · ') : 'Candidatos indisponíveis';
    };

    const renderFrames = frames => {
        const body = document.querySelector('#pairedFramesBody');
        body.replaceChildren();
        for (const frame of frames) {
            const row = document.createElement('tr');
            const values = [
                shortToken(frame.session_token), frame.frame_sequence, frame.occurred_at ? new Date(`${frame.occurred_at}Z`).toLocaleString('pt-BR') : '—',
                (frame.failure_a && frame.failure_a !== 'null') ? frame.failure_a : (frame.result_a || 'ausente'), ambiguitySummary({
                    recognition_result: frame.decision_reason_a || frame.result_a, best_candidate_identity_id: frame.best_candidate_identity_a, best_candidate_distance_squared: frame.best_candidate_distance_a,
                    second_candidate_identity_id: frame.second_candidate_identity_a, second_candidate_distance_squared: frame.second_candidate_distance_a,
                    candidate_distance_delta_squared: frame.candidate_distance_delta_a, ambiguity_margin_squared: frame.ambiguity_margin_a
                }), format(frame.total_a_ms, ' ms'),
                (frame.failure_b && frame.failure_b !== 'null') ? frame.failure_b : (frame.result_b || 'ausente'), ambiguitySummary({
                    recognition_result: frame.decision_reason_b || frame.result_b, best_candidate_identity_id: frame.best_candidate_identity_b, best_candidate_distance_squared: frame.best_candidate_distance_b,
                    second_candidate_identity_id: frame.second_candidate_identity_b, second_candidate_distance_squared: frame.second_candidate_distance_b,
                    candidate_distance_delta_squared: frame.candidate_distance_delta_b, ambiguity_margin_squared: frame.ambiguity_margin_b
                }), format(frame.total_b_ms, ' ms'), format(frame.difference_ms, ' ms'),
                frame.complete ? 'A/B completo' : 'Incompleto'
            ];
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                if (!frame.complete && index === 8) cell.className = 'incomplete';
                row.append(cell);
            });
            body.append(row);
        }
        if (!frames.length) emptyRow(body, 11, 'Nenhum quadro oficial nesta sessão.');
    };

    const renderSessions = data => {
        const body = document.querySelector('#sessionsBody');
        body.replaceChildren();
        for (const item of data.sessions.items) {
            const row = document.createElement('tr');
            const values = [
                item.started_at ? new Date(`${item.started_at}Z`).toLocaleString('pt-BR') : '—', item.status, item.architectures,
                `${item.interval_ms} ms`, item.last_frame_sequence, item.missed_intervals, item.event_count, item.failure_count
            ];
            for (const value of values) {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.append(cell);
            }
            const actions = document.createElement('td');
            const select = document.createElement('button');
            select.type = 'button';
            select.className = 'session-select';
            select.textContent = item.session_token === data.selected_session ? 'Selecionada' : 'Visualizar';
            select.disabled = item.session_token === data.selected_session;
            select.addEventListener('click', () => { selectedSession = item.session_token; loadDashboard(); });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'session-delete';
            remove.textContent = 'Excluir';
            remove.disabled = ['running', 'paused', 'mixed'].includes(item.status);
            remove.addEventListener('click', () => deleteSession(item.session_token));
            actions.append(select, document.createTextNode(' '), remove);
            row.append(actions);
            body.append(row);
        }
        if (!data.sessions.items.length) emptyRow(body, 9, 'Nenhuma sessão registrada.');
        pages = data.sessions.pages;
        page = Math.min(data.sessions.page, pages);
        document.querySelector('#sessionsPage').textContent = `Página ${page} de ${pages} · ${data.sessions.total} sessão(ões)`;
        document.querySelector('#previousSessions').disabled = page <= 1;
        document.querySelector('#nextSessions').disabled = page >= pages;
    };

    const prepareCanvas = canvas => {
        const ratio = window.devicePixelRatio || 1;
        const width = Math.max(320, canvas.clientWidth || 700);
        const height = 280;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        const context = canvas.getContext('2d');
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);
        return {context, width, height};
    };

    const noChartData = (context, width, height) => {
        context.fillStyle = '#8fa4bd';
        context.font = '14px system-ui';
        context.textAlign = 'center';
        context.fillText('Sem medições válidas', width / 2, height / 2);
    };

    const drawLatencyChart = frames => {
        const {context, width, height} = prepareCanvas(document.querySelector('#latencyChart'));
        const chronological = [...frames].reverse();
        const values = chronological.flatMap(frame => [frame.total_a_ms, frame.total_b_ms]).filter(value => value !== null && value !== undefined);
        if (!values.length) { noChartData(context, width, height); return; }
        const padding = {left: 48, right: 18, top: 25, bottom: 35};
        const maximum = Math.max(...values);
        const scaleMax = maximum > 0 ? maximum * 1.1 : 1;
        context.strokeStyle = '#30435f';
        context.beginPath();
        context.moveTo(padding.left, padding.top);
        context.lineTo(padding.left, height - padding.bottom);
        context.lineTo(width - padding.right, height - padding.bottom);
        context.stroke();
        for (const [key, color, label] of [['total_a_ms', '#4fa3ff', 'A'], ['total_b_ms', '#5ee0a3', 'B']]) {
            context.strokeStyle = color;
            context.lineWidth = 2;
            context.beginPath();
            let started = false;
            chronological.forEach((frame, index) => {
                const value = frame[key];
                if (value === null || value === undefined) { started = false; return; }
                const x = padding.left + (chronological.length === 1 ? 0 : index / (chronological.length - 1)) * (width - padding.left - padding.right);
                const y = height - padding.bottom - Number(value) / scaleMax * (height - padding.top - padding.bottom);
                if (!started) context.moveTo(x, y); else context.lineTo(x, y);
                started = true;
            });
            context.stroke();
            context.fillStyle = color;
            context.fillText(label, width - padding.right - (label === 'A' ? 45 : 20), 16);
        }
        context.fillStyle = '#8fa4bd';
        context.textAlign = 'right';
        context.fillText(`${scaleMax.toFixed(1)} ms`, padding.left - 5, padding.top + 5);
        context.fillText('0', padding.left - 5, height - padding.bottom + 5);
    };

    const drawStagesChart = stages => {
        const {context, width, height} = prepareCanvas(document.querySelector('#stagesChart'));
        const stagesToPlot = [
            {name: 'detection_ms', label: 'Detecção', shortLabel: 'Detecção'},
            {name: 'extraction_ms', label: 'Extração', shortLabel: 'Extração'},
            {name: 'mysql_query_ms', label: 'Consulta MySQL', shortLabel: 'Consulta'},
            {name: 'candidate_read_ms', label: 'Leitura de candidatos', shortLabel: 'Leitura'},
            {name: 'embedding_decrypt_ms', label: 'Descriptografia', shortLabel: 'Decifr.'},
            {name: 'comparison_ms', label: 'Comparação', shortLabel: 'Comparar'},
            {name: 'comparison_total_ms', label: 'Comparação total', shortLabel: 'Comp. total'},
            {name: 'php_python_roundtrip_ms', label: 'PHP–Python', shortLabel: 'PHP–Py'},
        ];
        const values = stagesToPlot.flatMap(({name}) => ['A', 'B'].map(architecture => stages[architecture]?.[name]?.mean).filter(value => value !== undefined));
        if (!values.length) { noChartData(context, width, height); return; }
        const max = Math.max(...values) * 1.1 || 1;
        const top = 25, left = 42;
        const groupWidth = (width - left - 15) / stagesToPlot.length;
        const useShortLabels = groupWidth < 82;
        const rotateLabels = groupWidth < 55;
        const bottom = rotateLabels ? 78 : 55;
        const plotHeight = height - top - bottom;
        stagesToPlot.forEach(({name, label, shortLabel}, index) => {
            ['A', 'B'].forEach((architecture, position) => {
                const value = stages[architecture]?.[name]?.mean;
                if (value === undefined) return;
                const barWidth = Math.min(28, groupWidth / 3);
                const x = left + index * groupWidth + groupWidth / 2 + (position === 0 ? -barWidth : 2);
                const barHeight = Number(value) / max * plotHeight;
                context.fillStyle = architecture === 'A' ? '#4fa3ff' : '#5ee0a3';
                context.fillRect(x, top + plotHeight - barHeight, barWidth - 2, barHeight);
            });
            context.fillStyle = '#8fa4bd';
            context.font = '12px system-ui';
            const x = left + index * groupWidth + groupWidth / 2;
            const chartLabel = useShortLabels ? shortLabel : label;
            if (rotateLabels) {
                context.save();
                context.translate(x, height - 10);
                context.rotate(-Math.PI / 4);
                context.textAlign = 'right';
                context.fillText(chartLabel, 0, 0);
                context.restore();
            } else {
                context.textAlign = 'center';
                context.fillText(chartLabel, x, height - 28);
            }
        });
        context.fillStyle = '#4fa3ff'; context.fillText('A', width - 48, 15);
        context.fillStyle = '#5ee0a3'; context.fillText('B', width - 20, 15);
    };

    const loadDashboard = async () => {
        dashboardStatus.textContent = 'Atualizando painel...';
        try {
            const parameters = new URLSearchParams({page: String(page), limit: '20'});
            if (selectedSession) parameters.set('session_token', selectedSession);
            const data = await api(`dashboard&${parameters}`);
            selectedSession = data.selected_session;
            const method = data.methodology;
            document.querySelector('#dashboardMethodology').textContent = `Latência: ${method.latency_metric}; resultados válidos: ${method.included_results.join(', ')}; P95 nearest-rank; desvio populacional.`;
            document.querySelector('#exportMetrics').href = selectedSession ? `?route=api/metrics-export&session_token=${encodeURIComponent(selectedSession)}` : '?route=api/metrics-export';
            renderStatistics(data);
            renderLifecycle(data.lifecycle);
            renderPrecision(data.precision);
            renderFrames(data.paired_frames);
            renderSessions(data);
            drawLatencyChart(data.paired_frames);
            drawStagesChart(data.stages);
            dashboardStatus.textContent = method.precision_note;
        } catch (error) {
            dashboardStatus.textContent = error.message;
        }
    };

    const deleteSession = async token => {
        if (!window.confirm(`Excluir somente as métricas da sessão ${shortToken(token)}? Cadastros e embeddings serão preservados.`)) return;
        try {
            const data = await api('metrics-delete-session', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: token})});
            selectedSession = null;
            dashboardStatus.textContent = `Removidos ${data.removed.events} evento(s) e ${data.removed.metrics} métrica(s).`;
            await loadDashboard();
        } catch (error) { dashboardStatus.textContent = error.message; }
    };

    document.querySelector('#clearMetrics').addEventListener('click', async () => {
        if (!window.confirm('Excluir todas as sessões, eventos e métricas? Pessoas, embeddings e modelos serão preservados.')) return;
        try {
            const data = await api('metrics-clear', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'});
            selectedSession = null;
            page = 1;
            dashboardStatus.textContent = `Limpeza concluída: ${data.removed.sessions} sessão(ões), ${data.removed.events} evento(s), ${data.removed.metrics} métrica(s).`;
            await loadDashboard();
        } catch (error) { dashboardStatus.textContent = error.message; }
    });

    document.querySelector('#refreshDashboard').addEventListener('click', loadDashboard);
    document.querySelector('#precisionImport').addEventListener('change', event => { selectedImport = event.target.value; });
    document.querySelector('#runProbeEvaluation').addEventListener('click', async () => {
        const maximumSamples = Number(document.querySelector('#precisionMaximumSamples').value);
        if (!Number.isInteger(maximumSamples) || maximumSamples < 1 || maximumSamples > 200 || !selectedImport) {
            document.querySelector('#precisionStatus').textContent = 'Selecione uma importação e informe de 1 a 200 probes.';
            return;
        }
        const button = document.querySelector('#runProbeEvaluation');
        button.disabled = true;
        document.querySelector('#precisionStatus').textContent = 'Avaliando probes armazenadas, sem alterar a Gallery...';
        try {
            const precision = await api('probe-evaluation-run', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({import_token: selectedImport, maximum_samples: maximumSamples})});
            document.querySelector('#precisionStatus').textContent = `Avaliação concluída: ${precision.evaluation.selected_samples} probe(s) analisada(s).`;
            renderPrecision(precision);
        } catch (error) {
            document.querySelector('#precisionStatus').textContent = error.message;
        } finally { button.disabled = !selectedImport; }
    });
    document.querySelector('#previousSessions').addEventListener('click', () => { if (page > 1) { page--; loadDashboard(); } });
    document.querySelector('#nextSessions').addEventListener('click', () => { if (page < pages) { page++; loadDashboard(); } });
    window.addEventListener('resize', () => { clearTimeout(refreshTimer); refreshTimer = setTimeout(loadDashboard, 250); });
    document.querySelector('#metrics-tab')?.addEventListener('shown.bs.tab', () => { clearTimeout(refreshTimer); refreshTimer = setTimeout(loadDashboard, 0); });
    window.addEventListener('rfv2:metrics-updated', () => { clearTimeout(refreshTimer); refreshTimer = setTimeout(loadDashboard, 700); });

    (async () => {
        try {
            const bootstrap = await api('bootstrap');
            csrfToken = bootstrap.csrf_token;
            await loadDashboard();
        } catch (error) { dashboardStatus.textContent = error.message; }
    })();
})();
