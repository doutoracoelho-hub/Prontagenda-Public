"""Controles que impedem o modelo de reescrever respostas vindas do backend."""

from contextvars import ContextVar
from typing import Any

from google.adk.agents.callback_context import CallbackContext
from google.adk.models.llm_response import LlmResponse
from google.adk.tools import BaseTool, ToolContext
from google.genai import types


_resposta_controlada_atual: ContextVar[str] = ContextVar(
    "resposta_controlada_atual", default=""
)
_encerrar_fluxo_atual: ContextVar[bool] = ContextVar(
    "encerrar_fluxo_atual", default=False
)


def consumir_resposta_controlada() -> str:
    """Entrega o template ao gateway uma única vez."""
    template = _resposta_controlada_atual.get()
    if template:
        _resposta_controlada_atual.set("")
    return template


def consumir_encerramento_fluxo() -> bool:
    """Informa uma única vez que um agendamento foi realmente gravado."""
    encerrar = _encerrar_fluxo_atual.get()
    if encerrar:
        _encerrar_fluxo_atual.set(False)
    return encerrar


def resposta_controlada(
    tool: BaseTool,
    args: dict[str, Any],
    tool_context: ToolContext,
    tool_response: dict[str, Any],
) -> dict[str, Any] | None:
    """Guarda o template que substituirá a próxima saída do modelo."""
    template = tool_response.get("resposta_template")
    if not isinstance(template, str) or not template.strip():
        return None

    nome_tool = str(getattr(tool, "name", ""))
    if (
        nome_tool == "confirmar_novo_agendamento"
        and tool_response.get("sucesso") is True
        and isinstance(tool_response.get("agendamento"), dict)
    ):
        _encerrar_fluxo_atual.set(True)

    # O estado temporário do ADK pode não ser propagado até o callback da
    # resposta final em todas as versões. ContextVar mantém o template isolado
    # na árvore assíncrona desta requisição, sem vazar entre conversas.
    _resposta_controlada_atual.set(template.strip())
    # A resposta estruturada original continua disponível ao modelo para que ele
    # entenda IDs/opções. A saída textual dele será descartada pelo callback.
    return None


def aplicar_resposta_controlada(
    callback_context: CallbackContext,
    llm_response: LlmResponse,
) -> LlmResponse | None:
    """Substitui a redação do Gemini pelo template exato vindo do PHP."""
    template = consumir_resposta_controlada()
    if not template:
        return None
    return LlmResponse(
        content=types.Content(role="model", parts=[types.Part.from_text(text=template)]),
        turn_complete=True,
    )
