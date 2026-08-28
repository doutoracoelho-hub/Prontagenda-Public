<?php

declare(strict_types=1);

final class AgendaExpedienteService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{profissional_id:int,profissional_nome:string,faixas_atendimento:list<array{dia_inicio:string,dia_fim:string,hora_inicio:string,hora_fim:string}>,resumo_expediente:string} */
    public function obter(int $empresaId, int $profissionalId): array
    {
        $profissional = $this->pdo->prepare(
            "SELECT id, nome FROM usuarios WHERE id = :id AND empresa_id = :empresa
             AND nivel_acesso != 'secretaria' LIMIT 1"
        );
        $profissional->execute([':id' => $profissionalId, ':empresa' => $empresaId]);
        $dados = $profissional->fetch(PDO::FETCH_ASSOC);
        if (!$dados) {
            throw new DomainException('PROFISSIONAL_NAO_ENCONTRADO');
        }

        $stmt = $this->pdo->prepare(
            'SELECT dia_semana, hora_inicio, hora_fim FROM agenda_dias_horarios '
            . 'WHERE usuario_id = :profissional AND ativo = 1 '
            . 'AND hora_inicio IS NOT NULL AND hora_fim IS NOT NULL ORDER BY dia_semana'
        );
        $stmt->execute([':profissional' => $profissionalId]);
        $dias = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nomes = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
        $faixas = [];
        foreach ($dias as $dia) {
            $numero = (int)$dia['dia_semana'];
            if (!isset($nomes[$numero])) continue;
            $inicio = substr((string)$dia['hora_inicio'], 0, 5);
            $fim = substr((string)$dia['hora_fim'], 0, 5);
            $ultima = array_key_last($faixas);
            if ($ultima !== null && $faixas[$ultima]['_fim_numero'] + 1 === $numero
                && $faixas[$ultima]['hora_inicio'] === $inicio && $faixas[$ultima]['hora_fim'] === $fim) {
                $faixas[$ultima]['dia_fim'] = $nomes[$numero];
                $faixas[$ultima]['_fim_numero'] = $numero;
            } else {
                $faixas[] = ['dia_inicio' => $nomes[$numero], 'dia_fim' => $nomes[$numero], '_fim_numero' => $numero, 'hora_inicio' => $inicio, 'hora_fim' => $fim];
            }
        }
        foreach ($faixas as &$faixa) unset($faixa['_fim_numero']);
        unset($faixa);
        $partes = array_map(static function (array $faixa): string {
            $dias = $faixa['dia_inicio'] === $faixa['dia_fim'] ? $faixa['dia_inicio'] : 'de ' . $faixa['dia_inicio'] . ' a ' . $faixa['dia_fim'];
            return $dias . ', das ' . $faixa['hora_inicio'] . ' às ' . $faixa['hora_fim'];
        }, $faixas);
        $nome = (string)$dados['nome'];
        $resumo = $partes ? $nome . ' atende ' . implode('; ', $partes) . '.' : 'Não há expediente configurado para ' . $nome . '.';
        return ['profissional_id' => (int)$dados['id'], 'profissional_nome' => $nome, 'faixas_atendimento' => $faixas, 'resumo_expediente' => $resumo];
    }

    public function aceitaPreferencia(array $faixas, string $horario, string $tipo): bool
    {
        return $this->aceitaPreferenciaEmDias($faixas, $horario, $tipo, null);
    }

    public function aceitaPreferenciaEmDias(array $faixas, string $horario, string $tipo, ?array $dias): bool
    {
        $numeros = [
            'segunda-feira' => 1, 'terça-feira' => 2, 'quarta-feira' => 3,
            'quinta-feira' => 4, 'sexta-feira' => 5, 'sábado' => 6, 'domingo' => 7,
        ];
        foreach ($faixas as $faixa) {
            $inicioDia = $numeros[$faixa['dia_inicio']] ?? 0;
            $fimDia = $numeros[$faixa['dia_fim']] ?? 0;
            if ($dias !== null) {
                $abrangeDia = false;
                foreach ($dias as $dia) {
                    if ($dia >= $inicioDia && $dia <= $fimDia) {
                        $abrangeDia = true;
                        break;
                    }
                }
                if (!$abrangeDia) continue;
            }
            if ($tipo === 'exato' && $horario >= $faixa['hora_inicio'] && $horario < $faixa['hora_fim']) return true;
            if ($tipo === 'a_partir' && $horario < $faixa['hora_fim']) return true;
            if ($tipo === 'ate' && $horario >= $faixa['hora_inicio']) return true;
        }
        return false;
    }
}
