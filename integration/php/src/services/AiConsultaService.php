<?php

declare(strict_types=1);

require_once __DIR__ . '/TelefoneNormalizer.php';

final class AiConsultaService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array{id:int,nome:string,telefone:string}> */
    public function buscarPacientes(int $empresaId, ?string $telefone, ?int $pacienteId, ?string $nome): array
    {
        $where = ['p.empresa_id = :empresa', 'COALESCE(p.excluido, 0) = 0'];
        $params = [':empresa' => $empresaId];

        if ($telefone !== null && trim($telefone) !== '') {
            $variantes = TelefoneNormalizer::variantesBrasil($telefone);
            $expressao = TelefoneNormalizer::somenteDigitosSql('p.celular');
            $where[] = "{$expressao} IN (:telefone_local, :telefone_pais)";
            $params[':telefone_local'] = $variantes[0];
            $params[':telefone_pais'] = $variantes[1];
        } elseif ($pacienteId !== null && $pacienteId > 0) {
            $where[] = 'p.id = :paciente';
            $params[':paciente'] = $pacienteId;
        } elseif ($nome !== null && mb_strlen(trim($nome), 'UTF-8') >= 2) {
            $where[] = 'p.nome COLLATE utf8mb4_unicode_ci LIKE :nome';
            $params[':nome'] = '%' . trim($nome) . '%';
        } else {
            throw new InvalidArgumentException('FILTRO_OBRIGATORIO');
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.nome, p.celular AS telefone FROM pacientes p WHERE '
            . implode(' AND ', $where) . ' ORDER BY p.nome, p.id LIMIT 11'
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'nome' => (string)$row['nome'],
            'telefone' => (string)($row['telefone'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function buscarAgendamentos(
        int $empresaId,
        int $pacienteId,
        ?string $data,
        ?int $profissionalId,
        ?string $status
    ): array {
        $paciente = $this->pdo->prepare(
            'SELECT 1 FROM pacientes WHERE id = :paciente AND empresa_id = :empresa AND COALESCE(excluido, 0) = 0'
        );
        $paciente->execute([':paciente' => $pacienteId, ':empresa' => $empresaId]);
        if (!$paciente->fetchColumn()) {
            throw new DomainException('PACIENTE_NAO_ENCONTRADO');
        }

        $where = ['a.empresa_id = :empresa', 'a.paciente_id = :paciente'];
        $params = [':empresa' => $empresaId, ':paciente' => $pacienteId];
        if ($data !== null) {
            $where[] = 'a.data_hora_inicio >= :data_inicio AND a.data_hora_inicio < :data_fim';
            $params[':data_inicio'] = $data . ' 00:00:00';
            $params[':data_fim'] = (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d H:i:s');
        } else {
            $where[] = 'a.data_hora_fim >= NOW()';
        }
        if ($profissionalId !== null) {
            $where[] = 'a.profissional_id = :profissional';
            $params[':profissional'] = $profissionalId;
        }
        if ($status !== null) {
            $where[] = 'a.status = :status';
            $params[':status'] = $status;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.paciente_id, a.profissional_id, u.nome AS profissional_nome, '
            . 'a.data_hora_inicio, a.data_hora_fim, a.status '
            . 'FROM agendamentos a '
            . 'JOIN usuarios u ON u.id = a.profissional_id AND u.empresa_id = a.empresa_id '
            . 'WHERE ' . implode(' AND ', $where)
            . ' ORDER BY a.data_hora_inicio ASC, a.id ASC LIMIT 50'
        );
        $stmt->execute($params);

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'paciente_id' => (int)$row['paciente_id'],
            'profissional_id' => (int)$row['profissional_id'],
            'profissional_nome' => (string)$row['profissional_nome'],
            'data' => substr((string)$row['data_hora_inicio'], 0, 10),
            'hora_inicio' => substr((string)$row['data_hora_inicio'], 11, 5),
            'hora_fim' => substr((string)$row['data_hora_fim'], 11, 5),
            'status' => (string)($row['status'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
