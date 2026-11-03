# Reconhecimento Facial V2

## Descrição do projeto

Aplicação local para cadastro e reconhecimento facial com persistência protegida de identidades, embeddings e métricas.

O projeto compara duas arquiteturas:

- **Arquitetura A:** utiliza JavaScript (Face API.js) para processamento facial no navegador e PHP para comparação e persistência.
- **Arquitetura B:** utiliza Python/FastAPI com OpenCV YuNet e SFace para processamento facial, mantendo a comparação e a persistência no PHP.

## Requisitos

- Windows com XAMPP (Apache, MySQL/MariaDB e PHP).
- PHP com as extensões `pdo_mysql`, `openssl` e `mbstring` habilitadas.
- Python 3.11 ou versão compatível e `pip`.
- Git para obter o projeto.
- Navegador moderno e webcam.

## Instalação

No PowerShell, acesse o diretório do XAMPP e clone o repositório:

```powershell
cd C:\xampp\htdocs\
git clone https://github.com/G28NF/facial-recognition-lab.git ReconhecimentoFacial-2
cd .\ReconhecimentoFacial-2
```

Se o projeto já estiver instalado, utilize o diretório existente.

## Configuração

### 1. Configurar o ambiente

Crie o arquivo `.env`:

```powershell
php .\bin\generate-local-env.php
```

Se o arquivo já existir, preserve a chave `RFV2_DATA_KEY`, necessária para descriptografar os dados existentes.

### 2. Configurar o banco de dados

Inicie o MySQL no painel do XAMPP e configure o `.env`:

```ini
RFV2_DB_HOST=127.0.0.1
RFV2_DB_PORT=3306
RFV2_DB_NAME=rf_v2
RFV2_DB_USER=rf_v2_app
RFV2_DB_PASSWORD=defina-uma-senha-local-segura
RFV2_DB_CHARSET=utf8mb4
```

Crie o banco e o usuário no MariaDB:

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS rf_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'rf_v2_app'@'127.0.0.1' IDENTIFIED BY 'defina-uma-senha-local-segura'; GRANT ALL PRIVILEGES ON rf_v2.* TO 'rf_v2_app'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

A senha utilizada no comando deve corresponder à configurada no `.env`. Caso o usuário já exista, confira suas credenciais e permissões.

Execute as migrations:

```powershell
php .\database\migrate.php
```

Se estiver migrando uma instalação anterior, faça backup e restaure os dados antes de validar a aplicação. Preserve a chave de criptografia original.

### 3. Configurar o Python

Prepare o ambiente Python:

```powershell
.\bin\setup-python.ps1
```

Instale os modelos faciais necessários nos diretórios indicados em:

- `public/assets/models/README.md` — Face API.js.
- `python-service/models/README.md` — YuNet e SFace.

## Execução

1. Inicie **Apache** e **MySQL** pelo painel do XAMPP.

2. Abra o PowerShell na pasta do projeto e execute:

   ```powershell
   .\bin\start-python.ps1
   ```

3. Verifique o serviço Python:

   ```powershell
   curl.exe http://127.0.0.1:8021/health
   ```

4. Acesse a aplicação no navegador:

   `http://localhost/ReconhecimentoFacial-2/public/`

Utilize a chave `RFV2_ACCESS_KEY` configurada no `.env` para acessar a interface e permita o uso da webcam quando solicitado.
