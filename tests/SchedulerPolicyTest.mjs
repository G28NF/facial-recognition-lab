import assert from 'node:assert/strict';
import '../public/assets/js/scheduler.js';

const policy = globalThis.RFV2SchedulerPolicy;
assert.deepEqual(policy.advance(1000, 1000, 1000), {delayMs: 0, missedIntervals: 0, nextDue: 2000});
assert.deepEqual(policy.advance(2000, 4500, 1000), {delayMs: 2500, missedIntervals: 2, nextDue: 5000});
assert.deepEqual(policy.advance(5000, 5050, 1000), {delayMs: 50, missedIntervals: 0, nextDue: 6000});
assert.throws(() => policy.advance(0, 0, 0), TypeError);
console.log('OK - relógio monotônico descarta intervalos sem criar fila');
