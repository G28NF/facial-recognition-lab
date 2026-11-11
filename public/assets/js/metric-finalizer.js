(() => {
    'use strict';

    class MetricFinalizer {
        constructor(send, maximumAttempts = 3, persistence = null) {
            if (typeof send !== 'function' || !Number.isInteger(maximumAttempts) || maximumAttempts < 1) {
                throw new TypeError('Finalizador de metricas invalido.');
            }
            this.send = send;
            this.maximumAttempts = maximumAttempts;
            this.entries = new Map();
            this.completed = new Set();
            this.persistence = persistence
                && typeof persistence.getItem === 'function'
                && typeof persistence.setItem === 'function'
                && typeof persistence.removeItem === 'function'
                ? persistence
                : null;
            this.restore();
        }

        key(payload) {
            return `${payload.session_token}:${payload.frame_sequence}:${payload.architecture}`;
        }

        submit(payload) {
            const key = this.key(payload);
            if (this.completed.has(key)) {
                return Promise.resolve({status: 'completed'});
            }
            if (!this.entries.has(key)) {
                this.entries.set(key, {payload, attempts: 0, inFlight: null, exhausted: false});
                this.persist();
            }
            return this.attempt(key);
        }

        async retryPending(limit = 1) {
            let processed = 0;
            for (const [key, entry] of this.entries) {
                if (entry.exhausted) {
                    continue;
                }
                await this.attempt(key);
                processed++;
                if (processed >= limit) {
                    break;
                }
            }
            return this.summary();
        }

        attempt(key) {
            const entry = this.entries.get(key);
            if (!entry) {
                return Promise.resolve({status: 'completed'});
            }
            if (entry.inFlight) {
                return entry.inFlight;
            }
            if (entry.attempts >= this.maximumAttempts) {
                entry.exhausted = true;
                return Promise.resolve({status: 'exhausted'});
            }
            entry.attempts++;
            entry.inFlight = Promise.resolve()
                .then(() => this.send(entry.payload))
                .then(() => {
                    this.entries.delete(key);
                    this.persist();
                    this.completed.add(key);
                    return {status: 'completed'};
                })
                .catch(() => {
                    entry.exhausted = entry.attempts >= this.maximumAttempts;
                    this.persist();
                    return {status: entry.exhausted ? 'exhausted' : 'pending'};
                })
                .finally(() => {
                    entry.inFlight = null;
                });
            return entry.inFlight;
        }

        summary() {
            let pending = 0;
            let exhausted = 0;
            for (const entry of this.entries.values()) {
                entry.exhausted ? exhausted++ : pending++;
            }
            return {pending, exhausted};
        }

        reset() {
            this.entries.clear();
            this.completed.clear();
            this.persistence?.removeItem();
        }

        restore() {
            if (!this.persistence) {
                return;
            }
            try {
                const entries = JSON.parse(this.persistence.getItem() || '[]');
                if (!Array.isArray(entries)) {
                    return;
                }
                for (const payload of entries) {
                    if (!payload || typeof payload.session_token !== 'string'
                        || !Number.isInteger(payload.frame_sequence)
                        || !['A', 'B'].includes(payload.architecture)) {
                        continue;
                    }
                    this.entries.set(this.key(payload), {payload, attempts: 0, inFlight: null, exhausted: false});
                }
            } catch (_) {
                this.persistence.removeItem();
            }
        }

        persist() {
            if (!this.persistence) {
                return;
            }
            try {
                const entries = [...this.entries.values()].map(entry => entry.payload);
                if (entries.length === 0) {
                    this.persistence.removeItem();
                } else {
                    this.persistence.setItem(entries);
                }
            } catch (_) {
                // The in-memory queue still prevents duplicate submission in this page.
            }
        }
    }

    globalThis.RFV2MetricFinalizer = MetricFinalizer;
})();
