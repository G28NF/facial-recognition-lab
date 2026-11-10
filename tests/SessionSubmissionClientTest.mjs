import {readFileSync} from 'node:fs';

const script = readFileSync(new URL('../public/assets/js/app.js', import.meta.url), 'utf8');
const controller = readFileSync(new URL('../app/Controllers/ApiController.php', import.meta.url), 'utf8');
const continuous = readFileSync(new URL('../app/Services/ContinuousRecognitionService.php', import.meta.url), 'utf8');
const check = (condition, message) => { if (!condition) throw new Error(message); };

check(script.includes('let sequenceSubmissionStarted = false;'), 'A rodada nao distingue uma falha anterior de uma confirmacao ambigua.');
check(script.includes("request('session-warmup'"), 'session-warmup deixou de registrar a submissao de aquecimento.');
check(script.includes("request('session-frame-a'"), 'A nao possui requisicao de sessao independente.');
check(script.includes("request('session-frame-b'"), 'B nao possui requisicao de sessao independente.');
check(script.includes('if (!committed && !sequenceSubmissionStarted && session.state === \'running\')'), 'O fallback ainda pode registrar presence para uma sequencia ja submetida.');
check((script.match(/request\('session-presence'/g) || []).length === 1, 'session-presence nao ficou restrito ao fallback de falha do cliente.');
check(!script.includes('const detectPresence = async () =>'), 'A presenca Face API.js ainda bloqueia a rodada comparativa.');
check(script.includes('faceapi_embedding: face.embedding'), 'A nao envia somente o embedding para sua requisicao.');
check(script.includes('image: encoded.image'), 'B nao envia a imagem para sua requisicao.');
check(script.includes('http_roundtrip_ms: performance.now() - communicationStarted'), 'Round-trip HTTP individual nao esta instrumentado.');
check(script.includes('end_to_end_ms: performance.now() - totalStarted'), 'Latencia ponta a ponta individual nao esta instrumentada.');
check(script.includes('request_payload_bytes: payloadBytes(body)'), 'Bytes enviados nao sao registrados individualmente.');
check(script.includes("faceApiFailure('no_face'"), 'Ausencia de rosto nao possui classificacao explicita.');
check(script.includes("faceApiFailure('multiple_faces'"), 'Multiplos rostos nao possuem classificacao explicita.');
check(script.includes("error?.rfv2FailureCode") && script.includes("'technical_failure'"), 'Falhas tecnicas Face API.js nao sao classificadas separadamente.');
check(script.includes('faceapi_failure: face.failure ?? null'), 'Classificacao Face API.js nao e enviada ao servidor.');
check(script.includes('metricFinalizer.submit({session_token: session.token'), 'Falhas de finalizacao ainda podem ser ignoradas silenciosamente.');
check(!script.includes("try { await request('session-finalize'"), 'session-finalize ainda descarta erros sem controlar pendencias.');
check(script.includes("sequence % 2 === 1 ? ['A', 'B'] : ['B', 'A']"), 'A ordem A/B nao alterna por rodada.');
check(script.includes('if (!pythonPipelineWarmed) {'), 'O aquecimento de B ainda depende do aquecimento de A.');
check(controller.includes("'session-frame-a' => $this->sessionFrameA()"), 'Endpoint A de sessao nao foi registrado.');
check(controller.includes("'session-frame-b' => $this->sessionFrameB()"), 'Endpoint B de sessao nao foi registrado.');
check(continuous.includes('processArchitectureA') && continuous.includes('processArchitectureB'), 'Processamento independente de A/B nao foi implementado.');
check(continuous.includes("['no_face', 'multiple_faces']") && continuous.includes("'technical_failure'"), 'Servico nao preserva classificacao segura da Face API.js.');

console.log('OK - fallback de presenca protegido contra sequencia ja submetida');
