import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../public/assets/js/upload-request.js', import.meta.url), 'utf8');
const listenerTarget = () => {
    const listeners = new Map();
    return {
        addEventListener(name, callback) { const list = listeners.get(name) || []; list.push(callback); listeners.set(name, list); },
        removeEventListener(name, callback) { listeners.set(name, (listeners.get(name) || []).filter(item => item !== callback)); },
        emit(name) { for (const callback of listeners.get(name) || []) callback(); }
    };
};
let now = 0;
let mode = 'success';
let request;
let resourceEntries = [];
class FakeXHR {
    constructor() { Object.assign(this, listenerTarget()); this.upload = listenerTarget(); this.headers = {}; }
    open(method, url) { this.method = method; this.url = url; }
    setRequestHeader(name, value) { this.headers[name] = value; }
    send(body) {
        this.body = body; request = this;
        this.upload.emit('loadstart'); now += mode === 'slow' ? 120 : 8;
        if (mode === 'success' || mode === 'slow' || mode === 'without-timing') {
            this.upload.emit('load');
            now += 250; // processamento/resposta posterior nao pode contaminar o upload
            if (mode !== 'without-timing') resourceEntries.push({initiatorType: 'xmlhttprequest', startTime: 0, responseStart: now - 40, responseEnd: now - 10});
            this.status = 200; this.responseText = '{"architecture":"B"}'; this.emit('load');
        } else {
            this.upload.emit(mode);
            this.emit(mode);
        }
    }
    abort() { this.upload.emit('abort'); this.emit('abort'); }
}
const context = vm.createContext({XMLHttpRequest: FakeXHR, URL, location: {href: 'http://localhost/ReconhecimentoFacial-2/public/'}, performance: {now: () => now, getEntriesByName: () => resourceEntries}});
vm.runInContext(source, context);
const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': 'csrf'};
const result = await context.RFV2UploadRequest.sendJson('?route=api/session-frame-b', {headers, body: '{"image":"jpeg"}'});
assert.equal(result.uploadMs, 8, 'Atraso de processamento/resposta entrou no tempo de upload.');
assert.equal(result.downloadMs, 30, 'Intervalo de recebimento da resposta nao foi obtido do Resource Timing.');
assert.equal(result.data.architecture, 'B');
assert.equal(request.method, 'POST');
assert.equal(request.headers['X-CSRF-Token'], 'csrf');
assert.equal(request.body, '{"image":"jpeg"}');
mode = 'slow'; now = 0;
const slowResult = await context.RFV2UploadRequest.sendJson('?route=api/session-frame-b', {headers, body: '{"image":"jpeg"}'});
assert.equal(slowResult.uploadMs, 120, 'Limitacao de upload simulada nao foi refletida na metrica.');
assert.equal(slowResult.downloadMs, 30, 'Download nao foi separado do upload lento.');
mode = 'without-timing'; now = 0;
const unavailableTiming = await context.RFV2UploadRequest.sendJson('?route=api/session-frame-b', {headers, body: '{"image":"jpeg"}'});
assert.equal(unavailableTiming.downloadMs, null, 'Medicao de download ausente nao pode ser estimada.');
for (const failure of ['error', 'abort', 'timeout']) {
    mode = failure; now = 0;
    await assert.rejects(context.RFV2UploadRequest.sendJson('?route=api/session-frame-a', {headers, body: '{"embedding":[1]}'}), /Falha de comunicação/);
}
console.log('OK - upload XHR separado da resposta e falhas nao concluem medicao');
