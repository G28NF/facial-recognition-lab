(() => {
    'use strict';
    const panel = document.querySelector('#import-title');
    if (!panel) return;

    let csrfToken = '';
    let capabilities = null;
    let current = null;
    let processing = false;
    let localFiles = new Map();
    const samplePageSize = 100;
    const processQueueSize = 10;
    let renderedSampleSignature = '';

    const byId = id => document.querySelector(`#${id}`);
    const status = byId('importStatus');
    const importCapabilities = () => {
        if (!capabilities || !Number.isSafeInteger(capabilities.maximum_archive_bytes) || capabilities.maximum_archive_bytes < 1) {
            throw new Error('A configuracao de limites da importacao ainda nao esta disponivel. Atualize a pagina e verifique o servico da Central.');
        }
        return capabilities;
    };
    const request = async (action, options = {}) => {
        const headers = new Headers(options.headers || {});
        if (csrfToken && (options.method || 'GET') !== 'GET') headers.set('X-CSRF-Token', csrfToken);
        const response = await fetch(`?route=api/${action}`, {...options, headers});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || 'Falha na importação.');
            error.status = response.status;
            throw error;
        }
        return data;
    };
    const jsonPost = (action, body) => request(action, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
    const limits = () => ({
        maximum_people: Number(byId('importMaxPeople').value),
        maximum_images_per_person: Number(byId('importMaxImages').value),
        gallery_images_per_person: Number(byId('importGalleryImages').value)
    });
    const failureText = code => {
        try {
            const failures = JSON.parse(code);
            if (failures && typeof failures === 'object' && !Array.isArray(failures)) {
                return Object.entries(failures).map(([architecture, failure]) => `${architecture}: ${failureText(failure)}`).join('; ');
            }
        } catch (_) {}
        return ({
        unsupported_format: 'formato não suportado', invalid_image: 'imagem inválida', image_too_large: 'imagem muito grande',
        dimensions_invalid: 'dimensões inválidas', faceapi_rejected: 'Face API.js rejeitou a imagem',
        python_rejected: 'YuNet/SFace não encontrou um rosto válido',
        no_face: 'nenhum rosto encontrado', multiple_faces: 'mais de um rosto encontrado',
        biometric_failure: 'extração biométrica rejeitada', http_failure: 'falha temporária de comunicação',
        internal_failure: 'falha temporária interna',
        python_unavailable: 'serviço Python indisponível; inicie-o e retome o lote',
        backend_rejected: 'o PHP rejeitou os dados processados da amostra',
        backend_failed: 'falha interna ao persistir ou validar a amostra',
        gallery_required: 'nenhuma gallery válida para a identidade',
        processing_failed: 'falha de processamento', import_cancelled: 'importação cancelada'
        }[code] || code || '—');
    };

    const render = data => {
        current = data;
        const terminal = ['completed', 'cancelled'].includes(data.status);
        const active = ['running', 'paused'].includes(data.status);
        byId('importState').textContent = {running: 'Executando', paused: 'Pausada', completed: 'Concluída', cancelled: 'Cancelada'}[data.status] || data.status;
        byId('importState').className = `session-state ${data.status === 'running' ? 'running' : data.status === 'paused' ? 'paused' : 'ended'}`;
        byId('importTotal').textContent = data.total_samples;
        byId('importPeople').textContent = data.linked_identities;
        byId('importProcessed').textContent = data.processed_samples;
        byId('importRejected').textContent = data.rejected_samples;
        byId('importPending').textContent = data.pending_samples;
        byId('importProgress').max = Math.max(1, data.total_samples);
        byId('importProgress').value = data.processed_samples + data.rejected_samples;
        byId('pauseImport').disabled = data.status !== 'running';
        byId('resumeImport').disabled = data.status !== 'paused';
        byId('cancelImport').disabled = terminal;
        byId('startFolderImport').disabled = active;
        byId('startZipImport').disabled = active || !capabilities?.zip_available;
        status.textContent = `${data.dataset_name}: ${data.processed_samples} processadas, ${data.rejected_samples} rejeitadas, ${data.pending_samples} pendentes.${data.current_identity ? ` Próxima: ${data.current_identity}.` : ''}`;
    };

    const renderSamples = samples => {
        const signature = samples.map(sample => `${sample.sample_token}:${sample.status}:${sample.failure_code || ''}`).join('|');
        if (signature === renderedSampleSignature) return;
        renderedSampleSignature = signature;
        const body = byId('importSamplesBody');
        body.replaceChildren();
        for (const sample of samples) {
            const row = document.createElement('tr');
            for (const value of [sample.identity, sample.split, sample.status, failureText(sample.failure_code)]) {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            }
            body.append(row);
        }
    };

    const samplePage = (states, limit = samplePageSize, offset = 0) => request(`import-samples&import_token=${encodeURIComponent(current.import_token)}&states=${encodeURIComponent(states)}&limit=${limit}&offset=${offset}`);
    const refreshVisibleSamples = async () => {
        if (!current) return;
        const page = await samplePage('pending,rejected');
        renderSamples(page.items);
    };

    const formatBytes = bytes => {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    };

    byId('datasetFolder').addEventListener('change', event => {
        const files = [...event.target.files];
        const root = files[0]?.webkitRelativePath.split('/')[0];
        byId('folderSelection').textContent = files.length
            ? `${root || 'Pasta'}: ${files.length} arquivo(s) selecionado(s).`
            : 'Nenhuma pasta selecionada.';
    });
    byId('datasetZip').addEventListener('change', event => {
        const file = event.target.files[0];
        byId('zipSelection').textContent = file
            ? `${file.name} (${formatBytes(file.size)})`
            : 'Nenhum ZIP selecionado.';
    });

    const renderHistory = imports => {
        const body = byId('importsHistoryBody'); body.replaceChildren();
        for (const item of imports) {
            const row = document.createElement('tr');
            for (const value of [item.dataset_name, item.source_type, item.status, `${item.processed_samples}/${item.total_samples}`, item.rejected_samples]) {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            }
            const actionCell = document.createElement('td');
            const button = document.createElement('button'); button.type = 'button'; button.className = 'secondary'; button.textContent = 'Abrir';
            button.addEventListener('click', async () => { render(await request(`import-status&import_token=${encodeURIComponent(item.import_token)}`)); await refreshVisibleSamples(); });
            actionCell.append(button); row.append(actionCell); body.append(row);
        }
    };

    const fingerprint = async file => Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer())))
        .map(value => value.toString(16).padStart(2, '0')).join('');

    const folderManifest = async files => {
        const config = importCapabilities();
        const sorted = [...files].sort((a, b) => a.webkitRelativePath.localeCompare(b.webkitRelativePath));
        if (!sorted.length || sorted.length > config.maximum_files) throw new Error('Quantidade de arquivos fora do limite configurado.');
        localFiles = new Map();
        const manifest = [];
        for (let index = 0; index < sorted.length; index++) {
            const file = sorted[index];
            status.textContent = `Preparando manifesto ${index + 1}/${sorted.length}...`;
            const parts = file.webkitRelativePath.split('/').filter(Boolean);
            if (parts.length < 2) continue;
            const hash = await fingerprint(file);
            localFiles.set(hash, file);
            manifest.push({
                identity: parts[parts.length - 2], source_key: file.webkitRelativePath, fingerprint: hash,
                mime_type: file.type || 'application/octet-stream', byte_size: file.size,
                error: file.size > config.maximum_image_bytes ? 'image_too_large' : null
            });
        }
        return manifest;
    };

    const blobForSample = async sample => {
        if (current.source_type === 'folder') {
            const file = localFiles.get(sample.fingerprint);
            if (!file) throw new Error('Selecione novamente a mesma pasta para retomar esta importação.');
            return file;
        }
        const response = await fetch(`?route=api/import-image&import_token=${encodeURIComponent(current.import_token)}&sample_token=${encodeURIComponent(sample.sample_token)}`);
        if (!response.ok) throw new Error('Imagem temporária do ZIP não está disponível. Reenvie o mesmo ZIP para retomar.');
        return response.blob();
    };

    const refreshAfterFailure = async (sample, rejectionCode = null) => {
        if (rejectionCode) {
            return jsonPost('import-reject', {import_token: current.import_token, sample_token: sample.sample_token, failure_code: rejectionCode});
        }
        return request(`import-status&import_token=${encodeURIComponent(current.import_token)}`);
    };

    const prepareImage = async (blob, extractFaceApi = true) => {
        const config = importCapabilities();
        if (blob.size > config.maximum_image_bytes) throw new Error('Imagem maior que o limite.');
        const bitmap = await createImageBitmap(blob);
        try {
            if (bitmap.width < config.minimum_dimension || bitmap.height < config.minimum_dimension || bitmap.width * bitmap.height > config.maximum_pixels) {
                throw new Error('Dimensões da imagem fora do limite.');
            }
            const canvas = document.createElement('canvas'); canvas.width = bitmap.width; canvas.height = bitmap.height;
            canvas.getContext('2d').drawImage(bitmap, 0, 0);
            let embedding = null;
            let failure = null;
            if (extractFaceApi) {
                try { embedding = (await window.rfv2FaceApi.extractFromCanvas(canvas)).embedding; }
                catch (error) { failure = ['no_face', 'multiple_faces'].includes(error?.rfv2FailureCode) ? error.rfv2FailureCode : 'internal_failure'; }
            }
            return {embedding, failure, image: canvas.toDataURL('image/jpeg', .9)};
        } finally { bitmap.close(); }
    };

    const processPending = async () => {
        if (processing || !current || current.status !== 'running') return;
        processing = true;
        try {
            let sinceLastSampleRefresh = 0;
            while (current && current.status === 'running') {
                const queue = await samplePage('pending', processQueueSize);
                if (!queue.items.length) break;
                const ordered = queue.items.sort((a, b) => (a.split === b.split ? 0 : a.split === 'gallery' ? -1 : 1));
                for (const sample of ordered) {
                if (!current || current.status !== 'running') break;
                status.textContent = `Processando ${sample.identity} (${sample.split})...`;
                let blob;
                try {
                    blob = await blobForSample(sample);
                } catch (error) {
                    status.textContent = `${sample.identity}: ${error.message}`;
                    continue;
                }
                let prepared;
                try {
                    prepared = await prepareImage(blob, !sample.completed_architectures.includes('A'));
                } catch (error) {
                    try { render(await refreshAfterFailure(sample, 'invalid_image')); } catch (_) {}
                    status.textContent = `${sample.identity}: ${error.message}`;
                    continue;
                }
                try {
                    render(await jsonPost('import-process', {import_token: current.import_token, sample_token: sample.sample_token, faceapi_embedding: prepared.embedding, faceapi_failure: prepared.failure, image: prepared.image}));
                } catch (error) {
                    try {
                        render(await refreshAfterFailure(sample));
                    } catch (_) {}
                    status.textContent = `${sample.identity}: resultado não confirmado; retome para consultar e tentar novamente. ${error.message}`;
                    byId('resumeImport').disabled = current?.status !== 'running' && current?.status !== 'paused';
                    return;
                }
                sinceLastSampleRefresh++;
                if (sinceLastSampleRefresh >= processQueueSize || current?.status !== 'running') {
                    try { await refreshVisibleSamples(); } catch (_) {}
                    sinceLastSampleRefresh = 0;
                }
                }
            }
            try { await refreshVisibleSamples(); } catch (_) {}
            window.dispatchEvent(new CustomEvent('rfv2:identity-updated'));
        } finally { processing = false; }
    };

    byId('startFolderImport').addEventListener('click', async () => {
        try {
            const manifest = await folderManifest(byId('datasetFolder').files);
            render(await jsonPost('import-start', {dataset_name: byId('datasetName').value, manifest, ...limits()}));
            await refreshVisibleSamples();
            await processPending();
        } catch (error) { status.textContent = error.message; }
    });
    byId('startZipImport').addEventListener('click', async () => {
        try {
            const config = importCapabilities();
            const file = byId('datasetZip').files[0];
            if (!file) throw new Error('Selecione um arquivo ZIP.');
            if (file.size > config.maximum_archive_bytes) throw new Error(`ZIP maior que o limite configurado (${formatBytes(config.maximum_archive_bytes)}).`);
            const form = new FormData(); form.append('archive', file); form.append('dataset_name', byId('datasetName').value);
            form.append('source_split', byId('importSourceSplit').value);
            for (const [name, value] of Object.entries(limits())) form.append(name, String(value));
            render(await request('import-zip', {method: 'POST', body: form}));
            await refreshVisibleSamples();
            await processPending();
        } catch (error) { status.textContent = error.message; }
    });
    byId('pauseImport').addEventListener('click', async () => { if (current) render(await jsonPost('import-pause', {import_token: current.import_token})); });
    byId('resumeImport').addEventListener('click', async () => {
        if (!current) return;
        render(current.status === 'paused'
            ? await jsonPost('import-resume', {import_token: current.import_token})
            : await request(`import-status&import_token=${encodeURIComponent(current.import_token)}`));
        await refreshVisibleSamples();
        await processPending();
    });
    byId('cancelImport').addEventListener('click', async () => {
        if (current && window.confirm('Cancelar a importação? Amostras já processadas serão preservadas.')) { render(await jsonPost('import-cancel', {import_token: current.import_token})); await refreshVisibleSamples(); }
    });

    request('bootstrap').then(data => {
        csrfToken = data.csrf_token; capabilities = data.import;
        importCapabilities();
        renderHistory(data.recent_imports || []);
        byId('importMaxPeople').max = capabilities.maximum_people;
        byId('importMaxImages').max = capabilities.maximum_images_per_person;
        byId('startFolderImport').disabled = false;
        byId('startZipImport').disabled = !capabilities.zip_available;
    }).catch(error => {
        byId('startFolderImport').disabled = true;
        byId('startZipImport').disabled = true;
        status.textContent = `Importacao indisponivel: ${error.message}`;
    });
})();
