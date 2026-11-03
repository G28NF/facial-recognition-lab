# Pesos Face API.js locais

Este diretorio deve conter os pesos usados pela arquitetura A. Eles sao carregados pelo navegador e
nao sao versionados neste repositorio. Obtenha-os somente da origem oficial, depois de revisar os
termos aplicaveis aos pesos e aos dados de treinamento:

<https://github.com/justadudewhohacks/face-api.js/tree/master/weights>

Copie para este diretorio exatamente estes arquivos:

- `tiny_face_detector_model-weights_manifest.json`
- `tiny_face_detector_model-shard1`
- `face_landmark_68_model-weights_manifest.json`
- `face_landmark_68_model-shard1`
- `face_recognition_model-weights_manifest.json`
- `face_recognition_model-shard1`
- `face_recognition_model-shard2`

O codigo usa `face-api.js@0.22.2` com SRI; a biblioteca upstream esta sob MIT. Esta lista nao
autoriza incluir imagens, embeddings, datasets ou outros artefatos de experimento no repositorio.
