<?php

declare(strict_types=1);

/**
 * Calendário conservador usado pela disponibilidade automática.
 *
 * Considera os feriados cadastrados pela empresa e os feriados nacionais de
 * data fixa. Datas locais ou facultativas devem ser cadastradas em
 * agenda_feriados para refletir a rotina real da clínica.
 */
final class AgendaFeriadoService
{
    private const NACIONAIS_FIXOS = [
        '01-01', // Confraternização Universal
        '04-21', // Tiradentes
        '05-01', // Dia Mundial do Trabalho
        '09-07', // Independência do Brasil
        '10-12', // Nossa Senhora Aparecida
        '11-02', // Finados
        '11-15', // Proclamação da República
        '11-20', // Dia Nacional de Zumbi e da Consciência Negra
        '12-25', // Natal
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function ehFeriado(int $empresaId, string $data): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            throw new InvalidArgumentException('DATA_INVALIDA');
        }
        if (in_array(substr($data, 5), self::NACIONAIS_FIXOS, true)) {
            return true;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM agenda_feriados
              WHERE empresa_id = :empresa AND data = :data LIMIT 1'
        );
        $stmt->execute([':empresa' => $empresaId, ':data' => $data]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array<string,true> */
    public function datasNoPeriodo(
        int $empresaId,
        DateTimeImmutable $inicio,
        DateTimeImmutable $fimExclusivo
    ): array {
        $feriados = [];
        $stmt = $this->pdo->prepare(
            'SELECT data FROM agenda_feriados
              WHERE empresa_id = :empresa AND data >= :inicio AND data < :fim'
        );
        $stmt->execute([
            ':empresa' => $empresaId,
            ':inicio' => $inicio->format('Y-m-d'),
            ':fim' => $fimExclusivo->format('Y-m-d'),
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $data) {
            $feriados[substr((string)$data, 0, 10)] = true;
        }

        for ($ano = (int)$inicio->format('Y'); $ano <= (int)$fimExclusivo->format('Y'); $ano++) {
            foreach (self::NACIONAIS_FIXOS as $mesDia) {
                $data = sprintf('%04d-%s', $ano, $mesDia);
                if ($data >= $inicio->format('Y-m-d') && $data < $fimExclusivo->format('Y-m-d')) {
                    $feriados[$data] = true;
                }
            }
        }
        return $feriados;
    }
}
