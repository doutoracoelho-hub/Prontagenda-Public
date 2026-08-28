import asyncio
import unittest

from prontagenda_whatsapp_agent.runtime_context import (
    definir_contexto_whatsapp,
    obter_contexto_whatsapp,
    restaurar_contexto_whatsapp,
)


class RuntimeContextTest(unittest.IsolatedAsyncioTestCase):
    async def test_isola_tokens_entre_requisicoes_concorrentes(self) -> None:
        iniciou = asyncio.Event()
        leituras: dict[str, str] = {}

        async def executar(nome: str, valor: str) -> None:
            marcador = definir_contexto_whatsapp(valor)
            try:
                iniciou.set()
                await asyncio.sleep(0)
                leituras[nome] = obter_contexto_whatsapp()
            finally:
                restaurar_contexto_whatsapp(marcador)

        await asyncio.gather(executar("a", "token-a"), executar("b", "token-b"))
        self.assertEqual({"a": "token-a", "b": "token-b"}, leituras)
        self.assertEqual("", obter_contexto_whatsapp())
