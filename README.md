# Reconhecimento Facial V2

Base independente, em PHP puro, para comparar duas arquiteturas de reconhecimento
continuo por video:

- **A:** Face API.js extrai no navegador e PHP compara;
- **B:** OpenCV YuNet + SFace extrai em Python e PHP compara.

O cadastro pela webcam gera, na mesma identidade, embeddings Face API.js e OpenCV SFace. A
arquitetura A extrai no navegador; a B envia a captura ao servico Python, que executa somente
YuNet/SFace. Comparacao 1:N, persistencia e regras de negocio permanecem no PHP. O reconhecimento
continuo, o painel de desempenho e a importacao local de datasets estao implementados.

O modo contínuo usa a mesma captura para A e B, monitora a presença a cada intervalo configurado
(1 segundo por padrao) e nao cria fila: uma nova rodada so e agendada depois da anterior. Atrasos
maiores que o intervalo descartam os instantes vencidos e incrementam `missed_intervals`.

## Requisitos

- PHP 8.2+ com `pdo_mysql`, `openssl`, `mbstring`, `fileinfo` e `zip`;
- Apache/XAMPP com a raiz do projeto acessivel;
- Docker Desktop com Docker Compose.
- Python 3.12 (o script de preparo cria o ambiente virtual com esta versao).

### Pesos de modelos de terceiros

Os pesos nao sao versionados neste repositorio. Eles nao sao dados biometricos de usuarios,
mas a redistribuicao fica fora deste projeto para que cada instalacao confira os termos nas fontes
oficiais. Antes de iniciar o FastAPI ou abrir a interface autenticada, baixe somente os arquivos
abaixo, confira as licencas e coloque-os nos caminhos indicados. Nenhum dataset ou imagem de pessoa
deve ser adicionado a esses diretorios.

- Face API.js (arquitetura A): os tres manifests e quatro shards listados em
  `public/assets/models/README.md`, obtidos do diretorio `weights` do
  [face-api.js](https://github.com/justadudewhohacks/face-api.js). O projeto upstream usa MIT;
  confira tambem a proveniencia dos pesos antes de usa-los.
- YuNet e SFace (arquitetura B):
  `face_detection_yunet_2023mar.onnx` e `face_recognition_sface_2021dec.onnx` em
  `python-service/models/`, conforme `python-service/models/README.md`. O OpenCV Zoo informa MIT
  para YuNet e Apache-2.0 para SFace.

Os arquivos de instrucoes nesses diretorios registram URLs oficiais e nomes exatos. Eles nao
baixam nada automaticamente; reveja os termos antes da obtencao.

## Configuracao e inicio manual

Os tres processos sao iniciados separadamente: Apache/PHP pelo XAMPP, MySQL pelo Docker Compose e
FastAPI pelo PowerShell. Nenhum deles e iniciado automaticamente pela aplicacao.

1. Inicie **Apache** no XAMPP Control Panel e mantenha o Docker Desktop em execucao.
2. Instale manualmente os pesos indicados acima e, na raiz da V2, crie o ambiente local uma unica vez:

   ```powershell
   php bin\generate-local-env.php
   bin\setup-python.ps1
   ```

   O gerador cria `.env` com senhas e chave aleatorias sem exibi-las. O arquivo e ignorado pelo Git.
3. Inicie o MySQL e aplique somente migrations ainda nao registradas:

   ```powershell
   docker compose --env-file .env up -d
   docker compose --env-file .env ps
   php database\migrate.php
   ```

   O Compose publica somente em `127.0.0.1` e usa a porta configuravel `RFV2_DB_PORT` (3310 no
   exemplo), o container exclusivo `rf-v2-mysql` e o volume exclusivo `rf_v2_mysql_data`.
4. Em outro PowerShell, inicie o FastAPI e mantenha essa janela aberta durante o uso da aplicacao:

   ```powershell
   bin\start-python.ps1
   ```

   O script usa `RFV2_PYTHON_HOST` e `RFV2_PYTHON_PORT` do `.env` (por padrao,
   `127.0.0.1:8021`) e inicia o servidor em primeiro plano. Ele e necessario para a arquitetura B
   (YuNet/SFace), cadastro com os dois modelos, importacao e aquecimento comparativo.
5. Verifique os servicos antes de abrir a webcam:

   ```powershell
   curl.exe http://127.0.0.1:8021/health
   curl.exe http://localhost/RF/ReconhecimentoFacial-2/public/?route=health
   ```

   O primeiro comando deve responder pelo FastAPI; o segundo deve retornar HTTP 200 com banco
   disponivel. Entao acesse a URL definida por `APP_BASE_URL`. A configuracao de exemplo aponta
   para `http://localhost/RF/ReconhecimentoFacial-2/public`, mas nenhum caminho do codigo depende
   desse nome.

O acesso biometrico exige a chave
`RFV2_ACCESS_KEY` do `.env`; a sessao usa cookie `HttpOnly`/`SameSite=Strict` e requisicoes de
escrita exigem CSRF. A API tambem rejeita clientes que nao sejam loopback.

## Painel de desempenho

O painel autenticado apresenta estatisticas gerais e por sessao para A e B, evolucao temporal,
medias por etapa e pares do mesmo `session_group_id` e `frame_sequence`. Quadros sem uma das
arquiteturas sao exibidos como incompletos e nunca comparados com outro quadro.

As estatisticas de latencia usam somente `pipeline_total_ms` numerico e nao negativo de eventos
`recognized`, `unknown` ou `inconclusive`. Falhas (`error`) continuam contabilizadas, mas ficam fora
dos calculos de latencia. P95 usa *nearest rank* e o desvio-padrao e populacional. Inicializacao,
aquecimento, monitoramento de presenca e tempo comparativo permanecem categorias separadas; valores
ausentes permanecem ausentes, sem conversao para zero.

O CSV pode exportar uma sessao ou todo o historico. Registros `recognition` contem os eventos e suas
etapas; registros `session_metric` preservam inicializacao, aquecimento e presenca separadamente.
Nenhuma imagem, nome ou embedding e exportado. A exclusao por sessao e a limpeza geral exigem
autenticacao, origem local, CSRF e confirmacao na interface; sessoes ativas nao podem ser apagadas.
Essas operacoes removem somente sessoes, eventos e metricas experimentais, preservando identidades,
modelos e embeddings.

## Gerenciamento de pessoas

A area autenticada **Pessoas cadastradas** permite pesquisar, consultar os modelos disponiveis,
renomear a identidade e ativar ou desativar cada amostra facial. Nomes continuam criptografados e
as alteracoes exigem sessao local e CSRF.

A exclusao de uma pessoa e logica: a identidade e todas as suas amostras deixam de participar do
reconhecimento, mas os vinculos de datasets e os eventos experimentais permanecem integros. Por
seguranca, a interface exige duas confirmacoes e nomes de identidades excluidas continuam reservados.
Nao ha restauracao de pessoas pela interface atual.

## Importacao de datasets

A area autenticada de importacao aceita uma pasta local ou um ZIP com estrutura LFW
`raiz/identidade/imagem`. Nenhum dataset e baixado automaticamente. Os limites de pessoas, imagens,
tamanho, dimensoes, quantidade de arquivos e expansao ZIP sao configurados pelas variaveis
`RFV2_IMPORT_*` descritas em `.env.example`.

As imagens sao processadas sequencialmente. Face API.js usa no navegador o mesmo pipeline do
cadastro e o servico Python existente produz SFace. Cada imagem `gallery` grava os dois embeddings
na galeria na mesma transacao. Imagens `probe` usam armazenamento criptografado separado e nunca
entram na busca 1:N. O fingerprint do arquivo impede que uma mesma imagem seja usada novamente ou
apareca nos dois conjuntos dentro da importacao.

ZIPs sao inspecionados entrada por entrada: caminhos absolutos, `..`, links simbolicos, expansao
excessiva e taxas de compressao suspeitas sao rejeitados. Os arquivos ficam em uma pasta aleatoria
sob o diretorio temporario configurado, fora de `public`, sao entregues somente a uma sessao local
autenticada e removidos ao processar, rejeitar, cancelar ou concluir o lote. Para retomar uma pasta
apos recarregar a pagina, selecione novamente a mesma pasta; o manifesto idempotente reaproveita o
job e nao duplica amostras.

O LFW possui termos e limitacoes proprios. A disponibilidade publica do conjunto nao constitui
autorizacao irrestrita, consentimento biometrico ou adequacao a uma finalidade. Verifique a licenca,
a base legal, as restricoes institucionais e a autorizacao aplicavel antes de importar qualquer
imagem. A aplicacao nao mede nem declara precisao do dataset nesta etapa.

## Roteiro de experimento local

1. Inicie o MySQL com `docker compose --env-file .env up -d`, aplique `php database\migrate.php`
   e inicie o servico facial com `bin\start-python.ps1`.
2. Acesse `APP_BASE_URL`, entre com a chave local `RFV2_ACCESS_KEY` e confirme que a pagina indica
   banco e servico facial disponiveis.
3. Para um experimento pequeno pela webcam, cadastre uma pessoa com consentimento explicito. O
   cadastro cria uma amostra Face API.js e uma SFace para a mesma identidade.
4. Para um dataset autorizado, selecione uma pasta ou ZIP no formato
   `raiz/identidade/imagem`. Configure pelo menos uma imagem `gallery` e uma `probe` por pessoa.
   Gallery entra na busca 1:N; probe fica criptografada e separada para avaliacao futura. Nao use a
   mesma imagem nos dois conjuntos.
5. Com a webcam, inicie uma sessao comparativa. A e B recebem o mesmo quadro; aqueça os modelos
   antes das medições oficiais e encerre a sessao ao finalizar a coleta.
6. Consulte **Desempenho e metricas** para pares pelo mesmo quadro e exporte o CSV da sessao ou do
   historico. Limpe metricas somente depois de exportar: a limpeza preserva identidades, modelos e
   embeddings.

Uma webcam funcional e imagens cuja utilizacao esteja autorizada sao necessarias para validar um
experimento real. A ausencia de reconhecimento em uma amostra nao mede precisao, e eventos periodicos
de uma mesma sessao nao devem ser tratados como amostras independentes de acuracia.

## Comparacao 1:N e cache

A comparacao consulta apenas IDs e embeddings do modelo selecionado. O nome criptografado nao faz
parte da varredura: ele e consultado e descriptografado somente para a identidade efetivamente
reconhecida. Com varias amostras, o PHP preserva apenas a menor distancia por identidade e encontra
os dois melhores candidatos sem ordenar toda a galeria.

As etapas sao registradas separadamente como `mysql_query_ms`, `candidate_read_ms`,
`embedding_decrypt_ms`, `candidate_prepare_ms`, `comparison_ms` (somente distancia matematica),
`candidate_selection_ms`, tempos de recuperacao do nome, `comparison_overhead_ms` e
`comparison_total_ms`. Os componentes sao disjuntos; `comparison_total_ms` e o agregado e nao deve
ser somado novamente. `pipeline_total_ms` continua sendo a referencia completa do pipeline.

O XAMPP local verificado nao possui APCu nem WinCache. PHP comum encerra a memoria de usuario ao fim
da requisicao, e OPcache nao e um cache mutavel de dados. Por isso nao foi criado cache estatico,
de sessao ou em arquivo: seriam ineficazes entre requisicoes ou persistiriam biometria descriptografada
de forma inadequada. Um cache compartilhado so deve ser ativado futuramente com extensao de memoria
compatível, limites, TTL e versao coordenada pelo MySQL.

## Estrutura

- `app/Controllers`, `app/Models`, `app/Services`, `app/Views`: MVC e casos de infraestrutura;
- `app/Infrastructure`: PDO e criptografia autenticada;
- `config`: configuracoes externas carregadas do `.env`;
- `database/migrations`: schema MySQL reproduzivel;
- `public`: unico ponto de entrada web e assets;
- `python-service`: deteccao/alinhamento YuNet e extracao SFace da arquitetura B;
- `tests`: verificacao local e integracao real com MySQL.

## Dados sensiveis

Nomes e embeddings usam AES-256-GCM com chaves derivadas por finalidade. A busca exata de nomes
usa HMAC e nao grava o nome em texto puro. Imagens faciais nao fazem parte do schema. Em producao,
injete `RFV2_DATA_KEY` por um cofre de segredos, mantenha backups da chave e planeje rotacao pelo
`key_id`; perder a chave torna os dados irrecuperaveis.

Os limiares configuraveis preservam os valores de referencia: distancia quadrada `0.36` para
Face API.js e `1.3225` para SFace normalizado. A comparacao percorre somente embeddings ativos do
mesmo modelo usando o indice `ix_embeddings_model_active`, mantendo em memoria apenas a melhor
amostra por identidade. Isso evita carregar todos os vetores ao mesmo tempo, mas nao constitui
benchmark de 20 mil pessoas; essa escala ainda deve ser medida com dados representativos.

Para encerrar apenas a V2 sem apagar dados:

```powershell
docker compose --env-file .env down
```
