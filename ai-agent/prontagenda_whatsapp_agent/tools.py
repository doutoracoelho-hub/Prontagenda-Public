"""Tools externas: a identidade vem somente do contexto assinado da conversa."""

import os
import logging
import re
import time
import unicodedata
from datetime import date, datetime
from typing import Literal
from zoneinfo import ZoneInfo

import requests

from .runtime_context import obter_contexto_whatsapp, obter_mensagem_whatsapp


logger = logging.getLogger("uvicorn.error")


def _erro(codigo: str, mensagem: str) -> dict:
    return {
        "sucesso": False,
        "erro": codigo,
        "mensagem": mensagem,
        "resposta_template": mensagem,
    }


def _data_iso(valor: str) -> str:
    texto = valor.strip()
    hoje = datetime.now(ZoneInfo("America/Sao_Paulo")).date()
    for formato in ("%Y-%m-%d", "%d/%m/%Y"):
        try:
            return datetime.strptime(texto, formato).date().isoformat()
        except ValueError:
            pass
    partes = texto.split("/")
    if len(partes) != 2:
        raise ValueError
    candidata = date(hoje.year, int(partes[1]), int(partes[0]))
    if candidata < hoje:
        candidata = date(hoje.year + 1, candidata.month, candidata.day)
    return candidata.isoformat()


def _dias_semana_csv(valor: str | None) -> str | None:
    if not valor or not valor.strip():
        return None
    texto = unicodedata.normalize("NFKD", valor.lower()).encode("ascii", "ignore").decode()
    mapa = {
        "segunda": 1, "seg": 1, "terca": 2, "ter": 2, "quarta": 3, "qua": 3,
        "quinta": 4, "qui": 4, "sexta": 5, "sex": 5, "sabado": 6, "sab": 6,
        "domingo": 7, "dom": 7,
    }
    encontrados: list[int] = []
    for parte in re.split(r"\s*(?:,|;|/|\bou\b|\be\b)\s*", texto):
        chave = parte.strip().replace("-feira", "")
        if not chave:
            continue
        if chave.isdigit() and 1 <= int(chave) <= 7:
            encontrados.append(int(chave))
        elif chave in mapa:
            encontrados.append(mapa[chave])
        else:
            raise ValueError(f"Dia da semana inválido: {parte.strip()}")
    return ",".join(str(dia) for dia in sorted(set(encontrados))) or None


def _consultar(endpoint: str, params: dict | None = None) -> dict:
    inicio = time.perf_counter()
    base_url = os.getenv("PRONTAGENDA_API_BASE_URL", "").rstrip("/")
    # O gateway de produção injeta o token em um ContextVar por requisição.
    # O .env permanece apenas como compatibilidade com sessões controladas do
    # ADK Web durante desenvolvimento.
    contexto = obter_contexto_whatsapp() or os.getenv("PRONTAGENDA_WHATSAPP_CONTEXT_TOKEN", "")
    if not base_url or not contexto:
        return _erro("CONTEXTO_NAO_CONFIGURADO", "Esta conversa não possui um contexto autenticado.")
    try:
        response = requests.get(
            f"{base_url}/api/ai/whatsapp/{endpoint}",
            params=params or {},
            headers={"Authorization": f"Bearer {contexto}", "X-Prontagenda-WhatsApp-Context": contexto, "Accept": "application/json"},
            timeout=10,
        )
        body = response.json()
    except requests.Timeout:
        logger.warning(
            "metric=backend_tool endpoint=%s resultado=timeout total_ms=%d",
            endpoint,
            round((time.perf_counter() - inicio) * 1000),
        )
        return _erro("API_TIMEOUT", "A consulta excedeu o tempo limite.")
    except (requests.RequestException, requests.JSONDecodeError):
        logger.warning(
            "metric=backend_tool endpoint=%s resultado=indisponivel total_ms=%d",
            endpoint,
            round((time.perf_counter() - inicio) * 1000),
        )
        return _erro("API_INDISPONIVEL", "Não foi possível consultar o Prontagenda.")
    logger.info(
        "metric=backend_tool endpoint=%s resultado=http_%d total_ms=%d",
        endpoint,
        response.status_code,
        round((time.perf_counter() - inicio) * 1000),
    )
    if not isinstance(body, dict):
        return _erro("RESPOSTA_INVALIDA", "A API retornou uma resposta inválida.")
    if not response.ok:
        logger.warning(
            "metric=backend_tool_error endpoint=%s status=%d erro=%s",
            endpoint,
            response.status_code,
            str(body.get("erro", "ERRO_HTTP")),
        )
        return _erro(body.get("erro", "ERRO_HTTP"), body.get("mensagem", "A consulta não foi concluída."))
    return body


def _enviar(endpoint: str, payload: dict) -> dict:
    inicio = time.perf_counter()
    base_url = os.getenv("PRONTAGENDA_API_BASE_URL", "").rstrip("/")
    contexto = obter_contexto_whatsapp() or os.getenv("PRONTAGENDA_WHATSAPP_CONTEXT_TOKEN", "")
    if not base_url or not contexto:
        return _erro("CONTEXTO_NAO_CONFIGURADO", "Esta conversa não possui um contexto autenticado.")
    try:
        response = requests.post(
            f"{base_url}/api/ai/whatsapp/{endpoint}",
            json=payload,
            headers={"Authorization": f"Bearer {contexto}", "X-Prontagenda-WhatsApp-Context": contexto, "Accept": "application/json"},
            timeout=12,
        )
        body = response.json()
    except requests.Timeout:
        logger.warning("metric=backend_tool endpoint=%s resultado=timeout total_ms=%d", endpoint, round((time.perf_counter() - inicio) * 1000))
        return _erro("API_TIMEOUT", "A confirmação excedeu o tempo limite.")
    except (requests.RequestException, requests.JSONDecodeError):
        logger.warning("metric=backend_tool endpoint=%s resultado=indisponivel total_ms=%d", endpoint, round((time.perf_counter() - inicio) * 1000))
        return _erro("API_INDISPONIVEL", "Não foi possível acessar o Prontagenda.")
    logger.info("metric=backend_tool endpoint=%s resultado=http_%d total_ms=%d", endpoint, response.status_code, round((time.perf_counter() - inicio) * 1000))
    if not isinstance(body, dict):
        return _erro("RESPOSTA_INVALIDA", "A API retornou uma resposta inválida.")
    if not response.ok:
        logger.warning(
            "metric=backend_tool_error endpoint=%s status=%d erro=%s",
            endpoint,
            response.status_code,
            str(body.get("erro", "ERRO_HTTP")),
        )
        return _erro(body.get("erro", "ERRO_HTTP"), body.get("mensagem", "A operação não foi concluída."))
    return body


def confirmar_minha_identidade() -> dict:
    """Confirma apenas o primeiro nome associado ao remetente desta conversa."""
    return _consultar("me.php")


def buscar_meus_agendamentos(data: str | None = None) -> dict:
    """Consulta somente os agendamentos do paciente autenticado nesta conversa."""
    params = {}
    if data:
        try:
            params["data"] = _data_iso(data)
        except (ValueError, TypeError):
            return _erro("DATA_INVALIDA", "Informe a data como DD/MM, DD/MM/AAAA ou AAAA-MM-DD.")
    return _consultar("agendamentos.php", params)


def buscar_horarios_para_reagendamento(agendamento_id: int, data: str) -> dict:
    """Busca horários para uma consulta pertencente ao remetente; não altera dados."""
    if agendamento_id < 1:
        return _erro("AGENDAMENTO_INVALIDO", "Selecione um agendamento retornado pela consulta.")
    try:
        data_iso = _data_iso(data)
    except (ValueError, TypeError):
        return _erro("DATA_INVALIDA", "Informe a data como DD/MM, DD/MM/AAAA ou AAAA-MM-DD.")
    return _consultar("disponibilidade_reagendamento.php", {"agendamento_id": agendamento_id, "data": data_iso})


def buscar_horarios_para_nova_consulta(data: str, profissional: str) -> dict:
    """Consulta horários reais para paciente novo; não cria cadastro nem agenda."""
    if not profissional or len(profissional.strip()) < 2:
        return _erro("PROFISSIONAL_OBRIGATORIO", "Informe o nome do profissional.")
    try:
        data_iso = _data_iso(data)
    except (ValueError, TypeError):
        return _erro("DATA_INVALIDA", "Informe a data como DD/MM, DD/MM/AAAA ou AAAA-MM-DD.")
    return _consultar(
        "disponibilidade_nova_consulta.php",
        {"data": data_iso, "profissional": profissional.strip()},
    )


def listar_profissionais() -> dict:
    """Lista os profissionais disponíveis na empresa desta conversa."""
    return _consultar("profissionais.php")


def consultar_expediente_profissional(profissional_id: int) -> dict:
    """Consulta o expediente; reproduza resumo_expediente literalmente, sem recalcular."""
    if profissional_id < 1:
        return _erro("PROFISSIONAL_INVALIDO", "Escolha um profissional retornado pela lista.")
    return _consultar("expediente_profissional.php", {"profissional_id": profissional_id})


def buscar_proxima_disponibilidade(
    profissional_id: int,
    horario_preferido: str | None = None,
    tipo_preferencia: Literal["exato", "a_partir", "ate", "intervalo", "primeiro_disponivel", "periodo", "aproximado"] = "exato",
    horario_fim: str | None = None,
    periodo: Literal["manha", "tarde", "noite"] | None = None,
    dias_preferidos: str | None = None,
    dias_excluidos: str | None = None,
) -> dict:
    """Busca vaga por horário e dias da semana escritos por nome; datas oferecidas não usam esta tool."""
    if profissional_id < 1:
        return _erro("PROFISSIONAL_INVALIDO", "Escolha um profissional retornado pela lista.")
    tipos = ("exato", "a_partir", "ate", "intervalo", "primeiro_disponivel", "periodo", "aproximado")
    if tipo_preferencia not in tipos:
        return _erro("PREFERENCIA_INVALIDA", "Tipo de preferência inválido.")
    inicio = horario_preferido
    fim = horario_fim
    if tipo_preferencia == "primeiro_disponivel":
        inicio, fim = "00:00", "23:59"
    elif tipo_preferencia == "periodo":
        faixas = {"manha": ("00:00", "11:59"), "tarde": ("12:00", "17:59"), "noite": ("18:00", "23:59")}
        if periodo not in faixas:
            return _erro("PERIODO_INVALIDO", "Informe manhã, tarde ou noite.")
        inicio, fim = faixas[periodo]
    try:
        horario = datetime.strptime((inicio or "").strip(), "%H:%M").strftime("%H:%M")
        horario_final = datetime.strptime((fim or "").strip(), "%H:%M").strftime("%H:%M") if fim else None
    except (ValueError, TypeError):
        return _erro("HORARIO_INVALIDO", "Informe o horário no formato HH:MM.")
    if tipo_preferencia in ("intervalo", "periodo", "primeiro_disponivel"):
        if not horario_final or horario_final < horario:
            return _erro("INTERVALO_INVALIDO", "O horário final deve ser posterior ao inicial.")
    try:
        preferidos_csv = _dias_semana_csv(dias_preferidos)
        excluidos_csv = _dias_semana_csv(dias_excluidos)
    except (ValueError, TypeError) as exc:
        logger.info("preferencia_dias_invalida detalhe=%s", str(exc))
        return _erro(
            "DIAS_SEMANA_INVALIDOS",
            "Dias preferidos aceitam nomes como segunda ou quinta. Para escolher uma data já oferecida, use a data e o horário daquela opção.",
        )
    parametros = {
        "profissional_id": profissional_id,
        "horario_preferido": horario,
        "tipo_preferencia": tipo_preferencia,
    }
    if horario_final:
        parametros["horario_fim"] = horario_final
    if periodo:
        parametros["periodo"] = periodo
    if preferidos_csv:
        parametros["dias_preferidos"] = preferidos_csv
    if excluidos_csv:
        parametros["dias_excluidos"] = excluidos_csv
    return _consultar(
        "proxima_disponibilidade.php",
        parametros,
    )


def preparar_novo_agendamento(
    profissional_id: int,
    data: str,
    horario: str,
    paciente_nome: str | None = None,
) -> dict:
    """Prepara uma vaga escolhida e pede confirmação; ainda não grava na agenda."""
    if profissional_id < 1:
        return _erro("PROFISSIONAL_INVALIDO", "Escolha um profissional retornado pela lista.")
    mensagem_original = obter_mensagem_whatsapp().strip()
    mensagem_atual = unicodedata.normalize("NFKD", mensagem_original.lower())
    mensagem_atual = "".join(c for c in mensagem_atual if not unicodedata.combining(c))
    hora_escolhida_modelo = None
    try:
        hora_escolhida_modelo = int(horario.strip().split(":", 1)[0])
    except (ValueError, TypeError):
        pass
    numero_puro = re.fullmatch(r"\s*(?:pode ser\s+|prefiro\s+|quero\s+)?(\d{1,2})\s*", mensagem_atual)
    numero_puro_confirma_hora = (
        numero_puro is not None
        and "dia" not in mensagem_atual
        and hora_escolhida_modelo is not None
        and int(numero_puro.group(1)) == hora_escolhida_modelo
    )
    afirmacao_contextual = (
        mensagem_original in {"👍", "👍🏻", "👍🏼", "👍🏽", "👍🏾", "👍🏿", "✅", "☑️"}
        or re.fullmatch(
            r"\s*(?:sim(?:\s+por favor|\s+pode(?:\s+ser)?)?|pode(?:\s+ser(?:\s+entao)?)?|ok|certo|beleza|combinado|fechado|perfeito|confirmo|confirmado|isso|esse|essa|nesse horario|esse horario)[.!?]?\s*",
            mensagem_atual,
        ) is not None
    )
    informou_horario = (
        re.search(r"\b(?:[01]?\d|2[0-3])\s*(?:h(?:oras?)?|:[0-5]\d)\b", mensagem_atual) is not None
        or re.search(r"\b(?:as|pelas?)\s+(?:[01]?\d|2[0-3])\b", mensagem_atual) is not None
        or re.search(r"\b(?:primeir[oa]|segund[oa])(?:\s+(?:opcao|horario))?\b", mensagem_atual) is not None
        or afirmacao_contextual
        or re.search(r"\b(?:pode ser\s+)?dia\s+\d{1,2}\b", mensagem_atual) is not None
        or numero_puro_confirma_hora
    )
    if not informou_horario:
        return {
            "sucesso": False,
            "erro": "HORARIO_NAO_ESCOLHIDO",
            "mensagem": (
                "O paciente informou apenas o dia. Repita os horários reais oferecidos "
                "para essa data e pergunte qual deles fica melhor. Não escolha por ele."
            ),
        }
    try:
        data_iso = _data_iso(data)
        hora = datetime.strptime(horario.strip(), "%H:%M").strftime("%H:%M")
    except (ValueError, TypeError):
        return _erro("DATA_HORA_INVALIDA", "Escolha uma das datas e horas que foram oferecidas.")
    payload = {
        "acao": "preparar",
        "profissional_id": profissional_id,
        "data_hora_inicio": f"{data_iso} {hora}:00",
    }
    if paciente_nome and paciente_nome.strip():
        payload["paciente_nome"] = paciente_nome.strip()
    return _enviar("confirmar_agendamento.php", payload)


def confirmar_novo_agendamento() -> dict:
    """Confirma uma proposta já apresentada; nunca use sem um sim explícito do paciente."""
    return _enviar(
        "confirmar_agendamento.php",
        {"acao": "confirmar"},
    )


def cancelar_proposta_anterior() -> dict:
    """Cancela a proposta pendente quando o paciente muda profissional, dia ou horário."""
    return _enviar("cancelar_proposta.php", {})


def substituir_proposta_e_buscar(
    profissional_id: int,
    horario_preferido: str | None = None,
    tipo_preferencia: Literal["exato", "a_partir", "ate", "intervalo", "primeiro_disponivel", "periodo", "aproximado"] = "exato",
    horario_fim: str | None = None,
    periodo: Literal["manha", "tarde", "noite"] | None = None,
    dias_preferidos: str | None = None,
    dias_excluidos: str | None = None,
) -> dict:
    """Cancela a proposta anterior e busca a nova preferência em uma única tool."""
    cancelamento = cancelar_proposta_anterior()
    if cancelamento.get("sucesso") is not True:
        return cancelamento

    resultado = buscar_proxima_disponibilidade(
        profissional_id=profissional_id,
        horario_preferido=horario_preferido,
        tipo_preferencia=tipo_preferencia,
        horario_fim=horario_fim,
        periodo=periodo,
        dias_preferidos=dias_preferidos,
        dias_excluidos=dias_excluidos,
    )
    if resultado.get("sucesso") is True:
        resultado["proposta_anterior_cancelada"] = True
        resultado["propostas_canceladas"] = int(cancelamento.get("propostas_canceladas", 0))
        preferencia = resultado.get("preferencia")
        resposta_unica = resultado.get("resposta_preferencia_unica")
        if isinstance(preferencia, dict) and isinstance(resposta_unica, str) and resposta_unica.strip():
            resultado["alternativa_mais_cedo"] = None
            resultado["alternativas"] = []
            resultado["resposta_template"] = resposta_unica.strip()
    return resultado


def encaminhar_para_equipe(
    motivo: Literal["sem_disponibilidade", "solicitacao_clinica", "preferencia_ambigua", "erro_agenda", "solicitacao_paciente"] = "solicitacao_paciente",
) -> dict:
    """Abre atendimento humano real; use quando disser que encaminhou à equipe."""
    return _enviar("encaminhar_atendimento.php", {"motivo": motivo})
