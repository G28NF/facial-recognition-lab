(() => {
    'use strict';

    const authenticated = document.body.dataset.authenticated === 'true';
    let csrfToken = '';
    let stream = null;
    let modelsPromise = null;
    let modelsReady = false;
    let modelInitializationMs = 0;
    let modelLoadMs = null;
    let runtimeReadyMs = null;
    let faceApiFirstInferenceMs = null;
    let pythonInitializationMs = 0;
    let faceApiPipelineWarmed = false;
    let pythonPipelineWarmed = false;
    let evaluationIdentityId = null;
    let activeEvaluationIdentityId = null;
    let evaluationSearchTimer = null;
    const supervised = {A: {correct: 0, incorrect: 0, notRecognized: 0, technical: 0}, B: {correct: 0, incorrect: 0, notRecognized: 0, technical: 0}};
    let supervisedPaired = 0;

    const request = async (action, options = {}) => {
        const headers = new Headers(options.headers || {});
        if (csrfToken && (options.method || 'GET') !== 'GET') headers.set('X-CSRF-Token', csrfToken);
        const response = await fetch(`?route=api/${action}`, {...options, headers});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || 'Falha de comunicação.');
        return data;
    };
    const sessionRequest = async (action, body) => {
        const headers = {'Content-Type': 'application/json'};
        if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
        return globalThis.RFV2UploadRequest.sendJson(`?route=api/${action}`, {headers, body});
    };

    const metricFinalizer = new globalThis.RFV2MetricFinalizer(payload => {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 5000);
        return request('session-finalize', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
            signal: controller.signal
        }).finally(() => window.clearTimeout(timeout));
    }, 3);
    const httpFailureStorage = {
        getItem: () => window.localStorage.getItem('rfv2:http-failure-pending'),
        setItem: entries => window.localStorage.setItem('rfv2:http-failure-pending', JSON.stringify(entries)),
        removeItem: () => window.localStorage.removeItem('rfv2:http-failure-pending')
    };
    const httpFailureReporter = new globalThis.RFV2MetricFinalizer(payload => {
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 5000);
        return request('session-http-failure', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload), signal: controller.signal})
            .then(data => { if (data.status !== 'recorded') throw new Error('Registro HTTP não confirmado.'); return data; })
            .finally(() => window.clearTimeout(timeout));
    }, 3, httpFailureStorage);
    const transportNotice = () => {
        const {pending, exhausted} = httpFailureReporter.summary();
        return pending + exhausted ? ` Falhas HTTP ainda sem confirmação de persistência: ${pending + exhausted}.` : '';
    };
    const submitArchitecture = async (architecture, body) => {
        try {
            const {data, uploadMs} = await sessionRequest(`session-frame-${architecture.toLowerCase()}`, body);
            if (data.architecture !== architecture || !['recognized', 'unknown', 'inconclusive', 'error'].includes(data.status) || !data.metrics) throw new Error('Resposta inválida.');
            return {data, uploadMs};
        } catch (error) {
            const payload = JSON.parse(body);
            await httpFailureReporter.submit({session_token: payload.session_token, frame_sequence: payload.frame_sequence, architecture});
            error.httpFailure = true;
            throw error;
        }
    };
    const finalizationNotice = ({pending, exhausted}) => exhausted > 0
        ? ` Métricas sem finalização após três tentativas: ${exhausted}.`
        : (pending > 0 ? ` Finalizações de métricas pendentes: ${pending}.` : '');
    const closedFinalizationNotice = ({pending, exhausted}) => pending + exhausted > 0
        ? ` ${pending + exhausted} finalização(ões) de métricas não pôde(ram) ser recuperada(s); os resultados biométricos foram preservados.`
        : '';

    if (!authenticated) {
        const form = document.querySelector('#loginForm');
        const loginStatus = document.querySelector('#loginStatus');
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            loginStatus.textContent = 'Validando acesso...';
            try {
                await request('login', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({access_key: document.querySelector('#accessKey').value})});
                window.location.reload();
            } catch (error) {
                loginStatus.textContent = error.message;
            }
        });
        return;
    }

    const video = document.querySelector('#video');
    const canvas = document.querySelector('#capture');
    const status = document.querySelector('#status');
    const result = document.querySelector('#result');
    const sessionStatus = document.querySelector('#sessionStatus');
    const intervalInput = document.querySelector('#recognitionInterval');
    const manualButtons = [document.querySelector('#recognizeA'), document.querySelector('#recognizeB'), document.querySelector('#registerPerson')];
    const session = {state: 'idle', token: null, intervalMs: 1000, nextDue: 0, sequence: 0, missed: 0, timer: null, inFlight: null};
    const activeSessionStorageKey = 'rfv2:active-session';
    const rememberSession = () => {
        if (session.token) window.localStorage.setItem(activeSessionStorageKey, session.token);
    };
    const forgetSession = () => window.localStorage.removeItem(activeSessionStorageKey);

    const sessionActive = () => !['idle', 'ended'].includes(session.state);
    const cameraReady = () => Boolean(stream && video.videoWidth && video.videoHeight && modelsReady);

    const updateControls = () => {
        const ready = cameraReady();
        const active = sessionActive();
        manualButtons.forEach(button => { button.disabled = !ready || active; });
        document.querySelector('#startSession').disabled = !ready || active;
        document.querySelector('#pauseSession').disabled = session.state !== 'running';
        document.querySelector('#resumeSession').disabled = !['paused', 'error'].includes(session.state);
        document.querySelector('#endSession').disabled = !['running', 'paused', 'pausing', 'error'].includes(session.state);
        document.querySelector('#stopCamera').disabled = !stream || active;
        intervalInput.disabled = active;
        const badge = document.querySelector('#sessionState');
        badge.className = `session-state ${session.state}`;
        badge.textContent = {idle: 'Inativa', running: 'Executando', pausing: 'Pausando', paused: 'Pausada', resuming: 'Retomando', ending: 'Encerrando', ended: 'Encerrada', error: 'Falha'}[session.state] || session.state;
    };

    const loadModels = async () => {
        if (modelsPromise) return modelsPromise;
        modelsPromise = (async () => {
            const loadStarted = performance.now();
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri('assets/models'),
                faceapi.nets.faceLandmark68Net.loadFromUri('assets/models'),
                faceapi.nets.faceRecognitionNet.loadFromUri('assets/models')
            ]);
            modelLoadMs = performance.now() - loadStarted;
            const runtimeStarted = performance.now();
            if (faceapi.tf && typeof faceapi.tf.ready === 'function') await faceapi.tf.ready();
            runtimeReadyMs = performance.now() - runtimeStarted;
            modelInitializationMs = modelLoadMs + runtimeReadyMs;
            modelsReady = true;
        })().catch(error => { modelsPromise = null; throw error; });
        return modelsPromise;
    };

    const captureFrame = () => {
        if (!video.videoWidth || !video.videoHeight) throw new Error('A câmera ainda não está pronta.');
        const started = performance.now();
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        return {captureMs: performance.now() - started};
    };

    const encodeFrame = () => {
        const started = performance.now();
        return {image: canvas.toDataURL('image/jpeg', 0.9), imageEncodeMs: performance.now() - started};
    };
    const payloadBytes = body => new TextEncoder().encode(body).byteLength;
    const faceApiFailure = (code, message) => Object.assign(new Error(message), {rfv2FailureCode: code});

    const faceApiEmbeddingFromCanvas = async (sourceCanvas) => {
        await loadModels();
        const inferenceStarted = performance.now();
        const detectionStarted = performance.now();
        const detections = await faceapi.detectAllFaces(sourceCanvas, new faceapi.TinyFaceDetectorOptions());
        const detectionMs = performance.now() - detectionStarted;
        if (detections.length === 0) throw faceApiFailure('no_face', 'Nenhum rosto foi detectado pela Face API.js.');
        if (detections.length > 1) throw faceApiFailure('multiple_faces', 'Mais de um rosto foi detectado.');
        const extractionStarted = performance.now();
        const detectedFaces = await faceapi.extractFaces(sourceCanvas, detections);
        const landmarks = await faceapi.detectFaceLandmarks(detectedFaces[0]);
        const alignedFaces = await faceapi.extractFaces(sourceCanvas, [landmarks.align(detections[0])]);
        const descriptor = await faceapi.computeFaceDescriptor(alignedFaces[0]);
        faceApiPipelineWarmed = true;
        const firstInferenceMs = performance.now() - inferenceStarted;
        const isFirstInference = faceApiFirstInferenceMs === null;
        if (isFirstInference) faceApiFirstInferenceMs = firstInferenceMs;
        return {embedding: Array.from(descriptor), metrics: {
            detection_ms: detectionMs, extraction_ms: performance.now() - extractionStarted,
            ...(isFirstInference ? {first_inference_ms: firstInferenceMs} : {})
        }};
    };

    const faceApiEmbedding = () => faceApiEmbeddingFromCanvas(canvas);
    window.rfv2FaceApi = {loadModels, extractFromCanvas: faceApiEmbeddingFromCanvas};

    const renderSupervised = () => {
        for (const architecture of ['A', 'B']) { const item = supervised[architecture], completed = item.correct + item.incorrect + item.notRecognized;
            document.querySelector(`#supervised${architecture}`).textContent = activeEvaluationIdentityId ? `A ${item.correct} · I ${item.incorrect} · N ${item.notRecognized} · F ${item.technical} · acerto ${completed ? (item.correct * 100 / completed).toFixed(2) + '%' : '—'} · pareados ${supervisedPaired}` : 'Sem avaliação supervisionada.'; }
    };
    const resetSupervised = () => { supervisedPaired = 0; for (const architecture of ['A', 'B']) Object.assign(supervised[architecture], {correct: 0, incorrect: 0, notRecognized: 0, technical: 0}); renderSupervised(); };
    const loadEvaluationIdentities = async query => { const params = new URLSearchParams({offset: '0', limit: '20'}); if (query) params.set('query', query); const data = await request(`people&${params.toString()}`); const select = document.querySelector('#evaluationIdentity'), current = select.value; select.replaceChildren(new Option('Nenhuma — reconhecimento normal', '')); for (const person of data.people) select.add(new Option(`${person.name} · ID ${person.id}`, String(person.id))); if ([...select.options].some(option => option.value === current)) select.value = current; };

    let people = [];
    let selectedPersonId = null;
    const peoplePage = {offset: 0, limit: 20, total: 0, query: ''};
    let peopleSearchTimer = null;
    const peoplePreviousButtons = [document.querySelector('#peoplePreviousTop'), document.querySelector('#peoplePreviousBottom')];
    const peopleNextButtons = [document.querySelector('#peopleNextTop'), document.querySelector('#peopleNextBottom')];

    const modelBadge = (label, count) => {
        const badge = document.createElement('span');
        badge.className = `model-badge${count > 0 ? '' : ' missing'}`;
        badge.textContent = `${label}: ${count}`;
        return badge;
    };

    const renderPeople = () => {
        const body = document.querySelector('#peopleBody');
        body.replaceChildren();
        for (const person of people) {
            const row = document.createElement('tr');
            for (const value of [person.id, person.name]) {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.append(cell);
            }
            const models = document.createElement('td');
            models.className = 'model-badges';
            models.append(modelBadge('Face API.js', person.faceapi_samples), modelBadge('SFace', person.sface_samples));
            row.append(models);
            const created = document.createElement('td');
            created.textContent = new Date(`${person.created_at}Z`).toLocaleString('pt-BR');
            row.append(created);
            const actions = document.createElement('td');
            const manage = document.createElement('button');
            manage.type = 'button'; manage.className = 'secondary'; manage.textContent = 'Gerenciar';
            manage.addEventListener('click', () => openPerson(person.id));
            actions.append(manage); row.append(actions);
            body.append(row);
        }
        if (!people.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.textContent = peoplePage.total ? 'Nenhuma pessoa nesta página.' : (peoplePage.query ? 'Nenhuma pessoa corresponde à pesquisa.' : 'Nenhuma pessoa cadastrada.');
            row.append(cell);
            body.append(row);
        }
    };

    const renderPeoplePagination = () => {
        const totalPages = Math.max(1, Math.ceil(peoplePage.total / peoplePage.limit));
        const page = Math.min(totalPages, Math.floor(peoplePage.offset / peoplePage.limit) + 1);
        const first = peoplePage.total ? peoplePage.offset + 1 : 0;
        const last = peoplePage.total ? Math.min(peoplePage.offset + people.length, peoplePage.total) : 0;
        const text = `Página ${page} de ${totalPages} · ${first}–${last} de ${peoplePage.total}`;
        document.querySelector('#peoplePageInfo').textContent = text;
        document.querySelector('#peoplePageInfoBottom').textContent = text;
        peoplePreviousButtons.forEach(button => { button.disabled = peoplePage.offset === 0; });
        peopleNextButtons.forEach(button => { button.disabled = peoplePage.offset + peoplePage.limit >= peoplePage.total; });
    };

    const renderPeopleLoadError = message => {
        const body = document.querySelector('#peopleBody');
        body.replaceChildren();
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.textContent = `Não foi possível carregar as identidades: ${message}`;
        row.append(cell);
        body.append(row);
    };

    const loadPeople = async ({reset = false, focus = false} = {}) => {
        if (reset) peoplePage.offset = 0;
        const params = new URLSearchParams({offset: String(peoplePage.offset), limit: String(peoplePage.limit)});
        if (peoplePage.query) params.set('query', peoplePage.query);
        const peopleStatus = document.querySelector('#peopleStatus');
        try {
            let data = await request(`people&${params.toString()}`);
            if (peoplePage.offset >= data.total && data.total > 0) {
                peoplePage.offset = Math.floor((data.total - 1) / peoplePage.limit) * peoplePage.limit;
                params.set('offset', String(peoplePage.offset));
                data = await request(`people&${params.toString()}`);
            }
            people = data.people;
            peoplePage.total = data.total;
            document.querySelector('#identityCount').textContent = data.total;
            peopleStatus.textContent = '';
            renderPeople();
            renderPeoplePagination();
            if (focus) document.querySelector('#peopleTableAnchor').scrollIntoView({behavior: 'smooth', block: 'start'});
        } catch (error) {
            people = [];
            peoplePage.total = 0;
            document.querySelector('#identityCount').textContent = '—';
            peopleStatus.textContent = `Falha ao carregar identidades: ${error.message}`;
            renderPeopleLoadError(error.message);
            renderPeoplePagination();
            throw error;
        }
    };

    const renderPerson = person => {
        selectedPersonId = person.id;
        const detail = document.querySelector('#personDetail');
        detail.hidden = false;
        document.querySelector('#personDetailTitle').textContent = `${person.name} · ID ${person.id}`;
        document.querySelector('#personEditName').value = person.name;
        document.querySelector('#personImpact').textContent = `Origem: ${person.enrollment_basis === 'authorized_dataset' ? 'dataset autorizado' : 'consentimento explícito'} · ${person.history_events} evento(s) histórico(s) · ${person.dataset_links} vínculo(s) com dataset.`;
        const body = document.querySelector('#personSamplesBody');
        body.replaceChildren();
        for (const sample of person.samples) {
            const row = document.createElement('tr');
            if (!sample.active) row.className = 'sample-inactive';
            const origin = sample.dataset_name ? `${sample.dataset_name} · ${sample.split || 'gallery'}` : 'Cadastro pela webcam';
            for (const value of [sample.model_name, `#${sample.sample_number}`, origin, sample.active ? 'Ativa' : 'Inativa']) {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            }
            const action = document.createElement('td');
            const toggle = document.createElement('button');
            toggle.type = 'button'; toggle.className = sample.active ? 'danger' : 'secondary';
            toggle.textContent = sample.active ? 'Desativar' : 'Reativar';
            toggle.addEventListener('click', async () => {
                const verb = sample.active ? 'desativar' : 'reativar';
                if (!window.confirm(`Deseja ${verb} esta amostra ${sample.model_name} #${sample.sample_number}?`)) return;
                try {
                    const data = await request('sample-status', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({embedding_id: sample.id, active: !sample.active})});
                    renderPerson(data.person);
                    await loadPeople();
                    document.querySelector('#personStatus').textContent = `Amostra ${sample.active ? 'desativada' : 'reativada'}.`;
                } catch (error) { document.querySelector('#personStatus').textContent = error.message; }
            });
            action.append(toggle); row.append(action); body.append(row);
        }
        if (!person.samples.length) {
            const row = document.createElement('tr'); const cell = document.createElement('td');
            cell.colSpan = 5; cell.textContent = 'Nenhuma amostra facial associada.'; row.append(cell); body.append(row);
        }
        detail.scrollIntoView({behavior: 'smooth', block: 'nearest'});
    };

    const openPerson = async id => {
        const personStatus = document.querySelector('#personStatus');
        try { renderPerson(await request(`person-detail&id=${encodeURIComponent(id)}`)); personStatus.textContent = ''; }
        catch (error) { personStatus.textContent = error.message; }
    };

    document.querySelector('#peopleSearch').addEventListener('input', event => {
        peoplePage.query = event.target.value.trim();
        clearTimeout(peopleSearchTimer);
        peopleSearchTimer = window.setTimeout(() => { loadPeople({reset: true}).catch(error => { document.querySelector('#personStatus').textContent = error.message; }); }, 250);
    });
    document.querySelector('#peoplePageSize').addEventListener('change', event => {
        peoplePage.limit = Number(event.target.value);
        loadPeople({reset: true}).catch(error => { document.querySelector('#personStatus').textContent = error.message; });
    });
    peoplePreviousButtons.forEach(button => button.addEventListener('click', () => { if (peoplePage.offset > 0) { peoplePage.offset -= peoplePage.limit; loadPeople({focus: true}).catch(error => { document.querySelector('#personStatus').textContent = error.message; }); } }));
    peopleNextButtons.forEach(button => button.addEventListener('click', () => { if (peoplePage.offset + peoplePage.limit < peoplePage.total) { peoplePage.offset += peoplePage.limit; loadPeople({focus: true}).catch(error => { document.querySelector('#personStatus').textContent = error.message; }); } }));
    document.querySelector('#closePersonDetail').addEventListener('click', () => { selectedPersonId = null; document.querySelector('#personDetail').hidden = true; });
    document.querySelector('#renamePerson').addEventListener('click', async () => {
        if (!selectedPersonId) return;
        const personStatus = document.querySelector('#personStatus');
        try {
            await request('person-rename', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: selectedPersonId, name: document.querySelector('#personEditName').value})});
            await loadPeople();
            await openPerson(selectedPersonId);
            personStatus.textContent = 'Nome atualizado com segurança.';
        } catch (error) { personStatus.textContent = error.message; }
    });
    document.querySelector('#deletePerson').addEventListener('click', async () => {
        if (!selectedPersonId) return;
        const name = document.querySelector('#personDetailTitle').textContent;
        if (!window.confirm(`Excluir ${name}? A pessoa e suas amostras deixarão de participar do reconhecimento. O histórico será preservado.`)) return;
        if (!window.confirm('Confirma a exclusão? Esta interface não oferece restauração da pessoa.')) return;
        const personStatus = document.querySelector('#personStatus');
        try {
            const data = await request('person-delete', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: selectedPersonId})});
            selectedPersonId = null;
            document.querySelector('#personDetail').hidden = true;
            await loadPeople();
            status.textContent = `Pessoa excluída. ${data.impact.deactivated_embeddings} amostra(s) desativada(s); histórico preservado.`;
        } catch (error) { personStatus.textContent = error.message; }
    });

    const outcomeText = (data) => data.server_result_unknown ? 'Resultado no servidor não confirmado' : data.status === 'recognized'
        ? `${data.name} (ID ${data.id})`
        : data.status === 'inconclusive' ? 'Resultado inconclusivo'
            : data.status === 'error' ? 'Falha no pipeline' : 'Pessoa desconhecida';

    const metricRows = (data) => [
        ['Distância quadrada', data.distance_squared],
        ['Embeddings comparados', data.compared_embeddings, 'integer'],
        ['Detecção', data.metrics?.detection_ms],
        ['Extração', data.metrics?.extraction_ms],
        ['Consulta MySQL', data.metrics?.mysql_query_ms],
        ['Leitura dos candidatos', data.metrics?.candidate_read_ms],
        ['Descriptografia', data.metrics?.embedding_decrypt_ms],
        ['Preparação', data.metrics?.candidate_prepare_ms],
        ['Comparação matemática', data.metrics?.comparison_ms],
        ['Seleção por identidade', data.metrics?.candidate_selection_ms],
        ['Consulta do nome', data.metrics?.matched_name_query_ms],
        ['Descriptografia do nome', data.metrics?.matched_name_decrypt_ms],
        ['Overhead da comparação', data.metrics?.comparison_overhead_ms],
        ['Comparação total', data.metrics?.comparison_total_ms],
        ['Pipeline total', data.metrics?.pipeline_total_ms],
        ['Round-trip PHP–Python', data.metrics?.php_python_roundtrip_ms],
        ['Overhead PHP–Python', data.metrics?.php_python_overhead_ms],
        ['Codificação JPEG', data.metrics?.image_encode_ms],
        ['Envio observado pelo navegador', data.metrics?.browser_upload_ms],
        ['Bytes enviados', data.metrics?.request_payload_bytes, 'integer']
    ];

    const appendMetrics = (container, rows) => {
        container.replaceChildren();
        for (const [label, value, format] of rows) {
            if (value === null || value === undefined) continue;
            const dt = document.createElement('dt');
            const dd = document.createElement('dd');
            dt.textContent = label;
            dd.textContent = typeof value === 'number' ? (format === 'integer' ? String(Math.trunc(value)) : Number(value).toFixed(3)) : value;
            container.append(dt, dd);
        }
    };

    const currentSessionHistory = [];
    const historyResult = item => {
        if (!item) return 'Indisponível';
        if (item.status === 'recognized') return item.name ? `Reconhecida: ${item.name}` : `Reconhecida: ID ${item.id}`;
        if (item.server_result_unknown) return 'Resultado no servidor não confirmado';
        if (item.status === 'error') return item.failure || item.error || 'Falha técnica';
        return item.status === 'inconclusive' ? 'Inconclusivo' : 'Desconhecida';
    };

    const historyTime = item => {
        const value = item?.metrics?.pipeline_total_ms;
        return value === null || value === undefined ? '—' : `${Number(value).toFixed(3)} ms`;
    };

    const renderCurrentSessionHistory = () => {
        const body = document.querySelector('#currentSessionHistoryBody');
        const count = document.querySelector('#sessionHistoryCount');
        body.replaceChildren();
        count.textContent = `${currentSessionHistory.length} evento${currentSessionHistory.length === 1 ? '' : 's'}`;
        count.className = `session-state ${currentSessionHistory.length ? 'running' : 'idle'}`;
        for (const event of currentSessionHistory) {
            const byArchitecture = Object.fromEntries(event.results.map(item => [item.architecture, item]));
            const row = document.createElement('tr');
            const values = [event.sequence, event.occurredAt.toLocaleTimeString('pt-BR'), historyResult(byArchitecture.A), historyTime(byArchitecture.A), historyResult(byArchitecture.B), historyTime(byArchitecture.B)];
            values.forEach(value => { const cell = document.createElement('td'); cell.textContent = value; row.append(cell); });
            body.append(row);
        }
        if (!currentSessionHistory.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.textContent = 'Aguardando o primeiro reconhecimento registrado.';
            row.append(cell);
            body.append(row);
        }
    };

    const recordCurrentSessionFrame = (sequence, results) => {
        currentSessionHistory.unshift({sequence, results, occurredAt: new Date()});
        if (currentSessionHistory.length > 100) currentSessionHistory.pop();
        renderCurrentSessionHistory();
    };

    const renderResult = (data) => {
        result.hidden = false;
        result.replaceChildren();
        const title = document.createElement('h2');
        title.textContent = `Resultado da arquitetura ${data.architecture}`;
        const outcome = document.createElement('strong');
        outcome.className = `outcome ${data.status}`;
        outcome.textContent = outcomeText(data);
        const details = document.createElement('dl');
        appendMetrics(details, metricRows(data));
        result.append(title, outcome, details);
    };

    const renderContinuous = (results, sharedMetrics) => {
        for (const item of results) {
            const outcome = document.querySelector(`#continuousResult${item.architecture}`);
            outcome.className = `outcome ${item.status}`;
            outcome.textContent = outcomeText(item);
            appendMetrics(document.querySelector(`#continuousMetrics${item.architecture}`), metricRows({...item, metrics: {...item.metrics, ...sharedMetrics}}));
        }
    };

    document.querySelector('#recognizeA').addEventListener('click', async () => {
        updateControls();
        manualButtons.forEach(button => { button.disabled = true; });
        try {
            status.textContent = 'Extraindo com Face API.js...';
            captureFrame();
            const face = await faceApiEmbedding();
            const started = performance.now();
            const data = await request('recognize-a', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({embedding: face.embedding, metrics: face.metrics})});
            data.metrics.browser_php_roundtrip_ms = performance.now() - started;
            renderResult(data);
            status.textContent = 'Reconhecimento A concluído.';
        } catch (error) { status.textContent = error.message; } finally { updateControls(); }
    });

    document.querySelector('#recognizeB').addEventListener('click', async () => {
        manualButtons.forEach(button => { button.disabled = true; });
        try {
            status.textContent = 'Extraindo com YuNet e SFace...';
            captureFrame();
            const frame = encodeFrame();
            const started = performance.now();
            const data = await request('recognize-b', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({image: frame.image})});
            pythonPipelineWarmed = true;
            data.metrics.browser_php_roundtrip_ms = performance.now() - started;
            renderResult(data);
            status.textContent = 'Reconhecimento B concluído.';
        } catch (error) { status.textContent = error.message; } finally { updateControls(); }
    });

    document.querySelector('#registerPerson').addEventListener('click', async () => {
        const name = document.querySelector('#personName').value.trim();
        const consent = document.querySelector('#consent').checked;
        if (name.length < 2 || !consent) { status.textContent = 'Informe o nome e confirme o consentimento.'; return; }
        manualButtons.forEach(button => { button.disabled = true; });
        try {
            status.textContent = 'Gerando os dois embeddings...';
            captureFrame();
            const frame = encodeFrame();
            const face = await faceApiEmbedding();
            const data = await request('register', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({name, consent, image: frame.image, faceapi_embedding: face.embedding})});
            pythonPipelineWarmed = true;
            document.querySelector('#personName').value = '';
            document.querySelector('#consent').checked = false;
            await loadPeople({reset: true});
            status.textContent = `Cadastro concluído com ID ${data.id}.`;
        } catch (error) { status.textContent = error.message; } finally { updateControls(); }
    });

    const scheduleNext = () => {
        if (session.state !== 'running') return;
        const delay = Math.max(0, session.nextDue - performance.now());
        session.timer = window.setTimeout(() => {
            session.inFlight = schedulerTick();
            session.inFlight.finally(() => {
                session.inFlight = null;
                scheduleNext();
            });
        }, delay);
    };

    const schedulerTick = async () => {
        if (session.state !== 'running') return;
        await metricFinalizer.retryPending();
        await httpFailureReporter.retryPending();
        const tickStarted = performance.now();
        const timing = globalThis.RFV2SchedulerPolicy.advance(session.nextDue, tickStarted, session.intervalMs);
        const schedulerDelay = timing.delayMs;
        const missed = timing.missedIntervals;
        session.missed += missed;
        session.nextDue = timing.nextDue;
        const sequence = ++session.sequence;
        document.querySelector('#frameSequence').textContent = sequence;
        document.querySelector('#missedIntervals').textContent = session.missed;
        document.querySelector('#schedulerDelay').textContent = `${schedulerDelay.toFixed(3)} ms`;
        let committed = false;
        let sequenceSubmissionStarted = false;
        let monitoringMetrics = {scheduler_delay_ms: schedulerDelay};
        try {
            const frame = captureFrame();
            monitoringMetrics = {
                ...monitoringMetrics,
                capture_ms: frame.captureMs
            };

            if (!faceApiPipelineWarmed || !pythonPipelineWarmed) {
                sessionStatus.textContent = 'Aquecendo os pipelines com o primeiro rosto...';
                let faceapiWarmupMs = null;
                let faceapiFirstInferenceMs = null;
                if (!faceApiPipelineWarmed) {
                    const warmupStarted = performance.now();
                    const warmupFace = await faceApiEmbedding();
                    faceapiWarmupMs = performance.now() - warmupStarted;
                    faceapiFirstInferenceMs = warmupFace.metrics.first_inference_ms ?? null;
                }
                sequenceSubmissionStarted = true;
                const warmupFrame = encodeFrame();
                await request('session-warmup', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: session.token, frame_sequence: sequence, missed_intervals: missed, image: warmupFrame.image, ...(faceapiWarmupMs === null ? {} : {faceapi_warmup_ms: faceapiWarmupMs}), ...(faceapiFirstInferenceMs === null ? {} : {faceapi_first_inference_ms: faceapiFirstInferenceMs}), monitoring_metrics: monitoringMetrics})});
                faceApiPipelineWarmed = true;
                pythonPipelineWarmed = true;
                committed = true;
                sessionStatus.textContent = 'Pipelines aquecidos; medições oficiais começam no próximo quadro.';
                return;
            }

            const executeA = async metricsForRound => {
                const totalStarted = performance.now();
                let face;
                try { face = await faceApiEmbedding(); } catch (error) {
                    const failure = ['no_face', 'multiple_faces'].includes(error?.rfv2FailureCode) ? error.rfv2FailureCode : 'technical_failure';
                    face = {embedding: null, failure, metrics: {faceapi_failure_ms: performance.now() - totalStarted}};
                }
                const body = JSON.stringify({session_token: session.token, frame_sequence: sequence, missed_intervals: missed, faceapi_embedding: face.embedding, faceapi_failure: face.failure ?? null, faceapi_metrics: face.metrics, monitoring_metrics: metricsForRound});
                const communicationStarted = performance.now();
                sequenceSubmissionStarted = true;
                const submission = await submitArchitecture('A', body);
                const data = submission.data;
                const clientMetrics = {browser_upload_ms: submission.uploadMs, http_roundtrip_ms: performance.now() - communicationStarted, end_to_end_ms: performance.now() - totalStarted, request_payload_bytes: payloadBytes(body)};
                Object.assign(data.metrics, clientMetrics);
                await metricFinalizer.submit({session_token: session.token, frame_sequence: sequence, architecture: 'A', metrics: clientMetrics});
                return data;
            };
            const executeB = async metricsForRound => {
                const totalStarted = performance.now();
                const encoded = encodeFrame();
                const body = JSON.stringify({session_token: session.token, frame_sequence: sequence, missed_intervals: missed, image: encoded.image, monitoring_metrics: metricsForRound});
                const communicationStarted = performance.now();
                sequenceSubmissionStarted = true;
                const submission = await submitArchitecture('B', body);
                const data = submission.data;
                const clientMetrics = {image_encode_ms: encoded.imageEncodeMs, browser_upload_ms: submission.uploadMs, http_roundtrip_ms: performance.now() - communicationStarted, end_to_end_ms: performance.now() - totalStarted, request_payload_bytes: payloadBytes(body)};
                Object.assign(data.metrics, clientMetrics);
                await metricFinalizer.submit({session_token: session.token, frame_sequence: sequence, architecture: 'B', metrics: clientMetrics});
                return data;
            };
            const order = sequence % 2 === 1 ? ['A', 'B'] : ['B', 'A'];
            const results = [];
            for (const architecture of order) {
                try {
                    const item = architecture === 'A' ? await executeA(results.length === 0 ? monitoringMetrics : {}) : await executeB(results.length === 0 ? monitoringMetrics : {});
                    results.push(item); committed = true;
                } catch (error) {
                    results.push({architecture, status: 'error', id: null, name: null, distance_squared: null, compared_embeddings: 0, error: error.httpFailure ? 'http_failure' : 'internal_failure', server_result_unknown: true, metrics: {}});
                }
            }
            results.sort((left, right) => left.architecture.localeCompare(right.architecture));
            renderContinuous(results, {});
            recordCurrentSessionFrame(sequence, results);
            if (activeEvaluationIdentityId) { if (results.length === 2 && results.every(item => item.status !== 'error')) supervisedPaired++; for (const item of results) { const stats = supervised[item.architecture]; if (item.server_result_unknown) continue; else if (item.status === 'error') stats.technical++; else if (item.status === 'recognized') item.id === activeEvaluationIdentityId ? stats.correct++ : stats.incorrect++; else stats.notRecognized++; } renderSupervised(); }
            sessionStatus.textContent = `Quadro ${sequence}: ${results.some(item => item.server_result_unknown) ? 'resultado não confirmado; consulte o histórico após recuperar a comunicação' : 'concluído sem sobreposição'}.${finalizationNotice(metricFinalizer.summary())}${transportNotice()}`;
        } catch (error) {
            sessionStatus.textContent = `Falha no quadro ${sequence}: ${error.message}. A próxima rodada será tentada.`;
            if (!committed && !sequenceSubmissionStarted && session.state === 'running') {
                try {
                    await request('session-presence', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: session.token, frame_sequence: sequence, missed_intervals: missed, monitoring_metrics: {...monitoringMetrics, client_failure_count: 1}})});
                } catch {}
            }
        }
    };

    document.querySelector('#startSession').addEventListener('click', async () => {
        const interval = Number(intervalInput.value);
        session.state = 'resuming';
        updateControls();
        try {
            const data = await request('session-start', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({interval_ms: interval, ...(evaluationIdentityId ? {expected_identity_id: evaluationIdentityId} : {}), initialization: {
                A: {initialization_ms: modelInitializationMs, model_load_ms: modelLoadMs, runtime_ready_ms: runtimeReadyMs},
                B: {initialization_ms: pythonInitializationMs}
            }})});
            session.token = data.session_token;
            rememberSession();
            metricFinalizer.reset();
            activeEvaluationIdentityId = evaluationIdentityId;
            resetSupervised();
            session.intervalMs = data.interval_ms;
            session.sequence = 0;
            session.missed = 0;
            currentSessionHistory.length = 0;
            renderCurrentSessionHistory();
            session.nextDue = performance.now();
            session.state = 'running';
            document.querySelector('#frameSequence').textContent = '0';
            document.querySelector('#missedIntervals').textContent = '0';
            sessionStatus.textContent = 'Sessão iniciada. Monitorando presença.';
            updateControls();
            scheduleNext();
        } catch (error) {
            session.state = 'idle';
            sessionStatus.textContent = error.message;
            updateControls();
        }
    });

    document.querySelector('#pauseSession').addEventListener('click', async () => {
        session.state = 'pausing';
        clearTimeout(session.timer);
        updateControls();
        try {
            if (session.inFlight) await session.inFlight;
            await request('session-pause', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: session.token})});
            session.state = 'paused';
            sessionStatus.textContent = 'Sessão pausada; nenhum quadro será acumulado.';
        } catch (error) {
            session.state = 'error';
            sessionStatus.textContent = error.message;
        }
        updateControls();
    });

    document.querySelector('#resumeSession').addEventListener('click', async () => {
        session.state = 'resuming';
        updateControls();
        try {
            const remote = await request(`session-status&session_token=${encodeURIComponent(session.token)}`);
            session.intervalMs = remote.interval_ms;
            session.sequence = remote.last_frame_sequence;
            session.missed = remote.missed_intervals;
            if (remote.status === 'paused') await request('session-resume', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: session.token})});
            else if (remote.status !== 'running') {
                session.state = 'ended';
                forgetSession();
                sessionStatus.textContent = `A sessão já está ${remote.status} no servidor.`;
                updateControls();
                return;
            }
            session.nextDue = performance.now() + session.intervalMs;
            session.state = 'running';
            sessionStatus.textContent = 'Sessão recuperada a partir do estado confirmado no servidor.';
            updateControls();
            scheduleNext();
        } catch (error) {
            session.state = 'error';
            sessionStatus.textContent = error.message;
            updateControls();
        }
    });

    const endSession = async () => {
        if (!sessionActive()) return;
        const previousState = session.state;
        session.state = 'ending';
        clearTimeout(session.timer);
        updateControls();
        try {
            if (session.inFlight) await session.inFlight;
            const finalizationSummary = await metricFinalizer.retryPending();
            await httpFailureReporter.retryPending();
            await request('session-end', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: session.token})});
            session.state = 'ended';
            forgetSession();
            sessionStatus.textContent = `Sessão encerrada e persistida.${closedFinalizationNotice(finalizationSummary)}${transportNotice()}`;
            window.dispatchEvent(new CustomEvent('rfv2:metrics-updated'));
        } catch (error) {
            session.state = previousState === 'paused' ? 'paused' : 'error';
            sessionStatus.textContent = error.message;
        }
        updateControls();
    };

    document.querySelector('#endSession').addEventListener('click', endSession);
    document.querySelector('#evaluationIdentitySearch').addEventListener('input', event => { clearTimeout(evaluationSearchTimer); evaluationSearchTimer = setTimeout(() => loadEvaluationIdentities(event.target.value.trim()).catch(() => {}), 250); });
    document.querySelector('#evaluationIdentity').addEventListener('change', async event => { const next = event.target.value ? Number(event.target.value) : null; evaluationIdentityId = next; if (!sessionActive()) { activeEvaluationIdentityId = next; resetSupervised(); return; } try { if (session.inFlight) await session.inFlight; await request('session-evaluation-identity', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_token: session.token, ...(next ? {expected_identity_id: next} : {})})}); activeEvaluationIdentityId = next; resetSupervised(); sessionStatus.textContent = next ? 'Novo segmento de avaliação iniciado.' : 'Avaliação supervisionada encerrada.'; } catch (error) { sessionStatus.textContent = error.message; } });
    document.querySelector('#stopCamera').addEventListener('click', () => {
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        video.srcObject = null;
        status.textContent = 'Câmera encerrada.';
        updateControls();
    });

    document.querySelector('#logout').addEventListener('click', async () => {
        try {
            if (sessionActive()) await endSession();
            await request('logout', {method: 'POST', body: '{}', headers: {'Content-Type': 'application/json'}});
        } finally { window.location.reload(); }
    });

    window.addEventListener('rfv2:identity-updated', async () => {
        try { await loadPeople({reset: true}); } catch (_) {}
    });

    (async () => {
        try {
            const bootstrap = await request('bootstrap');
            csrfToken = bootstrap.csrf_token;
            await httpFailureReporter.retryPending();
            const persistedToken = window.localStorage.getItem(activeSessionStorageKey);
            if (persistedToken) {
                try {
                    const remote = await request(`session-status&session_token=${encodeURIComponent(persistedToken)}`);
                    if (['running', 'paused'].includes(remote.status)) {
                        session.token = persistedToken;
                        session.intervalMs = remote.interval_ms;
                        session.sequence = remote.last_frame_sequence;
                        session.missed = remote.missed_intervals;
                        session.state = remote.status === 'paused' ? 'paused' : 'error';
                        sessionStatus.textContent = remote.status === 'paused'
                            ? 'Sessão pausada recuperada do servidor.'
                            : 'Sessão em execução recuperada; use Continuar para retomar a coleta.';
                    } else forgetSession();
                } catch (_) { forgetSession(); }
            }
            await loadPeople();
            await loadEvaluationIdentities('');
            intervalInput.value = bootstrap.video.default_interval_ms;
            intervalInput.min = bootstrap.video.minimum_interval_ms;
            intervalInput.max = bootstrap.video.maximum_interval_ms;
            status.textContent = 'Inicializando modelos e câmera...';
            const camera = navigator.mediaDevices.getUserMedia({video: {facingMode: 'user'}, audio: false});
            const [, pythonWarmup] = await Promise.all([
                loadModels(),
                request('warmup-b', {method: 'POST', body: '{}', headers: {'Content-Type': 'application/json'}}),
                camera.then(value => {
                    stream = value;
                    for (const track of value.getVideoTracks()) {
                        track.addEventListener('ended', () => {
                            if (['running', 'paused'].includes(session.state)) endSession();
                            sessionStatus.textContent = 'A câmera foi desconectada.';
                        });
                    }
                    video.srcObject = value;
                    return video.play();
                })
            ]);
            pythonInitializationMs = Number(pythonWarmup.initialization_ms || 0);
            status.textContent = 'Câmera e modelos prontos.';
            sessionStatus.textContent = 'Pronto para iniciar uma sessão comparativa.';
            updateControls();
        } catch (error) {
            status.textContent = error.name === 'NotAllowedError' ? 'Permissão da câmera negada.' : error.message;
            sessionStatus.textContent = 'Inicialização incompleta.';
            updateControls();
        }
    })();

    window.addEventListener('pagehide', () => {
        clearTimeout(session.timer);
        if (sessionActive() && session.token) {
            rememberSession();
        }
        stream?.getTracks().forEach(track => track.stop());
    });
})();
