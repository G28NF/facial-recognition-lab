(() => {
    'use strict';

    /**
     * Executes a same-origin JSON request and observes only the browser upload lifecycle.
     * `uploadMs` is null when the browser did not report a completed upload.
     */
    const sendJson = (url, {headers = {}, body, signal} = {}) => new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        let uploadStartedAt = null;
        let uploadMs = null;
        let settled = false;

        const finish = (callback, value) => {
            if (settled) return;
            settled = true;
            signal?.removeEventListener('abort', abort);
            callback(value);
        };
        const abort = () => xhr.abort();
        const transportFailure = () => finish(reject, new Error('Falha de comunicação.'));

        xhr.upload.addEventListener('loadstart', () => { uploadStartedAt = performance.now(); }, {once: true});
        xhr.upload.addEventListener('load', () => {
            if (uploadStartedAt !== null) uploadMs = performance.now() - uploadStartedAt;
        }, {once: true});
        xhr.upload.addEventListener('error', () => { uploadMs = null; }, {once: true});
        xhr.upload.addEventListener('abort', () => { uploadMs = null; }, {once: true});
        xhr.upload.addEventListener('timeout', () => { uploadMs = null; }, {once: true});
        xhr.addEventListener('load', () => {
            try {
                const data = JSON.parse(xhr.responseText || '{}');
                if (xhr.status < 200 || xhr.status >= 300) {
                    finish(reject, Object.assign(new Error(data.error || 'Falha de comunicação.'), {status: xhr.status}));
                    return;
                }
                finish(resolve, {data, uploadMs});
            } catch (_) {
                finish(reject, new Error('Resposta inválida do servidor.'));
            }
        });
        xhr.addEventListener('error', transportFailure, {once: true});
        xhr.addEventListener('abort', transportFailure, {once: true});
        xhr.addEventListener('timeout', transportFailure, {once: true});
        if (signal?.aborted) {
            transportFailure();
            return;
        }
        signal?.addEventListener('abort', abort, {once: true});
        xhr.open('POST', url, true);
        for (const [name, value] of Object.entries(headers)) xhr.setRequestHeader(name, value);
        xhr.send(body);
    });

    globalThis.RFV2UploadRequest = Object.freeze({sendJson});
})();
