import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import '../public/assets/js/metric-finalizer.js';
import '../public/assets/js/scheduler.js';

const MetricFinalizer = globalThis.RFV2MetricFinalizer;
const payload = (architecture, frameSequence = 1) => ({
    session_token: '0123456789abcdef0123456789abcdef',
    frame_sequence: frameSequence,
    architecture,
    metrics: {browser_upload_ms: architecture === 'A' ? 3 : 7, http_roundtrip_ms: 10, end_to_end_ms: 20, request_payload_bytes: architecture === 'A' ? 512 : 4096}
});

const normalCalls = [];
const normal = new MetricFinalizer(async item => { normalCalls.push(structuredClone(item)); });
await normal.submit(payload('A'));
await normal.submit(payload('B'));
assert.deepEqual(normal.summary(), {pending: 0, exhausted: 0});
assert.equal(normalCalls.length, 2, 'A e B devem ser finalizadas independentemente.');
assert.deepEqual(normalCalls[0], payload('A'), 'A finalizacao deve preservar identificadores e metricas.');
await normal.submit(payload('A'));
assert.equal(normalCalls.length, 2, 'A mesma finalizacao concluida nao deve ser reenviada pelo cliente.');

const attempts = new Map();
const transient = new MetricFinalizer(async item => {
    const amount = (attempts.get(item.architecture) || 0) + 1;
    attempts.set(item.architecture, amount);
    if (item.architecture === 'A' && amount === 1) throw new Error('falha transitoria');
});
assert.equal((await transient.submit(payload('A'))).status, 'pending');
assert.equal((await transient.submit(payload('B'))).status, 'completed');
assert.deepEqual(transient.summary(), {pending: 1, exhausted: 0});
assert.deepEqual(await transient.retryPending(), {pending: 0, exhausted: 0});
assert.equal(attempts.get('A'), 2, 'A deve ser recuperada sem repetir B.');
assert.equal(attempts.get('B'), 1, 'Falha de A nao pode reenviar ou impedir B.');

let failedAttempts = 0;
const exhausted = new MetricFinalizer(async () => { failedAttempts++; throw new Error('indisponivel'); }, 3);
await exhausted.submit(payload('A', 2));
await exhausted.retryPending();
assert.deepEqual(await exhausted.retryPending(), {pending: 0, exhausted: 1});
await exhausted.retryPending();
assert.equal(failedAttempts, 3, 'O limite de tentativas deve impedir loops de reenvio.');

let retryCalls = 0;
const limited = new MetricFinalizer(async () => { retryCalls++; throw new Error('indisponivel'); });
await limited.submit(payload('A', 4));
await limited.submit(payload('B', 4));
await limited.retryPending();
assert.equal(retryCalls, 3, 'Cada rodada deve limitar o reenvio pendente a uma tentativa.');

let simulatedNow = 2000;
const cadence = new MetricFinalizer(async () => { simulatedNow += 1200; throw new Error('transitorio'); });
await cadence.submit(payload('A', 5));
simulatedNow = 2000;
await cadence.retryPending();
assert.deepEqual(globalThis.RFV2SchedulerPolicy.advance(2000, simulatedNow, 1000), {delayMs: 1200, missedIntervals: 1, nextDue: 4000}, 'O atraso do reenvio deve ser contabilizado pelo agendador.');
const app = readFileSync(new URL('../public/assets/js/app.js', import.meta.url), 'utf8');
assert.ok(app.indexOf('await metricFinalizer.retryPending();') < app.indexOf('const tickStarted = performance.now();'), 'O atraso do reenvio precisa entrar em scheduler_delay_ms.');
assert.ok(app.indexOf('const totalStarted = performance.now();') > app.indexOf('await metricFinalizer.retryPending();'), 'O reenvio nao pode entrar na metrica end_to_end_ms da arquitetura.');

let concurrentCalls = 0;
let release;
const gate = new Promise(resolve => { release = resolve; });
const concurrent = new MetricFinalizer(async () => { concurrentCalls++; await gate; });
const first = concurrent.submit(payload('B', 3));
const duplicate = concurrent.submit(payload('B', 3));
await Promise.resolve();
release();
await Promise.all([first, duplicate]);
assert.equal(concurrentCalls, 1, 'A mesma finalizacao nao pode ter requisicoes concorrentes.');

console.log('OK - finalizacao normal, idempotencia cliente, recuperacao, limite e independencia A/B');
