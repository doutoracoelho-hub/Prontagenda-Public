# Prontagenda AI — All Things Agentic Hackathon

Esta pasta isola todo o trabalho de empacotamento e implantação do hackathon.
Ela não substitui o serviço atual do Prontagenda e não altera o fluxo que está
em produção.

## Projeto Google Cloud

- Project ID: `prontagenda-agent-hackathon`
- Configuração local da CLI: `prontagenda-hackathon`
- Região do Cloud Run: `southamerica-east1` (São Paulo)
- Faturamento: vinculado a uma conta exclusiva/controlada por orçamento

APIs habilitadas em 24/08/2026:

- Cloud Run (`run.googleapis.com`)
- Cloud Build (`cloudbuild.googleapis.com`)
- Artifact Registry (`artifactregistry.googleapis.com`)
- Secret Manager (`secretmanager.googleapis.com`)
- Gemini API (`generativelanguage.googleapis.com`)
- Vertex AI (`aiplatform.googleapis.com`)

Identidade de execução:

- Conta de serviço:
  `prontagenda-hackathon-agent@prontagenda-agent-hackathon.iam.gserviceaccount.com`
- Papel concedido: `roles/aiplatform.user`
- Não há chave JSON nem chave da Gemini API.
- O Cloud Run fica em `southamerica-east1`; o Gemini 3.5 Flash-Lite usa o
  endpoint Vertex AI `global`, que é uma localização suportada pelo modelo.

Recursos preparados:

- Artifact Registry: `prontagenda-hackathon` em `southamerica-east1`
- Secret Manager: `prontagenda-ai-gateway-token` (valor nunca versionado)
- Build: `cloudbuild.yaml`
- Deploy controlado: `deploy-cloud-run.ps1`

O script não faz alterações sem a opção explícita `-Deploy`. Para conferir os
parâmetros:

```powershell
.\hackathon\all-things-agentic\deploy-cloud-run.ps1
```

Para construir no Cloud Build e publicar uma revisão no Cloud Run:

```powershell
gcloud config configurations activate prontagenda-hackathon
.\hackathon\all-things-agentic\deploy-cloud-run.ps1 -Deploy
```

O serviço usa escala a zero, no máximo uma instância e a conta de serviço de
privilégio mínimo. O endpoint HTTP do Cloud Run é alcançável sem IAM para
permitir a chamada do backend PHP, mas `/v1/whatsapp/respond` continua protegido
pelo token Bearer armazenado no Secret Manager. `/health` não acessa pacientes,
agenda ou banco de dados.

O primeiro deploy e suas verificações estão registrados em
[`DEPLOYMENT_EVIDENCE.md`](DEPLOYMENT_EVIDENCE.md).

Para entrar e sair do contexto do hackathon na CLI:

```powershell
gcloud config configurations activate prontagenda-hackathon
gcloud config configurations activate default
```

## Regra de isolamento

- `ai-agent/` continua sendo o agente usado pelo sistema atual.
- `public_html/` e `src/` continuam sendo o backend atual.
- arquivos específicos de Google Cloud, Cloud Run, demonstração e submissão
  ficam somente em `hackathon/all-things-agentic/`.
- credenciais reais nunca devem ser gravadas nesta pasta ou versionadas.

O container usa o código atual de `ai-agent/` como base, sem copiá-lo ou
modificá-lo. Adaptações exclusivas do Cloud Run devem ser implementadas nesta
pasta, preservando o comportamento de produção.

## Teste local do container

Execute os comandos a partir da raiz do repositório:

```powershell
docker build -f hackathon/all-things-agentic/Dockerfile -t prontagenda-hackathon .
docker run --rm -p 8080:8080 --env-file hackathon/all-things-agentic/local.env prontagenda-hackathon
```

Antes do segundo comando, copie `cloudrun.env.example` para `local.env` e
preencha somente credenciais de teste. `local.env` é ignorado pelo Git.

Verificação:

```powershell
Invoke-RestMethod http://localhost:8080/health
```

## Como voltar ao fluxo normal

Não é necessário desfazer nada. Pare o container do hackathon com `Ctrl+C` e
continue iniciando o Prontagenda exatamente como antes. O serviço atual não lê
nenhum arquivo desta pasta.

Para conferir que nenhuma parte do sistema atual foi alterada:

```powershell
git status --short
```

As mudanças do hackathon devem aparecer apenas sob
`hackathon/all-things-agentic/`. Alterações em outros caminhos pertencem ao
fluxo normal e não devem ser descartadas ao trabalhar no hackathon.

## Próximas etapas

1. Testar o container localmente.
2. Criar o projeto no Google Cloud.
3. Guardar segredos no Secret Manager.
4. Publicar este container no Cloud Run.
5. Registrar prova do deploy e logs para o vídeo.
6. Adicionar aqui o diagrama e os materiais da submissão.
