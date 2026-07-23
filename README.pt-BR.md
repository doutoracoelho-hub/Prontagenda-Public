<div align="center">
  <img src="docs/assets/prontagenda-banner.png" alt="ProntAgenda — gestão clínica, administrativa e financeira" width="100%">

  <br>
  <h1>ProntAgenda</h1>
  <p><strong>Software de gestão em nuvem para dentistas e clínicas odontológicas.</strong></p>

  <p><a href="README.md">🇺🇸 English</a> &nbsp;|&nbsp; 🇧🇷 Português</p>

  <p>
    <img src="https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white" alt="PHP 8.x">
    <img src="https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white" alt="MySQL">
    <img src="https://img.shields.io/badge/JavaScript-ES6-F7DF1E?logo=javascript&logoColor=black" alt="JavaScript ES6">
    <img src="https://img.shields.io/badge/Licen%C3%A7a-Direitos%20reservados-red" alt="Direitos reservados">
    <img src="https://img.shields.io/badge/Status-Ativo-success" alt="Ativo">
  </p>

  <p>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda/commits"><img src="https://img.shields.io/github/last-commit/doutoracoelho-hub/Prontagenda" alt="Último commit"></a>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda"><img src="https://img.shields.io/github/commit-activity/m/doutoracoelho-hub/Prontagenda" alt="Atividade de commits"></a>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda/issues"><img src="https://img.shields.io/github/issues/doutoracoelho-hub/Prontagenda" alt="Issues abertas"></a>
    <a href="https://github.com/doutoracoelho-hub/Prontagenda/releases"><img src="https://img.shields.io/github/v/release/doutoracoelho-hub/Prontagenda?display_name=tag&include_prereleases" alt="Última versão"></a>
    <img src="https://img.shields.io/github/languages/top/doutoracoelho-hub/Prontagenda" alt="Linguagem principal">
  </p>
</div>

## Índice

- [Sobre](#sobre)
- [Principais funcionalidades](#principais-funcionalidades)
- [Demonstração](#demonstração)
- [Tecnologias](#tecnologias)
- [Arquitetura](#arquitetura)
- [Instalação local](#instalação-local)
- [Segurança](#segurança)
- [Roadmap](#roadmap)
- [Autora](#autora)
- [Licença](#licença)

## Sobre

O **ProntAgenda** é uma plataforma completa em nuvem que centraliza os fluxos clínicos, administrativos e financeiros de dentistas e clínicas odontológicas, permitindo gerenciar toda a jornada do paciente — do agendamento à conclusão do tratamento — em um único sistema.

Desenvolvido tanto para profissionais autônomos quanto para organizações com várias clínicas, oferece operação multiempresa, acesso seguro para múltiplos usuários e permissões baseadas em funções.

## Aviso sobre o repositório

O ProntAgenda é um software proprietário.

Este repositório público contém apenas documentação, imagens e materiais de demonstração do produto. O código-fonte da aplicação é mantido em um repositório privado e não é distribuído publicamente.

## Principais funcionalidades

| Área | Recursos |
| --- | --- |
| **Agenda** | Calendário interativo, agendas de vários profissionais, recorrências, rótulos e suporte à recepção |
| **Pacientes** | Cadastro, anamnese, evoluções clínicas, histórico de consultas, imagens e anexos |
| **Odontograma** | Mapa interativo, faces dentárias, sincronização com tratamentos e dentições permanente/decídua |
| **Tratamentos** | Catálogo, orçamentos, procedimentos, histórico de evolução e profissional responsável |
| **Financeiro** | Contas a receber e pagar, fluxo de caixa, cartões, parcelas, comissões e painéis |
| **Laboratório** | Fluxo de prótese, ordens de serviço, etapas de entrega e controle de pagamentos |
| **Estoque** | Produtos, movimentações, consumo de materiais, equipamentos e depreciação |
| **Documentos** | Receitas, atestados, exames, contratos e documentos personalizados para impressão |
| **Plataforma** | Multiempresa, perfis de acesso, auditoria, sessões seguras e gestão de assinaturas |
| **Relatórios** | Relatórios financeiros, histórico de tratamentos, documentos para impressão e painéis |

## Demonstração

Uma demonstração completa do produto será disponibilizada em breve.

A apresentação mostrará:

- Agendamento de consultas
- Prontuário do paciente
- Odontograma interativo
- Gestão financeira
- Planejamento de tratamentos

<!-- Substitua este bloco por: <img src="docs/assets/prontagenda-demo.gif" alt="Demonstração do ProntAgenda" width="100%"> -->

## Tecnologias

- **Backend:** PHP 8+, PDO, MySQL/MariaDB e APIs em estilo REST
- **Frontend:** HTML5, CSS3, JavaScript ES6, AJAX e JSON
- **Bibliotecas:** FullCalendar, Flatpickr e PHPMailer
- **Integrações:** Google OAuth, Google Contacts API, Mercado Pago e Memed API

## Arquitetura

```text
                Navegador
                    │
                    ▼
              public_html/
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
      Páginas      API     Autenticação
        │           │           │
        └───────────┼───────────┘
                    ▼
                  src/
        ┌───────────┼───────────┐
        ▼           ▼           ▼
    Configuração   Auth       Helpers
        │           │           │
        └───────────┼───────────┘
                    ▼
                  MySQL
```

As páginas públicas e os clientes JavaScript se comunicam com endpoints PHP em estilo REST. Os módulos compartilhados de autenticação, configuração e helpers centralizam as regras e o acesso ao banco.

## Instalação local

### Pré-requisitos

- PHP 8.0+
- MySQL 5.7+, MySQL 8+ ou versão compatível do MariaDB
- Extensões PHP `pdo_mysql`, `mbstring`, `curl`, `json` e `openssl`
- Apache, Nginx ou servidor de desenvolvimento do PHP

### Configuração

1. Clone o repositório:

   ```bash
   git clone https://github.com/doutoracoelho-hub/Prontagenda.git
   cd Prontagenda
   ```

2. Crie o banco MySQL e importe a estrutura de banco do projeto.

3. Crie o arquivo `src/config/.env`:

   ```env
   APP_ENV=local
   APP_DEBUG=true

   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=prontagenda
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   DB_TZ=-03:00

   MP_ACCESS_TOKEN=
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_OAUTH_BASE_URL=http://localhost:8000
   GOOGLE_REDIRECT_URI=http://localhost:8000/auth/google/callback.php
   SMTP_USER=
   SMTP_PASS=
   MEMED_API_KEY=
   MEMED_SECRET_KEY=
   ```

4. Aponte o document root do servidor para `public_html` ou inicie o servidor de desenvolvimento:

   ```bash
   php -S localhost:8000 -t public_html
   ```

5. Acesse `http://localhost:8000`.

> Nunca envie `.env`, dumps de banco, logs, uploads ou credenciais de produção ao repositório. Em produção, use `APP_ENV=production` e `APP_DEBUG=false`.

## Segurança

- Configuração por variáveis de ambiente
- Consultas preparadas com PDO
- Middleware de autenticação e validação de sessão
- Autorização baseada em funções
- Isolamento de dados entre empresas
- Hash seguro de senhas com a API nativa do PHP
- Tokens CSRF em formulários protegidos
- Prevenção contra SQL Injection com consultas preparadas
- Cabeçalhos de segurança e proteção de uploads

Como o ProntAgenda processa dados pessoais e informações de saúde, a implantação em produção deve utilizar HTTPS, backups criptografados, privilégio mínimo no banco, políticas de auditoria e controles compatíveis com a LGPD.

## Roadmap

- [x] Gestão de pacientes e agenda
- [x] Gestão financeira
- [x] Odontograma interativo
- [x] Planejamento de tratamentos
- [x] Estoque e equipamentos
- [x] Módulos de ortodontia e laboratório de prótese
- [x] Integrações Google, Mercado Pago e Memed
- [ ] Aplicativo móvel
- [ ] Assistente clínico com inteligência artificial
- [ ] Portal do paciente
- [ ] Agendamento online

## Autora

**Monica Simões Coelho** — Cirurgiã-dentista e desenvolvedora de software

[github.com/doutoracoelho-hub](https://github.com/doutoracoelho-hub)

[www.prontagenda.com.br](https://www.prontagenda.com.br)

## Licença

Este projeto é um software proprietário. Todos os direitos são reservados. O código-fonte é disponibilizado somente para fins de portfólio. Uso comercial, redistribuição ou reprodução sem autorização são proibidos.
