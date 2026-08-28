<?php

declare(strict_types=1);

require_once __DIR__ . '/AgendaFeriadoService.php';

final class AgendaDisponibilidadeService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Calcula os inicios livres usando as mesmas fontes exibidas pela agenda.
     *
     * @return array{horarios:list<string>,duracao_minutos:int,intervalo_minutos:int}
     */
    public function buscar(
        int $empresaId,
        int $profissionalId,
        string $data,
        ?int $duracaoMinutos = null,
        bool $usarIntervaloIa = false
    ): array
    {
        $profissional = $this->pdo->prepare(
            "SELECT id FROM usuarios
             WHERE id = :profissional AND empresa_id = :empresa
               AND nivel_acesso != 'secretaria'
             LIMIT 1"
        );
        $profissional->execute([':profissional' => $profissionalId, ':empresa' => $empresaId]);
        if (!$profissional->fetchColumn()) {
            throw new DomainException('PROFISSIONAL_NAO_ENCONTRADO');
        }

        $intervalo = $this->carregarIntervalo($profissionalId, $usarIntervaloIa);

        $duracao = $duracaoMinutos ?? $intervalo;
        if ($duracao < 1 || $duracao > 1440) {
            throw new InvalidArgumentException('DURACAO_INVALIDA');
        }

        $dia = new DateTimeImmutable($data . ' 00:00:00');
        $diaSemana = (int)$dia->format('N');

        if ((new AgendaFeriadoService($this->pdo))->ehFeriado($empresaId, $data)) {
            return ['horarios' => [], 'duracao_minutos' => $duracao, 'intervalo_minutos' => $intervalo];
        }

        $expedienteStmt = $this->pdo->prepare(
            'SELECT hora_inicio, hora_fim, ativo
             FROM agenda_dias_horarios
             WHERE usuario_id = :profissional AND dia_semana = :dia
             LIMIT 1'
        );
        $expedienteStmt->execute([':profissional' => $profissionalId, ':dia' => $diaSemana]);
        $expediente = $expedienteStmt->fetch(PDO::FETCH_ASSOC);
        if (!$expediente || (int)$expediente['ativo'] !== 1 || !$expediente['hora_inicio'] || !$expediente['hora_fim']) {
            return ['horarios' => [], 'duracao_minutos' => $duracao, 'intervalo_minutos' => $intervalo];
        }

        $bloqueioStmt = $this->pdo->prepare(
            'SELECT 1 FROM agenda_bloqueios
             WHERE usuario_id = :profissional
               AND (data = :data OR (data IS NULL AND recorrente = :recorrente))
             LIMIT 1'
        );
        $bloqueioStmt->execute([
            ':profissional' => $profissionalId,
            ':data' => $data,
            ':recorrente' => $dia->format('m-d'),
        ]);
        if ($bloqueioStmt->fetchColumn()) {
            return ['horarios' => [], 'duracao_minutos' => $duracao, 'intervalo_minutos' => $intervalo];
        }

        $inicioDia = $data . ' 00:00:00';
        $fimDia = $dia->modify('+1 day')->format('Y-m-d H:i:s');
        $ocupados = [];

        $agendamentosStmt = $this->pdo->prepare(
            'SELECT data_hora_inicio AS inicio, data_hora_fim AS fim
             FROM agendamentos
             WHERE profissional_id = :profissional AND empresa_id = :empresa
               AND data_hora_inicio < :fim_dia AND data_hora_fim > :inicio_dia'
        );
        $agendamentosStmt->execute([
            ':profissional' => $profissionalId,
            ':empresa' => $empresaId,
            ':inicio_dia' => $inicioDia,
            ':fim_dia' => $fimDia,
        ]);
        foreach ($agendamentosStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $ocupados[] = [$item['inicio'], $item['fim']];
        }

        $compromissosStmt = $this->pdo->prepare(
            'SELECT data_inicio AS inicio,
                    IF(data_fim IS NULL OR data_fim <= data_inicio, DATE_ADD(data_inicio, INTERVAL 15 MINUTE), data_fim) AS fim,
                    dia_todo
             FROM compromissos
             WHERE profissional_id = :profissional
               AND data_inicio < :fim_dia
               AND IF(data_fim IS NULL OR data_fim < data_inicio, data_inicio, data_fim) >= :inicio_dia'
        );
        $compromissosStmt->execute([
            ':profissional' => $profissionalId,
            ':inicio_dia' => $inicioDia,
            ':fim_dia' => $fimDia,
        ]);
        foreach ($compromissosStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ((int)$item['dia_todo'] === 1) {
                return ['horarios' => [], 'duracao_minutos' => $duracao, 'intervalo_minutos' => $intervalo];
            }
            $ocupados[] = [$item['inicio'], $item['fim']];
        }

        $intervalosStmt = $this->pdo->prepare(
            'SELECT hora_inicio, hora_fim FROM agenda_intervalos_dia
             WHERE usuario_id = :profissional AND dia_semana = :dia'
        );
        $intervalosStmt->execute([':profissional' => $profissionalId, ':dia' => $diaSemana]);
        foreach ($intervalosStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $ocupados[] = [$data . ' ' . $item['hora_inicio'], $data . ' ' . $item['hora_fim']];
        }

        $cursor = new DateTimeImmutable($data . ' ' . $expediente['hora_inicio']);
        $fimExpediente = new DateTimeImmutable($data . ' ' . $expediente['hora_fim']);
        $horarios = [];

        while ($cursor->modify('+' . $duracao . ' minutes') <= $fimExpediente) {
            $fimSlot = $cursor->modify('+' . $duracao . ' minutes');
            $livre = true;
            foreach ($ocupados as [$inicioOcupado, $fimOcupado]) {
                $inicioOcupadoDt = new DateTimeImmutable((string)$inicioOcupado);
                $fimOcupadoDt = new DateTimeImmutable((string)$fimOcupado);
                if ($cursor < $fimOcupadoDt && $fimSlot > $inicioOcupadoDt) {
                    $livre = false;
                    break;
                }
            }
            if ($livre) {
                $horarios[] = $cursor->format('H:i');
            }
            $cursor = $cursor->modify('+' . $intervalo . ' minutes');
        }

        return ['horarios' => $horarios, 'duracao_minutos' => $duracao, 'intervalo_minutos' => $intervalo];
    }

    /**
     * Procura a primeira vaga que atende à preferência e preserva a primeira
     * vaga absoluta como alternativa. A regra fica no backend, não no modelo.
     *
     * @return array{preferencia:?array{data:string,hora:string},alternativa_mais_cedo:?array{data:string,hora:string},alternativas:list<array{data:string,hora:string}>,proxima_na_grade:?array{data:string,hora:string},horario_fora_grade:bool,intervalo_minutos:int,dias_consultados:int}
     */
    public function buscarProximaPreferencia(
        int $empresaId,
        int $profissionalId,
        string $horarioPreferido,
        string $tipoPreferencia = 'exato',
        ?array $diasPreferidos = null,
        array $diasExcluidos = [],
        ?string $dataInicial = null,
        int $maximoDias = 60,
        ?string $horarioFim = null,
        bool $usarIntervaloIa = false
    ): array {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horarioPreferido)) {
            throw new InvalidArgumentException('HORARIO_INVALIDO');
        }
        if (!in_array($tipoPreferencia, ['exato', 'a_partir', 'ate', 'intervalo', 'periodo', 'primeiro_disponivel', 'aproximado'], true)) {
            throw new InvalidArgumentException('PREFERENCIA_INVALIDA');
        }
        if (in_array($tipoPreferencia, ['intervalo', 'periodo', 'primeiro_disponivel'], true)) {
            if ($horarioFim === null || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horarioFim)
                || $horarioFim < $horarioPreferido) {
                throw new InvalidArgumentException('INTERVALO_INVALIDO');
            }
        }
        $normalizarDias = static function (?array $dias, bool $aceitaNulo): ?array {
            if ($dias === null && $aceitaNulo) {
                return null;
            }
            $normalizados = [];
            foreach ($dias ?? [] as $dia) {
                if (!is_int($dia) && !ctype_digit((string)$dia)) {
                    throw new InvalidArgumentException('DIAS_SEMANA_INVALIDOS');
                }
                $numero = (int)$dia;
                if ($numero < 1 || $numero > 7) {
                    throw new InvalidArgumentException('DIAS_SEMANA_INVALIDOS');
                }
                $normalizados[$numero] = $numero;
            }
            sort($normalizados);
            return array_values($normalizados);
        };
        $diasPreferidos = $normalizarDias($diasPreferidos, true);
        $diasExcluidos = $normalizarDias($diasExcluidos, false) ?? [];
        $inicio = new DateTimeImmutable(($dataInicial ?: date('Y-m-d')) . ' 00:00:00');
        $maximoDias = max(1, min($maximoDias, 90));
        $agora = new DateTimeImmutable();
        $preferencia = null;
        $alternativa = null;
        $primeirasVagas = [];
        $proximaNaGrade = null;
        $horarioNaGrade = false;
        $consultados = 0;
        $periodo = $this->carregarPeriodo(
            $empresaId,
            $profissionalId,
            $inicio,
            $inicio->modify('+' . $maximoDias . ' days'),
            $usarIntervaloIa
        );

        for ($indice = 0; $indice < $maximoDias; $indice++) {
            $dia = $inicio->modify('+' . $indice . ' days');
            $numeroDia = (int)$dia->format('N');
            if (in_array($numeroDia, $diasExcluidos, true)) {
                continue;
            }
            $data = $dia->format('Y-m-d');
            $horarios = $this->horariosDoDia($periodo, $dia);
            $consultados++;
            $diaCompativel = $diasPreferidos === null
                || in_array($numeroDia, $diasPreferidos, true);
            if ($diaCompativel && isset($periodo['expedientes'][$numeroDia])) {
                [$inicioExpediente, $fimExpediente] = $periodo['expedientes'][$numeroDia];
                $inicioMinutos = $this->horaEmMinutos($inicioExpediente);
                $fimMinutos = $this->horaEmMinutos($fimExpediente);
                $preferidoMinutos = $this->horaEmMinutos($horarioPreferido);
                $intervaloGrade = (int)$periodo['intervalo'];
                if (
                    $preferidoMinutos >= $inicioMinutos
                    && $preferidoMinutos + $intervaloGrade <= $fimMinutos
                    && (($preferidoMinutos - $inicioMinutos) % $intervaloGrade) === 0
                ) {
                    $horarioNaGrade = true;
                }
            }
            $aproximadaDoDia = null;
            $distanciaAproximada = PHP_INT_MAX;
            foreach ($horarios as $hora) {
                $instante = new DateTimeImmutable($data . ' ' . $hora . ':00');
                if ($instante <= $agora) {
                    continue;
                }
                $vaga = ['data' => $data, 'hora' => $hora];
                if (count($primeirasVagas) < 2) {
                    $primeirasVagas[] = $vaga;
                }
                if ($alternativa === null) {
                    $alternativa = $vaga;
                }
                if (
                    $tipoPreferencia === 'exato'
                    && $diaCompativel
                    && $hora > $horarioPreferido
                    && $proximaNaGrade === null
                ) {
                    $proximaNaGrade = $vaga;
                }
                $compativel = match ($tipoPreferencia) {
                    'exato' => $hora === $horarioPreferido,
                    'a_partir' => $hora >= $horarioPreferido,
                    'ate' => $hora <= $horarioPreferido,
                    'intervalo', 'periodo', 'primeiro_disponivel' => $hora >= $horarioPreferido && $hora <= $horarioFim,
                    'aproximado' => false,
                    default => false,
                };
                if ($tipoPreferencia === 'aproximado' && $diaCompativel) {
                    $distancia = abs($this->horaEmMinutos($hora) - $this->horaEmMinutos($horarioPreferido));
                    if ($distancia < $distanciaAproximada) {
                        $distanciaAproximada = $distancia;
                        $aproximadaDoDia = $vaga;
                    }
                }
                if ($diaCompativel && $compativel) {
                    $preferencia = $vaga;
                    break 2;
                }
            }
            if ($tipoPreferencia === 'aproximado' && $aproximadaDoDia !== null) {
                $preferencia = $aproximadaDoDia;
                break;
            }
        }

        if ($preferencia !== null && $alternativa === $preferencia) {
            $alternativa = null;
        }
        $alternativas = array_values(array_filter(
            $primeirasVagas,
            static fn(array $vaga): bool => $preferencia === null || $vaga !== $preferencia
        ));
        return [
            'preferencia' => $preferencia,
            'alternativa_mais_cedo' => $alternativa,
            'alternativas' => array_slice($alternativas, 0, 2),
            'proxima_na_grade' => $proximaNaGrade,
            'horario_fora_grade' => $tipoPreferencia === 'exato' && !$horarioNaGrade,
            'intervalo_minutos' => (int)$periodo['intervalo'],
            'dias_consultados' => $consultados,
        ];
    }

    /** @return list<array{data:string,hora:string}> */
    public function buscarPrimeirasVagas(
        int $empresaId,
        int $profissionalId,
        string $dataInicial,
        int $quantidade = 2,
        int $maximoDias = 60,
        bool $usarIntervaloIa = false
    ): array {
        $quantidade = max(1, min(10, $quantidade));
        $maximoDias = max(1, min(90, $maximoDias));
        $inicio = new DateTimeImmutable($dataInicial . ' 00:00:00');
        $agora = new DateTimeImmutable();
        $periodo = $this->carregarPeriodo(
            $empresaId,
            $profissionalId,
            $inicio,
            $inicio->modify('+' . $maximoDias . ' days'),
            $usarIntervaloIa
        );
        $vagas = [];
        for ($indice = 0; $indice < $maximoDias && count($vagas) < $quantidade; $indice++) {
            $dia = $inicio->modify('+' . $indice . ' days');
            $data = $dia->format('Y-m-d');
            foreach ($this->horariosDoDia($periodo, $dia) as $hora) {
                if (new DateTimeImmutable($data . ' ' . $hora . ':00') <= $agora) continue;
                $vagas[] = ['data' => $data, 'hora' => $hora];
                if (count($vagas) >= $quantidade) break;
            }
        }
        return $vagas;
    }

    private function horaEmMinutos(string $hora): int
    {
        [$horas, $minutos] = array_map('intval', explode(':', substr($hora, 0, 5)));
        return ($horas * 60) + $minutos;
    }

    /** @return array<string,mixed> */
    private function carregarPeriodo(
        int $empresaId,
        int $profissionalId,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fimExclusivo,
        bool $usarIntervaloIa = false
    ): array {
        $profissional = $this->pdo->prepare(
            "SELECT id FROM usuarios
             WHERE id = :profissional AND empresa_id = :empresa
               AND nivel_acesso != 'secretaria'
             LIMIT 1"
        );
        $profissional->execute([':profissional' => $profissionalId, ':empresa' => $empresaId]);
        if (!$profissional->fetchColumn()) {
            throw new DomainException('PROFISSIONAL_NAO_ENCONTRADO');
        }

        $intervalo = $this->carregarIntervalo($profissionalId, $usarIntervaloIa);

        $expedientes = [];
        $stmt = $this->pdo->prepare(
            'SELECT dia_semana, hora_inicio, hora_fim
             FROM agenda_dias_horarios
             WHERE usuario_id = :profissional AND ativo = 1
               AND hora_inicio IS NOT NULL AND hora_fim IS NOT NULL'
        );
        $stmt->execute([':profissional' => $profissionalId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $expedientes[(int)$item['dia_semana']] = [
                substr((string)$item['hora_inicio'], 0, 8),
                substr((string)$item['hora_fim'], 0, 8),
            ];
        }

        $intervalos = [];
        $stmt = $this->pdo->prepare(
            'SELECT dia_semana, hora_inicio, hora_fim
             FROM agenda_intervalos_dia WHERE usuario_id = :profissional'
        );
        $stmt->execute([':profissional' => $profissionalId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $intervalos[(int)$item['dia_semana']][] = [
                substr((string)$item['hora_inicio'], 0, 8),
                substr((string)$item['hora_fim'], 0, 8),
            ];
        }

        $bloqueiosData = [];
        $bloqueiosRecorrentes = [];
        $stmt = $this->pdo->prepare(
            'SELECT data, recorrente FROM agenda_bloqueios
             WHERE usuario_id = :profissional
               AND ((data >= :inicio AND data < :fim) OR (data IS NULL AND recorrente IS NOT NULL))'
        );
        $stmt->execute([
            ':profissional' => $profissionalId,
            ':inicio' => $inicio->format('Y-m-d'),
            ':fim' => $fimExclusivo->format('Y-m-d'),
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if (!empty($item['data'])) {
                $bloqueiosData[substr((string)$item['data'], 0, 10)] = true;
            } elseif (!empty($item['recorrente'])) {
                $bloqueiosRecorrentes[(string)$item['recorrente']] = true;
            }
        }

        $ocupados = [];
        $inicioPeriodo = $inicio->format('Y-m-d H:i:s');
        $fimPeriodo = $fimExclusivo->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT data_hora_inicio AS inicio, data_hora_fim AS fim
             FROM agendamentos
             WHERE profissional_id = :profissional AND empresa_id = :empresa
               AND data_hora_inicio < :fim_periodo AND data_hora_fim > :inicio_periodo'
        );
        $stmt->execute([
            ':profissional' => $profissionalId,
            ':empresa' => $empresaId,
            ':inicio_periodo' => $inicioPeriodo,
            ':fim_periodo' => $fimPeriodo,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $ocupados[] = [(new DateTimeImmutable((string)$item['inicio']))->getTimestamp(), (new DateTimeImmutable((string)$item['fim']))->getTimestamp(), false];
        }

        $stmt = $this->pdo->prepare(
            'SELECT data_inicio AS inicio,
                    IF(data_fim IS NULL OR data_fim <= data_inicio, DATE_ADD(data_inicio, INTERVAL 15 MINUTE), data_fim) AS fim,
                    dia_todo
             FROM compromissos
             WHERE profissional_id = :profissional
               AND data_inicio < :fim_periodo
               AND IF(data_fim IS NULL OR data_fim < data_inicio, data_inicio, data_fim) >= :inicio_periodo'
        );
        $stmt->execute([
            ':profissional' => $profissionalId,
            ':inicio_periodo' => $inicioPeriodo,
            ':fim_periodo' => $fimPeriodo,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $ocupados[] = [(new DateTimeImmutable((string)$item['inicio']))->getTimestamp(), (new DateTimeImmutable((string)$item['fim']))->getTimestamp(), (int)$item['dia_todo'] === 1];
        }

        $feriados = (new AgendaFeriadoService($this->pdo))->datasNoPeriodo(
            $empresaId,
            $inicio,
            $fimExclusivo
        );

        return [
            'intervalo' => $intervalo,
            'expedientes' => $expedientes,
            'intervalos' => $intervalos,
            'bloqueios_data' => $bloqueiosData,
            'bloqueios_recorrentes' => $bloqueiosRecorrentes,
            'feriados' => $feriados,
            'ocupados' => $ocupados,
        ];
    }

    private function carregarIntervalo(int $profissionalId, bool $usarIntervaloIa): int
    {
        $coluna = $usarIntervaloIa
            ? 'COALESCE(intervalo_ia_minutos, intervalo_minutos)'
            : 'intervalo_minutos';
        try {
            $config = $this->pdo->prepare(
                "SELECT {$coluna} FROM configuracoes_agenda_usuario WHERE usuario_id = :profissional LIMIT 1"
            );
            $config->execute([':profissional' => $profissionalId]);
            $intervalo = (int)($config->fetchColumn() ?: 30);
        } catch (PDOException $e) {
            if (!$usarIntervaloIa || (string)$e->getCode() !== '42S22') {
                throw $e;
            }
            error_log('[agenda_disponibilidade] intervalo_ia_minutos ausente; usando intervalo_minutos');
            return $this->carregarIntervalo($profissionalId, false);
        }
        return ($intervalo >= 5 && $intervalo <= 480) ? $intervalo : 30;
    }

    /** @param array<string,mixed> $periodo @return list<string> */
    private function horariosDoDia(array $periodo, DateTimeImmutable $dia): array
    {
        $data = $dia->format('Y-m-d');
        $diaSemana = (int)$dia->format('N');
        if (
            !isset($periodo['expedientes'][$diaSemana])
            || isset($periodo['bloqueios_data'][$data])
            || isset($periodo['bloqueios_recorrentes'][$dia->format('m-d')])
            || isset($periodo['feriados'][$data])
        ) {
            return [];
        }

        [$horaInicio, $horaFim] = $periodo['expedientes'][$diaSemana];
        $inicioDia = $dia->getTimestamp();
        $fimDia = $dia->modify('+1 day')->getTimestamp();
        $ocupados = [];
        foreach ($periodo['ocupados'] as [$inicio, $fim, $diaTodo]) {
            if ($inicio < $fimDia && $fim > $inicioDia) {
                if ($diaTodo) {
                    return [];
                }
                $ocupados[] = [$inicio, $fim];
            }
        }
        foreach ($periodo['intervalos'][$diaSemana] ?? [] as [$inicio, $fim]) {
            $ocupados[] = [
                (new DateTimeImmutable($data . ' ' . $inicio))->getTimestamp(),
                (new DateTimeImmutable($data . ' ' . $fim))->getTimestamp(),
            ];
        }

        $intervalo = (int)$periodo['intervalo'];
        $duracao = $intervalo;
        $cursor = new DateTimeImmutable($data . ' ' . $horaInicio);
        $fimExpediente = new DateTimeImmutable($data . ' ' . $horaFim);
        $horarios = [];
        while ($cursor->modify('+' . $duracao . ' minutes') <= $fimExpediente) {
            $fimSlot = $cursor->modify('+' . $duracao . ' minutes');
            $cursorTs = $cursor->getTimestamp();
            $fimSlotTs = $fimSlot->getTimestamp();
            $livre = true;
            foreach ($ocupados as [$inicioOcupado, $fimOcupado]) {
                if ($cursorTs < $fimOcupado && $fimSlotTs > $inicioOcupado) {
                    $livre = false;
                    break;
                }
            }
            if ($livre) {
                $horarios[] = $cursor->format('H:i');
            }
            $cursor = $cursor->modify('+' . $intervalo . ' minutes');
        }
        return $horarios;
    }
}
