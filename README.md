# wp-deployer-core

[![Versão](https://img.shields.io/badge/versão-1.0.0-blue.svg)](https://github.com/infografic/wp-deployer-core/releases)
[![Licença](https://img.shields.io/badge/licença-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.3-777bb4.svg)](https://www.php.net/)

Core reutilizável de [Deployer](https://deployer.org/) para projetos WordPress (**Bedrock** e
**Native**), incluindo tasks de provisionamento com EasyEngine, tasks de manutenção compartilhadas
e artefatos de CI/templates prontos para uso.

## O que o pacote contém

- **`deploy.php`** — entrypoint que carrega helpers, providers e todas as tasks. É o arquivo que o
  projeto consumidor inclui.
- **`src/helpers.php`** — funções auxiliares e adaptadores por tipo de projeto (`PROJECT_TYPE`).
- **`src/providers/easyengine.php`** — integração com EasyEngine.
- **`src/tasks/*.php`** — cadeia de deploy, provisionamento, backup e manutenção.
- **`templates/`** — exemplos reutilizáveis de `.gitattributes`, workflows do GitHub Actions e
  comandos `.ddev`, organizados por tipo (`bedrock`/`native`) e comuns (`common`).
- **`bin/sync-templates`** — binário que sincroniza os templates do pacote para o projeto consumidor.

## Requisitos

- PHP `>= 8.3`
- [`deployer/deployer`](https://packagist.org/packages/deployer/deployer) `^8.0`
- [`oscarotero/env`](https://packagist.org/packages/oscarotero/env) `^2.1`

## Instalação

No projeto consumidor, instale como dependência de desenvolvimento:

```bash
composer require --dev infografic/wp-deployer-core
```

Ou diretamente no `composer.json`:

```json
{
  "require-dev": {
    "infografic/wp-deployer-core": "^1.0"
  }
}
```

## Quick start

No `deploy.php` do projeto consumidor:

```php
<?php

namespace Deployer;

define('DEPLOY_ROOT', __DIR__);

// Configuração específica do projeto (hosts, secrets, env_required, etc.)
require __DIR__ . '/deploy/config.php';

// Core do pacote: carrega helpers, providers e todas as tasks
require __DIR__ . '/vendor/infografic/wp-deployer-core/deploy.php';
```

Os overrides específicos do projeto (configuração de hosts, secrets de CI, tasks customizadas)
permanecem no repositório consumidor. O pacote fornece apenas a base reutilizável.

## PROJECT_TYPE (Bedrock vs Native)

O comportamento do core se adapta ao tipo de projeto, definido pela variável de ambiente
`PROJECT_TYPE`:

- **`bedrock`** (padrão) — estrutura [Bedrock](https://roots.io/bedrock/), com `web/app` e
  WordPress versionado via Composer.
- **`native`** — instalação WordPress tradicional.

Defina no `.env` do projeto consumidor:

```dotenv
PROJECT_TYPE=bedrock
```

Valores inválidos fazem as tasks falharem cedo com mensagem explícita.

## Sincronizar templates

O pacote expõe um binário do Composer (`vendor/bin/sync-templates`) — **não é necessário copiar
nenhum arquivo** para o projeto consumidor. Basta declarar os scripts no `composer.json` do
consumidor:

```json
{
  "scripts": {
    "deploy:templates:sync": "sync-templates",
    "deploy:templates:sync:force": "sync-templates --force"
  }
}
```

E rodar a partir da raiz do projeto:

```bash
composer deploy:templates:sync          # cria/atualiza apenas o que está ausente
composer deploy:templates:sync:force    # sobrescreve arquivos divergentes
```

O comando lê `PROJECT_TYPE` (do ambiente ou do `.env`) e sincroniza:

| Origem (no pacote)                          | Destino (no consumidor)         |
| ------------------------------------------- | ------------------------------- |
| `templates/common/workflows/*.yml.example`  | `.github/workflows/*.yml`       |
| `templates/common/ddev-commands/`           | `.ddev/commands/`               |
| `templates/{type}/.gitattributes.example`   | `.gitattributes`                |
| `templates/{type}/ddev-commands/`           | `.ddev/commands/`               |

Por padrão, arquivos já existentes com conteúdo diferente são **ignorados** (preserva
customizações locais); use `--force` para sobrescrevê-los.

> O binário usa o diretório de trabalho atual como raiz do projeto. Para rodar de outro lugar,
> informe `sync-templates --root=/caminho/do/projeto`.

## Tasks disponíveis

| Domínio          | Tasks                                                                                              |
| ---------------- | -------------------------------------------------------------------------------------------------- |
| Deploy           | `deploy:validate:env`, `deploy:version:report`, `deploy:update_code`, `deploy:upload_vendors`, `deploy:update_releases_log` |
| Provisionamento  | `ee:provision`, `ee:provision:prepare`, `ee:provision:deploy`, `ee:provision:finalize`, `ee:site:create`, `ee:site:clean`, `ee:site:restart`, `ee:shell`, `ee:cron:create`, `provision:configure-deploy-target`, `provision:generate-shared-env`, `provision:setup-shared-wpconfig`, `nginx:custom-config` |
| Backup / Restore | `backup:run`, `backup:db`, `backup:files`, `backup:scripts`, `restore:latest`, `duplicati:backup:register`, `duplicati:backup:run` |
| Inicialização    | `init:data`, `init:db`, `init:db:replace-urls`, `init:uploads`, `init:webp`, `ddev:generate-init-data` |
| WordPress        | `wp:cache:flush`, `wp:core:update-db`, `wp:config:lock`, `wp:config:unlock`                        |

Liste todas as tasks no consumidor com:

```bash
vendor/bin/dep list
```

## Rastreabilidade de versão

Para registrar exatamente qual versão de pacote/templates está ativa, defina no consumidor/CI:

- `DEPLOY_PACKAGE_NAME`
- `DEPLOY_PACKAGE_VERSION`
- `DEPLOY_TEMPLATES_VERSION`

Esses valores são exibidos pela task `deploy:version:report` no início do deploy.

## Licença

[MIT](LICENSE) © Infografic
