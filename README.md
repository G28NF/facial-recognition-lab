# Reconhecimento Facial V2

## Descrição do projeto

Aplicação para cadastro e reconhecimento facial, desenvolvida para comparar duas arquiteturas:

- **Arquitetura A:** JavaScript (Face API.js) para processamento facial no navegador, com PHP para comparação e persistência.
- **Arquitetura B:** Python (OpenCV YuNet/SFace e FastAPI) para processamento facial, com PHP para comparação e persistência.

O sistema utiliza MariaDB para armazenar identidades, embeddings criptografados e métricas de desempenho.

## Requisitos

- XAMPP com Apache, PHP e MySQL/MariaDB.
- PHP com as extensões `pdo_mysql`, `openssl` e `mbstring`.
- Python 3.12 com pip.
- Git e navegador com acesso à webcam.

## Instalação

**1. Clone o repositório** na pasta `htdocs` do XAMPP:

```powershell
cd C:\xampp\htdocs
git clone https://github.com/G28NF/facial-recognition-lab.git ReconhecimentoFacial-2
cd ReconhecimentoFacial-2
```

**2. Instale as dependências Python:**

```powershell
.\bin\setup-python.ps1
```

**3. Baixe os modelos faciais:**

```powershell
.\bin\setup-models.ps1
```

O script baixa e valida automaticamente os sete arquivos do Face API.js e os dois modelos ONNX (YuNet e SFace). Arquivos válidos já existentes são preservados.

## Configuração

**1. Gere o arquivo `.env`:**

```powershell
php .\bin\generate-local-env.php
```

O script gera automaticamente as chaves de segurança e a senha inicial do banco de dados. Se o `.env` já existir, preserve-o para manter o acesso aos dados criptografados.

**2. Confira as configurações no `.env`:**

```ini
APP_BASE_URL=http://localhost/ReconhecimentoFacial-2/public

RFV2_DB_HOST=127.0.0.1
RFV2_DB_PORT=3306
RFV2_DB_NAME=rf_v2
RFV2_DB_USER=rf_v2_app
RFV2_DB_PASSWORD=<senha_gerada_no_env>
```

Ajuste o endereço da aplicação, as credenciais e a porta conforme seu ambiente.

**3. Inicie o MySQL pelo XAMPP e configure o banco:**

```powershell
php .\bin\setup-database.php
```

O script configura o banco e o usuário quando necessário e executa as migrations automaticamente. Caso precise de acesso administrativo, solicitará as credenciais no terminal.

As configurações e os dados existentes são preservados.

## Execução

**1.** Inicie **Apache** e **MySQL** pelo painel do XAMPP.

**2.** Abra um terminal na pasta do projeto e inicie o serviço Python:

```powershell
.\bin\start-python.ps1
```

Mantenha esse terminal aberto enquanto utilizar a aplicação.

**3.** Em outro terminal, verifique os serviços:

```powershell
curl.exe http://127.0.0.1:8021/health
curl.exe "http://localhost/ReconhecimentoFacial-2/public/?route=health"
```

**4.** Acesse a aplicação pelo navegador:

http://localhost/ReconhecimentoFacial-2/public/

Informe a chave `RFV2_ACCESS_KEY` disponível no `.env` quando solicitada e autorize o acesso à webcam.