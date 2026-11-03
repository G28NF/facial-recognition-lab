# Reconhecimento Facial V2

## Descricao do projeto

Aplicacao local para cadastro e reconhecimento facial com persistencia protegida de identidades, embeddings e metricas. A arquitetura **A** executa reconhecimento no navegador com JavaScript e registra os resultados via PHP. A arquitetura **B** usa o servico Python/FastAPI para o processamento facial e o PHP para a aplicacao e persistencia.

## Requisitos

- Windows com XAMPP (Apache, MySQL ou MariaDB e PHP) instalado;
- PHP com extensoes `pdo_mysql`, `openssl` e `mbstring` habilitadas;
- Python 3.11 ou compativel e `pip`;
- Git, para obter o projeto;
- webcam e navegador moderno, para os fluxos de captura.

## Instalacao

No PowerShell, obtenha ou atualize o projeto no diretorio publico do XAMPP:

```powershell
cd C:\xampp\htdocs\RF
git clone <url-do-repositorio> ReconhecimentoFacial-2
cd .\ReconhecimentoFacial-2
```

Se o projeto ja estiver presente, entre no diretorio existente em vez de clona-lo. Nao copie um `.env` de outro ambiente sem preservar a chave de dados correspondente.

## Configuracao

1. Crie o arquivo de configuracao local sem exibir segredos:

   ```powershell
   php .\bin\generate-local-env.php
   ```

   Se `.env` ja existir, mantenha `RFV2_DATA_KEY` e ajuste somente a conexao necessaria. Nao gere uma nova chave para uma base que ja contenha dados criptografados.

2. Configure o banco no `.env` para o MySQL/MariaDB do XAMPP:

   ```ini
   RFV2_DB_HOST=127.0.0.1
   RFV2_DB_PORT=3306
   RFV2_DB_NAME=rf_v2
   RFV2_DB_USER=rf_v2_app
   RFV2_DB_PASSWORD=defina-uma-senha-local-segura
   RFV2_DB_CHARSET=utf8mb4
   ```

   Crie o banco e conceda acesso ao usuario configurado. As migrations usam `utf8mb4_unicode_ci`, compativel com o MariaDB distribuido pelo XAMPP:

   ```powershell
   & C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS rf_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'rf_v2_app'@'127.0.0.1' IDENTIFIED BY 'defina-uma-senha-local-segura'; GRANT ALL PRIVILEGES ON rf_v2.* TO 'rf_v2_app'@'127.0.0.1'; FLUSH PRIVILEGES;"
   php .\database\migrate.php
   ```

   Para migrar uma base anterior em Docker, gere e valide um dump antes de importar dados. Mantenha o container e o volume originais ate conferir a contagem das tabelas, os cadastros e os reconhecimentos no MariaDB do XAMPP.

3. Prepare o servico Python e baixe os modelos faciais conforme o script do projeto:

   ```powershell
   .\bin\setup-python.ps1
   ```

## Execucao

1. No painel do XAMPP, inicie **Apache** e **MySQL**.
2. Em outro PowerShell, inicie a API Python:

   ```powershell
   cd C:\xampp\htdocs\RF\ReconhecimentoFacial-2
   .\bin\start-python.ps1
   ```

3. Acesse `http://localhost/RF/ReconhecimentoFacial-2/public/` no navegador e permita o uso da webcam quando solicitado.
