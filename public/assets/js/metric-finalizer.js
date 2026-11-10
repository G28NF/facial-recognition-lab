(() => {
    'use strict';

    class MetricFinalizer {
        constructor(send, maximumAttempts = 3) {
            if (typeof send !== 'function' || !Number.isInteger(maximumAttempts) || maximumAttempts < 1) throw new TypeError('Finalizador de metricas invalido.');
            this.send = send;
            this.maximumAttempts = maximumAttempts;
            this.entries = new Map();
            this.completed = new Set();
        }

        key(payload) {
            return `${payload.session_token}:${payload.frame_sequence}:${payload.architecture}`;
        }

        submit(payload) {
            const key = this.key(payload);
            if (this.completed.has(key)) return Promise.resolve({status: 'completed'});
            if (!this.entries.has(key)) this.entries.set(key, {payload, attempts: 0, inFlight: null, exhausted: false});
            return this.attempt(key);
        }

        async retryPending(limit = 1) {
            let processed = 0;
            for (const [key, entry] of this.entries) {
                if (entry.exhausted) continue;
                await this.attempt(key);
                processed++;
                if (processed >= limit) break;
            }
            return this.summary();
        }

        attempt(key) {
            const entry = this.entries.get(key);
            if (!entry) return Promise.resolve({status: 'completed'});
            if (entry.inFlight) return entry.inFlight;
            if (entry.attempts >= this.maximumAttempts) {
                entry.exhausted = true;
                return Promise.resolve({status: 'exhausted'});
            }
            entry.attempts++;
            entry.inFlight = Promise.resolve()
                .then(() => this.send(entry.payload))
                .then(() => {
                    this.entries.delete(key);
                    this.completed.add(key);
                    return {status: 'completed'};
                })
                .catch(() => {
                    entry.exhausted = entry.attempts >= this.maximumAttempts;
                    return {status: entry.exhausted ? 'exhausted' : 'pending'};
                })
                .finally(() => { entry.inFlight = null; });
            return entry.inFlight;
        }

        summary() {
            let pending = 0;
            let exhausted = 0;
            for (const entry of this.entries.values()) entry.exhausted ? exhausted++ : pending++;
            return {pending, exhausted};
        }

        reset() {
            this.entries.clear();
            this.completed.clear();
        }
    }

    globalThis.RFV2MetricFinalizer = MetricFinalizer;
})();
