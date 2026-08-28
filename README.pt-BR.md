<div align="center">

# Prontagenda AI Agent

### Agendamento autônomo para clínicas com Gemini e Google Cloud

**All Things Agentic Hackathon 2026 · Trilha Taskmaster**

[Prontagenda](https://www.prontagenda.com.br) · [English](README.md)

</div>

## O projeto

O Prontagenda AI Agent adiciona uma camada agêntica controlada a uma plataforma
real de gestão de clínicas. Pelo WhatsApp, o paciente pode consultar
profissionais e disponibilidade real, informar preferências, confirmar um
agendamento ou solicitar atendimento humano.

Não é apenas um chatbot. O Gemini escolhe a ação apropriada, o Google ADK chama
uma ferramenta autorizada e o backend do Prontagenda valida e registra o
resultado.

## Arquitetura

![Arquitetura segura do Prontagenda AI Agent](docs/assets/prontagenda-ai-architecture.png)

```text
Paciente <-> WhatsApp <-> Evolution API <-> roteador PHP do Prontagenda
                                               |
                                  Bearer token + contexto assinado
                                               v
                              Cloud Run / agente Google ADK
                                               |
                                     Vertex AI / Gemini
                                               |
                                   ferramentas autorizadas
                                               v
                                  APIs PHP do Prontagenda
                                               |
                                  regras de negócio + MySQL

Pedido inseguro, ambíguo ou não suportado -> fila de atendimento humano
```

O modelo não possui credenciais do banco e não escolhe `empresa_id`,
`paciente_id` nem o telefone do remetente. Identidade, autorização e regras de
negócio permanecem no backend confiável. Consulte [SECURITY.md](SECURITY.md).

## Tecnologias Google

- Google Agent Development Kit (ADK)
- Vertex AI
- Gemini 3.5 Flash-Lite
- Cloud Run
- Cloud Build
- Artifact Registry
- Secret Manager

## Estrutura pública

```text
ai-agent/                       agentes ADK, tools, gateway e testes
hackathon/all-things-agentic/  Docker, Cloud Build e deploy no Cloud Run
integration/php/               referência auditável da integração PHP
docs/assets/                    materiais públicos da apresentação
```

O núcleo proprietário do Prontagenda e todos os dados de produção ou pacientes
continuam privados. `integration/php/` é uma extração para avaliação da
fronteira de segurança, não uma cópia independente do produto completo.

## Executar os testes

É necessário Python 3.11 ou mais recente.

```powershell
cd ai-agent
py -m venv .venv
.\.venv\Scripts\Activate.ps1
py -m pip install -r requirements.txt
py -m unittest discover -s tests -v
```

Os testes usam valores sintéticos e não precisam de dados de pacientes.

## Executar o container localmente

Com o Docker Desktop aberto, execute na raiz do repositório:

```powershell
docker build -f hackathon/all-things-agentic/Dockerfile -t prontagenda-hackathon .
```

Copie `hackathon/all-things-agentic/cloudrun.env.example` para `local.env`,
preencha somente valores de teste e execute:

```powershell
docker run --rm -p 8080:8080 --env-file local.env prontagenda-hackathon
Invoke-RestMethod http://localhost:8080/health
```

O endpoint autenticado é `POST /v1/whatsapp/respond`. Ele exige um Bearer token
com pelo menos 32 caracteres e um contexto assinado de curta duração emitido
pelo backend do Prontagenda.

## Implantar no Google Cloud

Os arquivos reproduzíveis estão em
[`hackathon/all-things-agentic`](hackathon/all-things-agentic/README.md). Depois
de autenticar a CLI e selecionar seu próprio projeto:

```powershell
.\hackathon\all-things-agentic\deploy-cloud-run.ps1
```

Esse comando apenas mostra a configuração. O deploy só acontece ao adicionar
explicitamente `-Deploy`. Segredos reais devem ficar no Secret Manager e nunca
no Git.

## Documentação

- [Implementação do agente e das tools](ai-agent/README.md)
- [Guia de deploy](hackathon/all-things-agentic/README.md)
- [Evidências do deploy](hackathon/all-things-agentic/DEPLOYMENT_EVIDENCE.md)
- [Referência da integração PHP](integration/php/README.md)
- [Modelo de segurança](SECURITY.md)

## Autora e licença

Monica Simões Coelho — cirurgiã-dentista e desenvolvedora de software.

Este repositório é compartilhado para avaliação no hackathon e portfólio. O
Prontagenda continua sendo software proprietário. Consulte [License](License).
