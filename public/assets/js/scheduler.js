(() => {
    'use strict';
    const advance = (nextDue, now, intervalMs) => {
        if (![nextDue, now, intervalMs].every(Number.isFinite) || intervalMs <= 0) throw new TypeError('Relogio monotônico invalido.');
        const delayMs = Math.max(0, now - nextDue);
        const missedIntervals = Math.floor(delayMs / intervalMs);
        return {
            delayMs,
            missedIntervals,
            nextDue: nextDue + (missedIntervals + 1) * intervalMs
        };
    };
    globalThis.RFV2SchedulerPolicy = Object.freeze({advance});
})();
