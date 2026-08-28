"""Gateway de produção autenticado para o agente externo do Prontagenda."""

from __future__ import annotations

import asyncio
import logging
import os
import secrets
import time
from collections import defaultdict
from collections.abc import Mapping
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from google.adk.runners import Runner
from google.adk.sessions import DatabaseSessionService
from google.genai import types
from pydantic import BaseModel, Field, SecretStr

from prontagenda_whatsapp_agent.agent import root_agent
from prontagenda_whatsapp_agent.callbacks import (
    consumir_encerramento_fluxo,
    consumir_resposta_controlada,
)
from prontagenda_whatsapp_agent.runtime_context import (
    definir_contexto_whatsapp,
    definir_mensagem_whatsapp,
    restaurar_contexto_whatsapp,
    restaurar_mensagem_whatsapp,
)


APP_NAME = "prontagenda_whatsapp_agent"
GATEWAY_TOKEN = os.getenv("PRONTAGENDA_AI_GATEWAY_TOKEN", "").strip()
DB_PATH = Path(os.getenv("PRONTAGENDA_AI_SESSION_DB", "/opt/prontagenda-ai/data/sessions.db"))
DB_PATH.parent.mkdir(parents=True, exist_ok=True)

session_service = DatabaseSessionService(f"sqlite+aiosqlite:///{DB_PATH.as_posix()}")
runner = Runner(
    agent=root_agent,
    app_name=APP_NAME,
    session_service=session_service,
)
app = FastAPI(
    title="Prontagenda AI Gateway",
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)
_locks: defaultdict[str, asyncio.Lock] = defaultdict(asyncio.Lock)
logger = logging.getLogger("uvicorn.error")


class WhatsAppRequest(BaseModel):
    empresa_id: int = Field(gt=0)
    conversa_id: int = Field(gt=0)
    mensagem: str = Field(min_length=1, max_length=4000)
    contexto_token: SecretStr
    message_id: str | None = Field(default=None, max_length=255)
    estado_fluxo: str = Field(default="", max_length=80)
    profissional_id_selecionado: int | None = Field(default=None, gt=0)


class WhatsAppResponse(BaseModel):
    sucesso: bool
    resposta: str
    sessao_id: str
    encaminhar_humano: bool = False
    motivo_encaminhamento: str | None = None


ERROS_TECNICOS_TOOL = frozenset(
    {
        "API_TIMEOUT",
        "API_INDISPONIVEL",
        "RESPOSTA_INVALIDA",
        "ERRO_INTERNO",
        "CONTEXTO_NAO_CONFIGURADO",
    }
)


def _autorizar(authorization: str = Header(default="")) -> None:
    if len(GATEWAY_TOKEN) < 32:
        raise HTTPException(status_code=503, detail="Gateway não configurado")
    prefixo = "Bearer "
    recebido = authorization[len(prefixo) :].strip() if authorization.startswith(prefixo) else ""
    if not recebido or not secrets.compare_digest(recebido, GATEWAY_TOKEN):
        raise HTTPException(status_code=401, detail="Não autorizado")


def _texto_evento(evento: object) -> str:
    conteudo = getattr(evento, "content", None)
    partes = getattr(conteudo, "parts", None) or []
    return "".join(
        parte.text for parte in partes if isinstance(getattr(parte, "text", None), str)
    ).strip()


def _template_evento(evento: object) -> str:
    """Extrai a resposta controlada diretamente do retorno de uma tool.

    Em algumas versões do ADK, callbacks e ContextVars executados pela tool não
    retornam ao contexto do gerador. O evento, porém, preserva o
    function_response e permite finalizar o turno sem uma segunda chamada ao
    modelo.
    """
    conteudo = getattr(evento, "content", None)
    partes = getattr(conteudo, "parts", None) or []
    for parte in partes:
        function_response = getattr(parte, "function_response", None)
        response = getattr(function_response, "response", None)
        if not isinstance(response, Mapping):
            continue
        template = response.get("resposta_template")
        if isinstance(template, str) and template.strip():
            return template.strip()
    return ""


def _mensagem_com_contexto(payload: WhatsAppRequest) -> str:
    """Acrescenta somente estado interno confirmado pelo backend.

    O PHP pode concluir etapas determinísticas sem chamar o modelo. Repetir esse
    estado em cada turno evita depender da memória efêmera de uma instância do
    Cloud Run e impede que o agente volte a listar profissionais já escolhidos.
    """
    mensagem = payload.mensagem.strip()
    if payload.profissional_id_selecionado is None:
        return mensagem

    return (
        "[CONTEXTO INTERNO CONFIRMADO PELO PRONTAGENDA; NÃO MOSTRAR AO PACIENTE]\n"
        f"Estado do fluxo: {payload.estado_fluxo or 'ia_whatsapp'}.\n"
        f"O profissional já foi escolhido e seu ID é "
        f"{payload.profissional_id_selecionado}. Não liste nem pergunte novamente "
        "os profissionais. Preserve essa escolha nas próximas consultas de "
        "disponibilidade. Se a resposta trouxer somente um horário, peça somente "
        "o dia que falta. Se trouxer somente um dia, consulte esse dia conforme "
        "as regras do fluxo.\n"
        "[MENSAGEM DO PACIENTE]\n"
        f"{mensagem}"
    )


def _agendamento_confirmado_evento(evento: object) -> bool:
    conteudo = getattr(evento, "content", None)
    partes = getattr(conteudo, "parts", None) or []
    for parte in partes:
        function_response = getattr(parte, "function_response", None)
        response = getattr(function_response, "response", None)
        if (
            isinstance(response, Mapping)
            and response.get("sucesso") is True
            and isinstance(response.get("agendamento"), Mapping)
        ):
            return True
    return False


def _falha_tecnica_evento(evento: object) -> str | None:
    """Retorna o código técnico de uma tool sem depender de nova ação do modelo."""
    conteudo = getattr(evento, "content", None)
    partes = getattr(conteudo, "parts", None) or []
    for parte in partes:
        function_response = getattr(parte, "function_response", None)
        response = getattr(function_response, "response", None)
        if not isinstance(response, Mapping) or response.get("sucesso") is not False:
            continue
        codigo = str(response.get("erro", "")).strip().upper()
        if codigo in ERROS_TECNICOS_TOOL:
            return codigo
    return None


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post(
    "/v1/whatsapp/respond",
    response_model=WhatsAppResponse,
    dependencies=[Depends(_autorizar)],
)
async def responder(payload: WhatsAppRequest) -> WhatsAppResponse:
    inicio_total = time.perf_counter()
    sessao_id = f"empresa-{payload.empresa_id}-conversa-{payload.conversa_id}"
    usuario_id = f"empresa-{payload.empresa_id}"
    contexto = payload.contexto_token.get_secret_value().strip()
    if len(contexto) < 32:
        raise HTTPException(status_code=400, detail="Contexto inválido")

    async with _locks[sessao_id]:
        sessao = await session_service.get_session(
            app_name=APP_NAME, user_id=usuario_id, session_id=sessao_id
        )
        if sessao is None:
            await session_service.create_session(
                app_name=APP_NAME, user_id=usuario_id, session_id=sessao_id
            )

        mensagem_modelo = _mensagem_com_contexto(payload)
        marcador = definir_contexto_whatsapp(contexto)
        marcador_mensagem = definir_mensagem_whatsapp(payload.mensagem.strip())
        resposta = ""
        agendamento_confirmado = False
        falha_tecnica = None
        inicio_modelo = time.perf_counter()
        try:
            mensagem = types.Content(
                role="user", parts=[types.Part.from_text(text=mensagem_modelo)]
            )
            try:
                async with asyncio.timeout(22):
                    async for evento in runner.run_async(
                        user_id=usuario_id,
                        session_id=sessao_id,
                        new_message=mensagem,
                    ):
                        if _agendamento_confirmado_evento(evento):
                            agendamento_confirmado = True
                        codigo_tecnico = _falha_tecnica_evento(evento)
                        if codigo_tecnico is not None:
                            falha_tecnica = codigo_tecnico
                        template = (
                            consumir_resposta_controlada()
                            or _template_evento(evento)
                        )
                        if template:
                            resposta = template
                            break
                        texto = _texto_evento(evento)
                        if texto and getattr(evento, "is_final_response", lambda: False)():
                            resposta = texto
            except TimeoutError as exc:
                logger.warning(
                    "metric=whatsapp_turn empresa=%d conversa=%d resultado=timeout model_ms=%d total_ms=%d",
                    payload.empresa_id,
                    payload.conversa_id,
                    round((time.perf_counter() - inicio_modelo) * 1000),
                    round((time.perf_counter() - inicio_total) * 1000),
                )
                raise HTTPException(
                    status_code=504,
                    detail="O agente excedeu o tempo limite de resposta",
                ) from exc
            except Exception as exc:
                logger.exception(
                    "metric=whatsapp_turn empresa=%d conversa=%d resultado=erro_modelo model_ms=%d total_ms=%d",
                    payload.empresa_id,
                    payload.conversa_id,
                    round((time.perf_counter() - inicio_modelo) * 1000),
                    round((time.perf_counter() - inicio_total) * 1000),
                )
                raise HTTPException(
                    status_code=503,
                    detail="O serviço de inteligência artificial está temporariamente indisponível",
                ) from exc
        finally:
            restaurar_mensagem_whatsapp(marcador_mensagem)
            restaurar_contexto_whatsapp(marcador)

        encerrar_por_callback = consumir_encerramento_fluxo()
        if agendamento_confirmado or encerrar_por_callback:
            await session_service.delete_session(
                app_name=APP_NAME,
                user_id=usuario_id,
                session_id=sessao_id,
            )
            logger.info(
                "metric=whatsapp_flow empresa=%d conversa=%d evento=agendamento_concluido sessao=encerrada",
                payload.empresa_id,
                payload.conversa_id,
            )

    if not resposta:
        logger.warning(
            "metric=whatsapp_turn empresa=%d conversa=%d resultado=sem_resposta model_ms=%d total_ms=%d",
            payload.empresa_id,
            payload.conversa_id,
            round((time.perf_counter() - inicio_modelo) * 1000),
            round((time.perf_counter() - inicio_total) * 1000),
        )
        raise HTTPException(status_code=502, detail="O agente não produziu uma resposta")
    logger.info(
        "metric=whatsapp_turn empresa=%d conversa=%d resultado=ok model_ms=%d total_ms=%d",
        payload.empresa_id,
        payload.conversa_id,
        round((time.perf_counter() - inicio_modelo) * 1000),
        round((time.perf_counter() - inicio_total) * 1000),
    )
    return WhatsAppResponse(
        sucesso=True,
        resposta=resposta,
        sessao_id=sessao_id,
        encaminhar_humano=falha_tecnica is not None,
        motivo_encaminhamento=(
            f"falha_tecnica_ia:{falha_tecnica.lower()}" if falha_tecnica else None
        ),
    )
