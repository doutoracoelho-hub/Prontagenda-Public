"""Cliente HTTP comum das tools do Prontagenda."""

import os

import requests


def erro(codigo: str, mensagem: str) -> dict:
    return {"sucesso": False, "erro": codigo, "mensagem": mensagem}


def consultar(endpoint: str, params: dict) -> dict:
    base_url = os.getenv("PRONTAGENDA_API_BASE_URL", "").rstrip("/")
    token = os.getenv("PRONTAGENDA_AI_INTERNAL_API_TOKEN", "")
    empresa_id = os.getenv("PRONTAGENDA_AI_INTERNAL_EMPRESA_ID", "")
    if not base_url or not token or not empresa_id:
        return erro("INTEGRACAO_NAO_CONFIGURADA", "A integração com o Prontagenda não está configurada.")

    params = {**params, "empresa_id": empresa_id}
    try:
        response = requests.get(
            f"{base_url}/api/ai/internal/{endpoint}",
            params=params,
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            timeout=10,
        )
    except requests.Timeout:
        return erro("API_TIMEOUT", "A consulta excedeu o tempo limite.")
    except requests.RequestException:
        return erro("API_INDISPONIVEL", "A API do Prontagenda está indisponível.")

    try:
        body = response.json()
    except requests.JSONDecodeError:
        return erro("RESPOSTA_INVALIDA", "A API do Prontagenda retornou uma resposta inválida.")
    if not isinstance(body, dict):
        return erro("RESPOSTA_INVALIDA", "A API do Prontagenda retornou uma resposta inválida.")
    if response.status_code in (401, 403):
        return erro(body.get("erro", "FALHA_AUTENTICACAO"), body.get("mensagem", "A API recusou as credenciais."))
    if not response.ok:
        return erro(body.get("erro", "ERRO_HTTP"), body.get("mensagem", "A API não concluiu a consulta."))
    return body
