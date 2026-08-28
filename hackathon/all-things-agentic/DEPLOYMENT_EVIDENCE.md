# Evidência de implantação — Google Cloud

Primeiro deploy validado em 24/08/2026.

## Recursos

- Projeto: `prontagenda-agent-hackathon`
- Cloud Build: `c482c12c-07bd-4895-9c50-e374a9c15b67`
- Artifact Registry: `prontagenda-hackathon`
- Serviço Cloud Run: `prontagenda-ai-hackathon`
- Região do Cloud Run: `southamerica-east1`
- Revisão: `prontagenda-ai-hackathon-00001-xht`
- URL: <https://prontagenda-ai-hackathon-676389265441.southamerica-east1.run.app>
- Imagem: `southamerica-east1-docker.pkg.dev/prontagenda-agent-hackathon/prontagenda-hackathon/prontagenda-ai:latest`

## Segurança e execução

- Identidade:
  `prontagenda-hackathon-agent@prontagenda-agent-hackathon.iam.gserviceaccount.com`
- Vertex AI acessado por credenciais temporárias da conta de serviço; não há
  chave Gemini nem chave JSON.
- Token Bearer injetado pelo Secret Manager
  (`prontagenda-ai-gateway-token:latest`).
- Escala configurada com mínimo zero e máximo uma instância.
- Concorrência: 8.
- Timeout da plataforma: 60 segundos.

## Verificações realizadas

```text
GET /health                         -> 200 {"status":"ok"}
POST /v1/whatsapp/respond sem token -> 401
```

O teste de saúde não acessou paciente, agenda, mensagens ou banco de dados. O
endpoint funcional não foi chamado nesta etapa porque a integração do backend
PHP ainda não recebeu o token do Secret Manager.

## Segunda revisão

Publicada e validada em 26/08/2026, sem alterar o VPS ou a configuração do
backend PHP em produção.

- Cloud Build: `461e34d6-ccfc-4c55-8898-a540f23a19fc`
- Revisão: `prontagenda-ai-hackathon-00002-kqd`
- Tráfego do serviço isolado do hackathon: 100% para a segunda revisão
- `GET /health`: `200 {"status":"ok"}`
- `POST /v1/whatsapp/respond` sem token: `401`

O Prontagenda continua apontando para o agente no VPS. A segunda revisão ainda
não recebeu tráfego funcional do backend PHP.

### Compatibilidade do gateway

Em 26/08/2026 foi criada a versão 2 de
`prontagenda-ai-gateway-token`, igual ao token já usado pelo backend PHP. A
revisão `prontagenda-ai-hackathon-00003-jwd` foi publicada para carregar essa
versão, sem modificar a Locaweb ou o VPS.

Teste autenticado direto no Cloud Run, sem ferramentas e sem envio ao WhatsApp:

```text
mensagem: Olá
resultado: sucesso=true
resposta: Olá. Como posso ajudar?
encaminhar_humano=false
```

Esse teste confirmou Bearer token, Cloud Run e Vertex AI. O teste integrado com
contexto assinado pelo PHP ainda será feito antes da troca temporária do gateway.

## Plano de retorno ao VPS

Antes do teste integrado, registrar sem expor segredos:

1. o valor atual de `PRONTAGENDA_AI_GATEWAY_URL` usado pelo backend;
2. qual serviço ou processo carrega essa configuração;
3. o comando de reinício desse processo;
4. uma verificação funcional no WhatsApp usando o VPS.

Para o teste do hackathon, alterar somente a URL do gateway e o token no ambiente
de teste controlado. Ao terminar, restaurar a URL e o token anteriores, reiniciar
o processo e repetir a verificação funcional. Não remover nem sobrescrever o
serviço do VPS durante a demonstração.
