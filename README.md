# Reconhecimento Facial V2

## Descrição do projeto

Aplicação para cadastro e reconhecimento facial, desenvolvida para comparar duas arquiteturas:

- **Arquitetura A:** JavaScript (Face API.js) para processamento facial no navegador e PHP para comparação e persistência.
- **Arquitetura B:** Python (OpenCV YuNet/SFace e FastAPI) para processamento facial e PHP para comparação e persistência.

O sistema utiliza MariaDB para armazenar identidades, embeddings criptografados e métricas de desempenho.

## Requisitos

- XAMPP com Apache, PHP e MySQL/MariaDB.
- PHP com `pdo_mysql`, `openssl` e `mbstring`.
- Python 3.12 e pip.
- Git e navegador com acesso à webcam.

## Instalação

**1. Clone o repositório** dentro da pasta `htdocs` do XAMPP:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/G28NF/facial-recognition-lab.git ReconhecimentoFacial-2
cd ReconhecimentoFacial-2
```

**2. Configure o ambiente Python:**

```powershell
.\bin\setup-python.ps1
```

**3. Instale manualmente os modelos faciais**, seguindo as instruções dos respectivos arquivos:

- [Face API.js](public/assets/models/README.md): sete arquivos em `public/assets/models/`.
- [YuNet e SFace](python-service/models/README.md): dois arquivos ONNX em `python-service/models/`.

Os modelos não são baixados automaticamente.

## Configuração

**1. Gere o arquivo `.env`:**

```powershell
php .\bin\generate-local-env.php
```

Se já existir um `.env`, mantenha-o. A chave `RFV2_DATA_KEY` deve ser preservada para acessar dados criptografados existentes.

**2. Configure o endereço da aplicação e o banco no `.env`:**

```ini
APP_BASE_URL=http://localhost/ReconhecimentoFacial-2/public

RFV2_DB_HOST=127.0.0.1
RFV2_DB_PORT=3306
RFV2_DB_NAME=rf_v2
RFV2_DB_USER=rf_v2_app
RFV2_DB_PASSWORD=sua_senha
```

Ajuste `APP_BASE_URL` se o projeto estiver em outro diretório.

**3. Inicie o MySQL pelo XAMPP e crie o banco e o usuário:**

```powershell
& C:\xampp\mysql\bin\mysql.exe -u root -p
```

```sql
CREATE DATABASE rf_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rf_v2_app'@'127.0.0.1' IDENTIFIED BY 'sua_senha';
GRANT ALL PRIVILEGES ON rf_v2.* TO 'rf_v2_app'@'127.0.0.1';
EXIT;
```

Utilize a mesma senha definida no `.env`. Se o banco ou usuário já existirem, aproveite a configuração existente, sem recriá-los.

**4. Execute as migrations:**

```powershell
php .\database\migrate.php
```

## Execução

**1.** Inicie Apache e MySQL no painel do XAMPP.

**2.** Abra um PowerShell na pasta do projeto e inicie o FastAPI:

```powershell
.\bin\start-python.ps1
```

**3.** Verifique os serviços:

```powershell
curl.exe http://127.0.0.1:8021/health
curl.exe "http://localhost/ReconhecimentoFacial-2/public/?route=health"
```

**4.** Acesse:

http://localhost/ReconhecimentoFacial-2/public/

Utilize a chave `RFV2_ACCESS_KEY` disponível no `.env` para autenticação e permita o acesso à webcam.

Mantenha o FastAPI em execução durante o uso da aplicação.