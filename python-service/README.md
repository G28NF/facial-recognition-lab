# Servico de visao da arquitetura B

Servico interno FastAPI restrito a deteccao YuNet, alinhamento e extracao SFace. Os modelos sao
carregados uma vez no ciclo de vida do processo. A comparacao, persistencia e regras de negocio
permanecem no PHP.

Use `bin\setup-python.ps1` uma vez e `bin\start-python.ps1` para iniciar em primeiro plano.
`/extract` e `/warmup` exigem `X-Internal-Token`; somente `/health` e publico no listener local.
