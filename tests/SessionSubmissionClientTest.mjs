import {readFileSync} from 'node:fs';

const script = readFileSync(new URL('../public/assets/js/app.js', import.meta.url), 'utf8');
const check = (condition, message) => { if (!condition) throw new Error(message); };

check(script.includes('let sequenceSubmissionStarted = false;'), 'A rodada nao distingue uma falha anterior de uma confirmacao ambigua.');
check(script.includes('sequenceSubmissionStarted = true;\n                await request(\'session-presence\''), 'session-presence pode tentar um fallback apos submissao ambigua.');
check(script.includes('sequenceSubmissionStarted = true;\n                await request(\'session-warmup\''), 'session-warmup pode tentar um fallback apos submissao ambigua.');
check(script.includes('sequenceSubmissionStarted = true;\n            const data = await request(\'session-frame\''), 'session-frame pode acionar fallback apos submissao ambigua.');
check(script.includes('if (!committed && !sequenceSubmissionStarted && session.state === \'running\')'), 'O fallback ainda pode registrar presence para uma sequencia ja submetida.');

console.log('OK - fallback de presenca protegido contra sequencia ja submetida');
