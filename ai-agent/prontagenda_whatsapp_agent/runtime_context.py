"""Contexto efêmero da requisição atual, seguro para execuções concorrentes."""

from contextvars import ContextVar, Token


_whatsapp_context_token: ContextVar[str] = ContextVar(
    "prontagenda_whatsapp_context_token", default=""
)
_whatsapp_mensagem_atual: ContextVar[str] = ContextVar(
    "prontagenda_whatsapp_mensagem_atual", default=""
)


def definir_contexto_whatsapp(valor: str) -> Token[str]:
    """Define o token somente na árvore assíncrona da requisição atual."""
    return _whatsapp_context_token.set(valor)


def restaurar_contexto_whatsapp(token: Token[str]) -> None:
    """Remove o token ao terminar a requisição, inclusive quando houver erro."""
    _whatsapp_context_token.reset(token)


def obter_contexto_whatsapp() -> str:
    """Obtém o contexto da requisição sem compartilhá-lo com outras conversas."""
    return _whatsapp_context_token.get()


def definir_mensagem_whatsapp(valor: str) -> Token[str]:
    """Guarda somente a mensagem desta requisição para validações de segurança."""
    return _whatsapp_mensagem_atual.set(valor)


def restaurar_mensagem_whatsapp(token: Token[str]) -> None:
    _whatsapp_mensagem_atual.reset(token)


def obter_mensagem_whatsapp() -> str:
    return _whatsapp_mensagem_atual.get()
