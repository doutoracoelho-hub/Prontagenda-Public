"""Ferramentas de consulta da agenda do Prontagenda."""

from datetime import date, datetime
from zoneinfo import ZoneInfo

from .client import consultar, erro


def _normalizar_data_brasileira(valor: str) -> str:
    """Converte YYYY-MM-DD, DD/MM/YYYY ou DD/MM para YYYY-MM-DD."""
    texto = valor.strip()
    hoje = datetime.now(ZoneInfo("America/Sao_Paulo")).date()

    for formato in ("%Y-%m-%d", "%d/%m/%Y"):
        try:
            return datetime.strptime(texto, formato).date().isoformat()
        except ValueError:
            pass

    try:
        partes = texto.split("/")
        if len(partes) != 2:
            raise ValueError
        dia, mes = (int(parte) for parte in partes)
        candidata = date(hoje.year, mes, dia)
        if candidata < hoje:
            candidata = date(hoje.year + 1, mes, dia)
        return candidata.isoformat()
    except (ValueError, TypeError):
        raise ValueError("DATA_INVALIDA") from None


def buscar_horarios_disponiveis(
    data: str,
    profissional: str,
    duracao_minutos: int | None = None,
) -> dict:
    """Consulta horários reais pelo nome ou ID do profissional e data brasileira."""
    try:
        data_normalizada = _normalizar_data_brasileira(data)
    except ValueError:
        return erro("DATA_INVALIDA", "Informe a data como DD/MM, DD/MM/AAAA ou AAAA-MM-DD.")

    params: dict[str, str | int] = {
        "data": data_normalizada,
    }
    profissional_texto = str(profissional).strip()
    if profissional_texto.isdigit():
        params["profissional_id"] = int(profissional_texto)
    else:
        params["profissional_nome"] = profissional_texto
    if duracao_minutos is not None:
        params["duracao_minutos"] = duracao_minutos

    return consultar("disponibilidade.php", params)
