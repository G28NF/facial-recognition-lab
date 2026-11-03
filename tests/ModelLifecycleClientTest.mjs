import {readFileSync} from 'node:fs';

const script = readFileSync(new URL('../public/assets/js/app.js', import.meta.url), 'utf8');
const check = (condition, message) => { if (!condition) throw new Error(message); };

check(script.includes('if (modelsPromise) return modelsPromise;'), 'Face API.js pode iniciar mais de um carregamento no mesmo ciclo de pagina.');
check(script.includes('modelsPromise = (async () => {'), 'Carregamento unico dos modelos nao foi preservado.');
check(script.includes('faceapi.tf && typeof faceapi.tf.ready === \'function\''), 'Disponibilidade do runtime TensorFlow nao e medida separadamente.');
check(script.includes('model_load_ms: modelLoadMs') && script.includes('runtime_ready_ms: runtimeReadyMs'), 'Metricas de carregamento e runtime nao seguem para a sessao.');
check(script.includes('first_inference_ms: firstInferenceMs'), 'Primeira inferencia da Face API.js nao e identificada.');
check(script.includes('await loadModels();\n        const inferenceStarted = performance.now();'), 'A inferencia ainda pode incluir o carregamento do modelo na mesma medicao.');

console.log('OK - carregamento unico Face API.js e fases de ciclo de vida instrumentados');
