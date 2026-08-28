# Prontagenda AI (hackathon)

Esta pasta contém a camada de IA adicionada ao Prontagenda pré-existente: agente Google ADK/Gemini e tools HTTP. O agente não acessa o MySQL. Regras de autorização, isolamento por empresa e consultas permanecem no backend PHP.

## Configuração

O agente interno e o canal externo usam credenciais diferentes. No backend PHP configure:

```env
PRONTAGENDA_AI_INTERNAL_API_TOKEN=gere-um-token-interno-longo
PRONTAGENDA_AI_INTERNAL_EMPRESA_ID=1
PRONTAGENDA_AI_WHATSAPP_CONTEXT_SECRET=gere-ao-menos-32-caracteres-aleatorios
```

No agente também configure:

```env
GOOGLE_GENAI_USE_VERTEXAI=FALSE
GOOGLE_API_KEY=sua-chave
PRONTAGENDA_API_BASE_URL=https://seu-dominio.example
PRONTAGENDA_AI_INTERNAL_API_TOKEN=o-mesmo-token-interno
PRONTAGENDA_AI_INTERNAL_EMPRESA_ID=1
```

Copie `prontagenda_agent/.env.example` para `prontagenda_agent/.env`; nunca versione credenciais reais. Instalação em PowerShell:

```powershell
cd ai-agent
py -m venv .venv
.\.venv\Scripts\Activate.ps1
py -m pip install -r requirements.txt
```

## Separação de confiança

- `prontagenda_agent` é o agente interno. Suas rotas ficam em `/api/ai/internal/*` e usam exclusivamente a credencial interna da empresa. Essa credencial nunca pode ser entregue ao navegador, webhook ou agente de WhatsApp.
- `prontagenda_whatsapp_agent` é externo. Ele usa um contexto HMAC curto, emitido no backend depois que o webhook autenticado já resolveu instância, empresa, conversa e telefone. O modelo nunca recebe parâmetros para escolher `empresa_id`, `paciente_id` ou telefone.
- Os services PHP de consulta são compartilhados, mas autenticação, contratos HTTP, tools e instruções são separados.

Em produção, a IA interna embutida na interface deverá ainda delegar a sessão/RBAC do usuário logado. O token interno desta etapa representa a empresa para ADK Web e não deve ser tratado como substituto definitivo da autorização individual de admin, profissional ou secretária.

## APIs internas somente leitura

Todas exigem `Authorization: Bearer <token>`. A empresa efetiva vem de `PRONTAGENDA_AI_INTERNAL_EMPRESA_ID` no servidor. O parâmetro `empresa_id` é apenas uma conferência de compatibilidade: se diferir da empresa do token, a API responde `403 EMPRESA_NAO_AUTORIZADA`.

- `GET /api/ai/internal/paciente.php`: aceita `telefone` (prioritário), `paciente_id` ou `nome`.
- `GET /api/ai/internal/agendamentos.php`: exige `paciente_id`; aceita `data`, `profissional_id` e `status`.
- `GET /api/ai/internal/disponibilidade.php`: exige `data` e um profissional.

Exemplos isolados (PowerShell):

```powershell
$headers = @{ Authorization = "Bearer $env:PRONTAGENDA_AI_INTERNAL_API_TOKEN"; Accept = "application/json" }
$base = $env:PRONTAGENDA_API_BASE_URL.TrimEnd('/')
Invoke-RestMethod "$base/api/ai/internal/paciente.php?telefone=31999999999&empresa_id=1" -Headers $headers
Invoke-RestMethod "$base/api/ai/internal/agendamentos.php?paciente_id=123&empresa_id=1" -Headers $headers
Invoke-RestMethod "$base/api/ai/internal/agendamentos.php?paciente_id=123&data=2026-08-19&empresa_id=1" -Headers $headers
Invoke-RestMethod "$base/api/ai/internal/disponibilidade.php?data=2026-08-21&profissional_id=3&empresa_id=1" -Headers $headers
```

Erros esperados incluem `NAO_AUTORIZADO` (401), `EMPRESA_NAO_AUTORIZADA` (403), `PACIENTE_NAO_ENCONTRADO` (404), `PACIENTE_AMBIGUO` (409), `PROFISSIONAL_AMBIGUO` (409), parâmetros inválidos (400) e falha interna genérica (500). Detalhes de banco são registrados apenas no log do servidor.

## Tools e ADK Web

As tools disponíveis são `buscar_paciente`, `buscar_agendamento` e `buscar_horarios_disponiveis`. As três chamam exclusivamente as APIs HTTPS acima.

```powershell
cd ai-agent
.\.venv\Scripts\Activate.ps1
adk web
```

Perguntas sugeridas:

- `Localize o paciente com telefone 31999999999.`
- `Quais são os próximos agendamentos desse paciente?`
- `A paciente Maria não consegue comparecer amanhã. Quais horários existem na sexta-feira depois das 15h?`
- `Quero remarcar minha consulta.`

O agente deve pedir dados ausentes, consultar antes de afirmar, pedir confirmação em ambiguidades e jamais dizer que reagendou: nesta etapa não existe tool de escrita.

## Canal WhatsApp

As rotas externas são:

- `GET /api/ai/whatsapp/me.php`: retorna somente o nome associado ao remetente.
- `GET /api/ai/whatsapp/agendamentos.php`: retorna somente consultas do remetente, sem IDs internos de paciente/profissional ou dados clínicos.
- `GET /api/ai/whatsapp/disponibilidade_reagendamento.php`: recebe um `agendamento_id` anteriormente retornado e uma data; o backend valida propriedade e deriva profissional/duração.
- `GET /api/ai/whatsapp/disponibilidade_nova_consulta.php`: aceita profissional e data para oferecer horários reais mesmo quando o remetente ainda não possui cadastro. Não cria paciente nem agendamento.
- `GET /api/ai/whatsapp/profissionais.php`: lista apenas ID e nome dos profissionais da empresa associada ao contexto.
- `GET /api/ai/whatsapp/expediente_profissional.php`: retorna apenas os dias e o expediente principal do profissional escolhido, agrupando dias consecutivos; não expõe intervalos nem ocupações.
- `GET /api/ai/whatsapp/proxima_disponibilidade.php`: procura no backend a primeira vaga que atende ao horário/período preferido e retorna também a primeira vaga absoluta quando ela permitir atendimento antes.

O backend emite o contexto com `WhatsAppAiContext::emitir($empresaId, $conversaId, $telefone)`. Ele expira em cinco minutos por padrão, é assinado com HMAC e é novamente confrontado com `whatsapp_conversas`. O token deve ser injetado em `PRONTAGENDA_WHATSAPP_CONTEXT_TOKEN` somente durante a execução daquela conversa. Não deve ser enviado ao usuário, persistido em mensagens ou registrado em logs.

Para visualizar o agente externo no ADK Web é possível usar um contexto de teste emitido para uma conversa controlada, nunca uma conversa real. Sem contexto, as tools recusam a consulta:

```powershell
cd ai-agent
.\.venv\Scripts\Activate.ps1
adk web
```

O `prontagenda_whatsapp_agent` não contém `buscar_paciente`, pesquisas de pacientes por nome ou listagem administrativa. Um paciente não identificado pode consultar disponibilidade para uma nova consulta; apenas o acesso a consultas pessoais permanece bloqueado.

Respostas que dependem do banco incluem `resposta_template`, montada em PHP. O callback `resposta_controlada` guarda esse texto e `aplicar_resposta_controlada` substitui integralmente a resposta subsequente do modelo pelo template. Isso é necessário porque o ADK Web não renderiza respostas de `FunctionTool` quando se usa apenas `skip_summarization`. Os dados estruturados permanecem no evento para sustentar a próxima escolha, mas a redação do Gemini é descartada. Em produção, o roteador do WhatsApp deve enviar `resposta_template` diretamente, sem nova chamada ao modelo.

### Gateway de produção

`gateway.py` expõe somente `GET /health` e `POST /v1/whatsapp/respond`. O endpoint
de resposta exige `Authorization: Bearer` com `PRONTAGENDA_AI_GATEWAY_TOKEN` de
no mínimo 32 caracteres. O contexto HMAC da conversa chega como `SecretStr` e é
injetado em um `ContextVar` durante aquela execução; ele não é salvo no SQLite
de sessões nem compartilhado entre requisições concorrentes. O `.env` usado no
ADK Web permanece apenas para testes controlados.

No VPS, use `production.env.example` como referência e o unit file
`deploy/prontagenda-ai.service`. O serviço executa com usuário sem privilégios,
grava somente em `/opt/prontagenda-ai/data`, usa um único worker para serializar
turnos da mesma conversa e escuta em `127.0.0.1:8000`. Nunca exponha o Uvicorn
diretamente; publique-o por proxy HTTPS autenticado quando o PHP estiver em
outro servidor.

Para testar no ADK Web, use exclusivamente uma conversa controlada já existente. O utilitário CLI emite um contexto de teste por duas horas; contextos normais de produção continuam expirando em cinco minutos e são renovados automaticamente a cada processamento:

```powershell
php ai-agent/scripts/emitir_contexto_whatsapp_teste.php --conversa=ID_DA_CONVERSA_DE_TESTE
# ou, usando seu próprio número que já enviou mensagem para a empresa:
php ai-agent/scripts/emitir_contexto_whatsapp_teste.php --telefone=DDDNUMERO --empresa=1
# forma recomendada: grava diretamente no .env sem exibir o token
php ai-agent/scripts/emitir_contexto_whatsapp_teste.php --telefone=DDDNUMERO --empresa=1 --salvar-env
```

O utilitário é somente CLI, consulta a conversa no banco e deriva empresa e telefone; ele não aceita esses dados como argumentos. Nunca use conversa de paciente real em demonstrações.

## Preparação do futuro reagendamento

Uma futura `reagendar_consulta(agendamento_id, novo_inicio)` deve ser um endpoint separado e transacional. Dentro de uma única transação, deverá bloquear o agendamento e o intervalo relevante (`SELECT ... FOR UPDATE` ou estratégia equivalente), validar empresa/status/permissão, recalcular disponibilidade no backend, gravar a alteração, registrar valores anterior/novo e ator de serviço em uma auditoria durável, e atualizar a fila de WhatsApp. A escrita só deve ocorrer após confirmação explícita do paciente e deve oferecer modo sandbox/dry-run para testes.

Não se reutiliza diretamente `mover_agendamento.php` para a IA: embora use transação e valide empresa, hoje ele não comprova disponibilidade/conflito nem registra uma auditoria de reagendamento. `salvar_agendamento.php` também não é uma fronteira apropriada para o token de serviço.
