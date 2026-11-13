import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const view = readFileSync(new URL('../app/Views/home.php', import.meta.url), 'utf8');
const script = readFileSync(new URL('../public/assets/js/app.js', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../public/assets/css/app.css', import.meta.url), 'utf8');

assert.ok(view.includes('id="customizeMetrics"') && view.includes('id="metricPreferencesModal"') && view.includes('id="metricPreferencesList"'), 'A interface de personalizacao de metricas nao esta disponivel.');
assert.ok(script.includes("metricPreferenceStorageKey = 'rfv2:visible-result-metrics'") && script.includes('window.localStorage.setItem(metricPreferenceStorageKey'), 'A selecao de metricas nao e persistida localmente.');
assert.ok(script.includes("key: 'browser_upload_ms'") && script.includes("key: 'browser_download_ms'") && script.includes("key: 'http_roundtrip_ms'") && script.includes("key: 'end_to_end_ms'"), 'As metricas de comunicacao nao estao disponiveis para selecao.');
assert.ok(script.includes("key: 'image_encode_ms'") && script.includes("label: 'Codificação da imagem'") && script.includes("key: 'candidate_prepare_ms', category: 'Processamento'"), 'As métricas de processamento nao estao organizadas conforme a interface.');
assert.ok(script.includes("data-bs-toggle', 'tooltip'") && script.includes('globalThis.bootstrap.Tooltip.getOrCreateInstance'), 'Os tooltips Bootstrap das metricas nao foram inicializados.');
assert.ok(script.includes("const info = document.createElement('span');") && script.includes("info.textContent = 'ⓘ';") && script.includes("info.tabIndex = 0;"), 'O icone informativo discreto e acessivel nao foi configurado.');
assert.ok(script.includes("format === 'bytes'") && script.includes("format === 'count'") && script.includes("return `${numeric.toFixed(3)} ms`;"), 'As unidades das metricas nao sao formatadas corretamente.');
assert.ok(styles.includes('.metric-option-grid { grid-template-columns: 1fr; }') && styles.includes('.metric-customize-button { width: 100%; }'), 'A personalizacao de metricas nao possui ajuste para telas estreitas.');
assert.ok(styles.includes('grid-template-columns: auto minmax(0, 1fr) auto') && styles.includes('.metric-preferences-modal .modal-body { max-height:'), 'O alinhamento das opcoes e a rolagem do modal nao foram configurados.');
assert.ok(styles.includes('scroll-padding-block: .75rem') && styles.includes('scroll-margin-block: .75rem') && styles.includes('font-size: .68rem'), 'O modal nao possui rolagem com categorias compactas e espacos uniformes.');
assert.ok(styles.includes('.metric-preferences-modal .modal-content { --bs-modal-bg: #000;') && styles.includes('background: #000; }'), 'O modal nao segue o fundo preto da pagina.');

console.log('OK - preferencias de metricas, tooltips, unidades e responsividade declarados');
