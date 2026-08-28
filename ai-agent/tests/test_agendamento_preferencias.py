import unittest
from unittest.mock import patch

from prontagenda_whatsapp_agent.tools import (
    buscar_proxima_disponibilidade,
    preparar_novo_agendamento,
    substituir_proposta_e_buscar,
)
from prontagenda_whatsapp_agent.runtime_context import (
    definir_mensagem_whatsapp,
    restaurar_mensagem_whatsapp,
)


class PreferenciasAgendamentoTest(unittest.TestCase):
    def consultar(self, **kwargs):
        with patch("prontagenda_whatsapp_agent.tools._consultar") as consultar:
            consultar.return_value = {"sucesso": True}
            resultado = buscar_proxima_disponibilidade(profissional_id=2, **kwargs)
            self.assertTrue(resultado["sucesso"])
            return consultar.call_args.args[1]

    def test_intervalo_preserva_inicio_fim_e_dias(self):
        params = self.consultar(horario_preferido="14:00", horario_fim="16:00",
            tipo_preferencia="intervalo", dias_preferidos="terça, quinta")
        self.assertEqual("14:00", params["horario_preferido"])
        self.assertEqual("16:00", params["horario_fim"])
        self.assertEqual("2,4", params["dias_preferidos"])

    def test_periodo_tarde_vira_faixa_controlada(self):
        params = self.consultar(tipo_preferencia="periodo", periodo="tarde")
        self.assertEqual("12:00", params["horario_preferido"])
        self.assertEqual("17:59", params["horario_fim"])

    def test_primeiro_disponivel_nao_inventa_hora(self):
        params = self.consultar(tipo_preferencia="primeiro_disponivel", dias_excluidos="segunda")
        self.assertEqual("00:00", params["horario_preferido"])
        self.assertEqual("23:59", params["horario_fim"])
        self.assertEqual("1", params["dias_excluidos"])

    def test_dia_sem_horario_busca_primeiro_disponivel_daquele_dia(self):
        params = self.consultar(tipo_preferencia="primeiro_disponivel", dias_preferidos="quinta")
        self.assertEqual("00:00", params["horario_preferido"])
        self.assertEqual("23:59", params["horario_fim"])
        self.assertEqual("4", params["dias_preferidos"])

    def test_intervalo_invertido_e_rejeitado(self):
        resultado = buscar_proxima_disponibilidade(profissional_id=2,
            horario_preferido="18:00", horario_fim="16:00", tipo_preferencia="intervalo")
        self.assertFalse(resultado["sucesso"])
        self.assertEqual("INTERVALO_INVALIDO", resultado["erro"])

    def test_horario_aproximado_preserva_referencia(self):
        params = self.consultar(horario_preferido="15:00", tipo_preferencia="aproximado")
        self.assertEqual("15:00", params["horario_preferido"])
        self.assertEqual("aproximado", params["tipo_preferencia"])

    def test_dia_do_mes_nao_e_aceito_como_dia_da_semana(self):
        resultado = buscar_proxima_disponibilidade(
            profissional_id=2,
            tipo_preferencia="primeiro_disponivel",
            dias_preferidos="31",
        )
        self.assertFalse(resultado["sucesso"])
        self.assertEqual("DIAS_SEMANA_INVALIDOS", resultado["erro"])
        self.assertNotIn("Dia da semana inválido: 31", resultado["mensagem"])
        self.assertIn("data já oferecida", resultado["mensagem"])

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_dia_sem_horario_delega_validacao_ao_backend(self, enviar):
        marcador = definir_mensagem_whatsapp("Pode ser dia 2")
        try:
            resultado = preparar_novo_agendamento(2, "2026-09-02", "15:30")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_horario_explicito_permite_preparar(self, enviar):
        enviar.return_value = {"sucesso": True}
        marcador = definir_mensagem_whatsapp("Pode ser às 17h")
        try:
            resultado = preparar_novo_agendamento(2, "2026-09-02", "17:00")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        self.assertTrue(resultado["sucesso"])
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_dia_e_hora_sem_minutos_permite_preparar(self, enviar):
        enviar.return_value = {"sucesso": True}
        marcador = definir_mensagem_whatsapp("Dia 01 às 15")
        try:
            resultado = preparar_novo_agendamento(2, "2026-09-01", "15:00")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        self.assertTrue(resultado["sucesso"])
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_numero_puro_igual_a_hora_permite_preparar(self, enviar):
        enviar.return_value = {"sucesso": True}
        marcador = definir_mensagem_whatsapp("17")
        try:
            resultado = preparar_novo_agendamento(2, "2026-09-02", "17:00")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        self.assertTrue(resultado["sucesso"])
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_pode_ser_em_preferencia_unica_permite_preparar(self, enviar):
        enviar.return_value = {"sucesso": True}
        marcador = definir_mensagem_whatsapp("Pode ser então")
        try:
            resultado = preparar_novo_agendamento(2, "2026-10-01", "17:30")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        self.assertTrue(resultado["sucesso"])
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_sim_pode_ser_em_preferencia_unica_permite_preparar(self, enviar):
        enviar.return_value = {"sucesso": True}
        marcador = definir_mensagem_whatsapp("Sim pode ser")
        try:
            resultado = preparar_novo_agendamento(2, "2026-09-02", "17:00")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        self.assertTrue(resultado["sucesso"])
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_dia_unico_oferecido_e_validado_pelo_backend(self, enviar):
        enviar.return_value = {"sucesso": True}
        marcador = definir_mensagem_whatsapp("Pode ser dia 23")
        try:
            resultado = preparar_novo_agendamento(2, "2026-09-23", "18:00")
        finally:
            restaurar_mensagem_whatsapp(marcador)
        self.assertTrue(resultado["sucesso"])
        enviar.assert_called_once()

    @patch("prontagenda_whatsapp_agent.tools._consultar")
    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_mudanca_cancela_e_busca_na_mesma_tool(self, enviar, consultar):
        enviar.return_value = {"sucesso": True, "propostas_canceladas": 1}
        consultar.return_value = {"sucesso": True, "preferencia": {"data": "2026-08-27"}}

        resultado = substituir_proposta_e_buscar(
            profissional_id=2,
            tipo_preferencia="primeiro_disponivel",
            dias_preferidos="quinta",
        )

        enviar.assert_called_once_with("cancelar_proposta.php", {})
        self.assertEqual("proxima_disponibilidade.php", consultar.call_args.args[0])
        self.assertEqual("4", consultar.call_args.args[1]["dias_preferidos"])
        self.assertTrue(resultado["proposta_anterior_cancelada"])
        self.assertEqual(1, resultado["propostas_canceladas"])

    @patch("prontagenda_whatsapp_agent.tools._consultar")
    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_mudanca_com_preferencia_exata_oculta_alternativas(self, enviar, consultar):
        enviar.return_value = {"sucesso": True, "propostas_canceladas": 0}
        consultar.return_value = {
            "sucesso": True,
            "preferencia": {"data": "2026-10-01", "hora": "17:30"},
            "alternativa_mais_cedo": {"data": "2026-09-01", "hora": "15:00"},
            "alternativas": [{"data": "2026-09-01", "hora": "15:00"}],
            "resposta_template": "resposta com alternativas",
            "resposta_preferencia_unica": "Na quinta-feira, às 17:30, tenho horário. Pode ser?",
        }

        resultado = substituir_proposta_e_buscar(
            profissional_id=2,
            horario_preferido="17:30",
            tipo_preferencia="a_partir",
            dias_preferidos="quinta",
        )

        self.assertIsNone(resultado["alternativa_mais_cedo"])
        self.assertEqual([], resultado["alternativas"])
        self.assertEqual(
            "Na quinta-feira, às 17:30, tenho horário. Pode ser?",
            resultado["resposta_template"],
        )

    @patch("prontagenda_whatsapp_agent.tools._consultar")
    @patch("prontagenda_whatsapp_agent.tools._enviar")
    def test_falha_ao_cancelar_impede_nova_busca(self, enviar, consultar):
        enviar.return_value = {"sucesso": False, "erro": "API_INDISPONIVEL"}

        resultado = substituir_proposta_e_buscar(
            profissional_id=2,
            tipo_preferencia="primeiro_disponivel",
            dias_preferidos="quinta",
        )

        consultar.assert_not_called()
        self.assertFalse(resultado["sucesso"])


if __name__ == "__main__":
    unittest.main()
