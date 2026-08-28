"""Tools somente leitura para pacientes e seus agendamentos."""

from .agenda import _normalizar_data_brasileira
from .client import consultar, erro


def buscar_paciente(
    telefone: str | None = None,
    paciente_id: int | None = None,
    nome: str | None = None,
) -> dict:
    """Localiza um único paciente real por telefone, ID ou nome; priorize telefone."""
    params: dict[str, str | int] = {}
    if telefone and telefone.strip():
        params["telefone"] = telefone.strip()
    elif paciente_id is not None:
        params["paciente_id"] = paciente_id
    elif nome and nome.strip():
        params["nome"] = nome.strip()
    else:
        return erro("FILTRO_OBRIGATORIO", "Informe telefone, paciente_id ou nome.")
    return consultar("paciente.php", params)


def buscar_agendamento(
    paciente_id: int,
    data: str | None = None,
    profissional_id: int | None = None,
    status: str | None = None,
) -> dict:
    """Consulta agendamentos futuros de um paciente real, com filtros opcionais."""
    if paciente_id < 1:
        return erro("PACIENTE_ID_INVALIDO", "Informe um paciente_id válido retornado pela busca de paciente.")
    params: dict[str, str | int] = {"paciente_id": paciente_id}
    if data:
        try:
            params["data"] = _normalizar_data_brasileira(data)
        except ValueError:
            return erro("DATA_INVALIDA", "Informe a data como DD/MM, DD/MM/AAAA ou AAAA-MM-DD.")
    if profissional_id is not None:
        params["profissional_id"] = profissional_id
    if status:
        params["status"] = status.strip()
    return consultar("agendamentos.php", params)
