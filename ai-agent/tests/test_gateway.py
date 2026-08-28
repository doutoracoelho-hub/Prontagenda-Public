import os
import tempfile
import unittest
import uuid
from pathlib import Path
from types import SimpleNamespace


_banco = Path(tempfile.gettempdir()) / f"prontagenda-gateway-{uuid.uuid4().hex}.db"
os.environ["PRONTAGENDA_AI_GATEWAY_TOKEN"] = "teste-local-123456789012345678901234567890"
os.environ["PRONTAGENDA_AI_SESSION_DB"] = str(_banco)
os.environ.setdefault("GOOGLE_API_KEY", "teste-nao-utilizado")
os.environ.setdefault("PRONTAGENDA_API_BASE_URL", "https://example.invalid")

import gateway  # noqa: E402


class GatewayTest(unittest.IsolatedAsyncioTestCase):
    async def test_sessao_persistida_nao_contem_contexto_hmac(self) -> None:
        identificador = f"empresa-1-conversa-{uuid.uuid4().hex}"
        criada = await gateway.session_service.create_session(
            app_name=gateway.APP_NAME,
            user_id="empresa-1",
            session_id=identificador,
        )
        self.assertNotIn("contexto_token", criada.state)

        lida = await gateway.session_service.get_session(
            app_name=gateway.APP_NAME,
            user_id="empresa-1",
            session_id=identificador,
        )
        self.assertIsNotNone(lida)
        self.assertNotIn("contexto_token", lida.state)

    def test_autorizacao_rejeita_token_invalido(self) -> None:
        with self.assertRaises(Exception) as erro:
            gateway._autorizar("Bearer incorreto")
        self.assertEqual(401, erro.exception.status_code)

    def test_timeout_de_tool_solicita_encaminhamento_humano(self) -> None:
        evento = SimpleNamespace(
            content=SimpleNamespace(
                parts=[
                    SimpleNamespace(
                        function_response=SimpleNamespace(
                            response={"sucesso": False, "erro": "API_TIMEOUT"}
                        )
                    )
                ]
            )
        )
        self.assertEqual("API_TIMEOUT", gateway._falha_tecnica_evento(evento))

    def test_erro_de_negocio_nao_solicita_encaminhamento_tecnico(self) -> None:
        evento = SimpleNamespace(
            content=SimpleNamespace(
                parts=[
                    SimpleNamespace(
                        function_response=SimpleNamespace(
                            response={"sucesso": False, "erro": "VAGA_NAO_DISPONIVEL"}
                        )
                    )
                ]
            )
        )
        self.assertIsNone(gateway._falha_tecnica_evento(evento))

    def test_profissional_confirmado_e_preservado_no_contexto_do_modelo(self) -> None:
        payload = gateway.WhatsAppRequest(
            empresa_id=1,
            conversa_id=259,
            mensagem="17 horas",
            contexto_token="x" * 32,
            estado_fluxo="ia_whatsapp",
            profissional_id_selecionado=2,
        )

        mensagem = gateway._mensagem_com_contexto(payload)

        self.assertIn("profissional já foi escolhido", mensagem)
        self.assertIn("ID é 2", mensagem)
        self.assertIn("Não liste nem pergunte novamente", mensagem)
        self.assertIn("peça somente o dia", mensagem)
        self.assertTrue(mensagem.endswith("17 horas"))

    def test_mensagem_sem_profissional_nao_recebe_contexto_artificial(self) -> None:
        payload = gateway.WhatsAppRequest(
            empresa_id=1,
            conversa_id=259,
            mensagem="Quero marcar uma consulta",
            contexto_token="x" * 32,
        )

        self.assertEqual("Quero marcar uma consulta", gateway._mensagem_com_contexto(payload))
