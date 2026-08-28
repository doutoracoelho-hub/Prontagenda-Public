<?php

declare(strict_types=1);

require_once __DIR__ . '/AgendaDisponibilidadeService.php';
require_once __DIR__ . '/TelefoneNormalizer.php';
require_once __DIR__ . '/WhatsAppAiTemplateService.php';
require_once __DIR__ . '/WhatsAppIntegrationService.php';

final class WhatsAppAgendamentoService
{
    private const VALIDADE_MINUTOS = 10;
    private const ROTULO_NOME = 'Agendado pela IA';
    private const ROTULO_COR = '#7C3AED';
    private const IDENTIDADE_AGUARDANDO_DESTINATARIO = 'aguardando_destinatario';
    private const IDENTIDADE_AGUARDANDO_NOME = 'aguardando_nome';
    private const IDENTIDADE_CONFIRMADA = 'confirmada';

    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Registra a opção que será apresentada ao paciente. Ainda não cria agendamento.
     *
     * @return array{solicitacao_id:int,solicitacao_uuid:string,confirmacao_token:string,status:string,expira_em:string,data_hora_inicio:string,data_hora_fim:string,profissional_nome:string}
     */
    public function preparar(
        array $contexto,
        int $profissionalId,
        string $dataHoraInicio,
        ?string $pacienteNome,
        ?int $mensagemOrigemId = null
    ): array {
        $empresaId = (int)($contexto['empresa_id'] ?? 0);
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        if ($empresaId < 1 || $conversaId < 1 || $profissionalId < 1) {
            throw new InvalidArgumentException('DADOS_SOLICITACAO_INVALIDOS');
        }

        $inicio = $this->dataHora($dataHoraInicio);
        if ($inicio <= new DateTimeImmutable()) {
            throw new DomainException('HORARIO_PASSADO');
        }
        $this->validarEscolhaHorarioContextual($empresaId, $conversaId, $inicio);

        $profissional = $this->buscarProfissional($empresaId, $profissionalId);
        $duracao = $this->duracaoProfissional($profissionalId);
        $fim = $inicio->modify('+' . $duracao . ' minutes');
        $disponibilidade = (new AgendaDisponibilidadeService($this->pdo))->buscar(
            $empresaId,
            $profissionalId,
            $inicio->format('Y-m-d'),
            $duracao,
            true
        );
        if (!in_array($inicio->format('H:i'), $disponibilidade['horarios'], true)) {
            throw new DomainException('VAGA_NAO_DISPONIVEL');
        }
        [$pacienteId, $nome, $telefone, $identidadeStatus] = $this->identidadePaciente($contexto, $pacienteNome);

        $idempotencia = hash('sha256', implode('|', [
            $empresaId,
            $conversaId,
            $profissionalId,
            $inicio->format('Y-m-d H:i:s'),
            $mensagemOrigemId ?: 0,
        ]));

        $existente = $this->buscarPorIdempotencia($idempotencia);
        if (
            $existente !== null
            && $existente['status'] === 'aguardando_confirmacao'
            && new DateTimeImmutable((string)$existente['expira_em']) >= new DateTimeImmutable()
        ) {
            return $this->respostaSolicitacao($existente, (string)$profissional['nome']);
        }
        if ($existente !== null && $existente['status'] === 'confirmado') {
            if ($this->agendamentoConfirmadoAindaExiste($existente, $empresaId)) {
                throw new DomainException('VAGA_JA_CONFIRMADA');
            }

            // A solicitação é apenas o histórico da conversa. Se o agendamento
            // vinculado foi removido da agenda, a vaga pode ser proposta novamente.
            $this->invalidarConfirmacaoSemAgendamento((int)$existente['id']);
            $existente = $this->buscarPorIdempotencia($idempotencia);
        }

        // Uma nova escolha invalida qualquer proposta anterior da conversa.
        // Isso impede que um "sim" posterior confirme silenciosamente outra vaga.
        $cancelar = $this->pdo->prepare(
            "UPDATE whatsapp_agendamento_solicitacoes
                SET status = 'cancelado', erro_codigo = 'SUBSTITUIDA_POR_NOVA_ESCOLHA', atualizado_em = NOW()
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND status = 'aguardando_confirmacao'"
        );
        $cancelar->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);

        $expiraEm = (new DateTimeImmutable())->modify('+' . self::VALIDADE_MINUTOS . ' minutes');
        if ($existente !== null) {
            $this->reativarSolicitacao(
                $existente,
                $pacienteId,
                $nome,
                $telefone,
                $fim,
                $duracao,
                $mensagemOrigemId,
                $expiraEm,
                $identidadeStatus
            );
            $reativada = $this->buscarPorIdempotencia($idempotencia);
            if ($reativada === null || $reativada['status'] !== 'aguardando_confirmacao') {
                throw new RuntimeException('SOLICITACAO_NAO_REATIVADA');
            }
            return $this->respostaSolicitacao($reativada, (string)$profissional['nome']);
        }

        $uuid = $this->uuidV4();
        $token = $this->tokenConfirmacao($uuid);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO whatsapp_agendamento_solicitacoes
                 (solicitacao_uuid, empresa_id, conversa_id, profissional_id, paciente_id,
                  paciente_nome, identidade_status, telefone, data_hora_inicio, data_hora_fim, duracao_minutos,
                  status, idempotency_key, confirmacao_token_hash, mensagem_origem_id, expira_em,
                  criado_em, atualizado_em)
                 VALUES
                 (:uuid, :empresa, :conversa, :profissional, :paciente, :nome, :identidade_status,
                  :telefone, :inicio, :fim, :duracao, \'aguardando_confirmacao\', :idempotencia,
                  :token_hash, :mensagem, :expira, NOW(), NOW())'
            );
            $stmt->execute([
                ':uuid' => $uuid,
                ':empresa' => $empresaId,
                ':conversa' => $conversaId,
                ':profissional' => $profissionalId,
                ':paciente' => $pacienteId,
                ':nome' => $nome,
                ':identidade_status' => $identidadeStatus,
                ':telefone' => $telefone,
                ':inicio' => $inicio->format('Y-m-d H:i:s'),
                ':fim' => $fim->format('Y-m-d H:i:s'),
                ':duracao' => $duracao,
                ':idempotencia' => $idempotencia,
                ':token_hash' => hash('sha256', $token),
                ':mensagem' => $mensagemOrigemId,
                ':expira' => $expiraEm->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '23000') {
                throw $e;
            }
            $existente = $this->buscarPorIdempotencia($idempotencia);
            if ($existente === null) {
                throw $e;
            }
            return $this->respostaSolicitacao($existente, (string)$profissional['nome']);
        }

        return [
            'solicitacao_id' => (int)$this->pdo->lastInsertId(),
            'solicitacao_uuid' => $uuid,
            'confirmacao_token' => $token,
            'status' => 'aguardando_confirmacao',
            'expira_em' => $expiraEm->format('Y-m-d H:i:s'),
            'data_hora_inicio' => $inicio->format('Y-m-d H:i:s'),
            'data_hora_fim' => $fim->format('Y-m-d H:i:s'),
            'profissional_nome' => (string)$profissional['nome'],
            'paciente_nome' => $nome,
            'identidade_status' => $identidadeStatus,
        ];
    }

    /** @return array{agendamento_id:int,ja_confirmado:bool,data_hora_inicio:string,profissional_nome:string} */
    public function confirmar(array $contexto, string $solicitacaoUuid, string $token): array
    {
        $empresaId = (int)($contexto['empresa_id'] ?? 0);
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        if (!$this->escritaAtiva($empresaId)) {
            throw new DomainException('AGENDAMENTO_IA_DESATIVADO');
        }
        if (!preg_match('/^[a-f0-9-]{36}$/i', $solicitacaoUuid) || strlen($token) < 32) {
            throw new DomainException('CONFIRMACAO_INVALIDA');
        }

        $lock = null;
        try {
            $solicitacao = $this->buscarSolicitacao($solicitacaoUuid, $empresaId, $conversaId);
            if ($solicitacao === null || !hash_equals((string)$solicitacao['confirmacao_token_hash'], hash('sha256', $token))) {
                throw new DomainException('CONFIRMACAO_INVALIDA');
            }
            if (
                $solicitacao['status'] === 'confirmado'
                && !empty($solicitacao['agendamento_id'])
                && $this->agendamentoConfirmadoAindaExiste($solicitacao, $empresaId)
            ) {
                $profissional = $this->buscarProfissional($empresaId, (int)$solicitacao['profissional_id']);
                return $this->confirmacaoExistente($solicitacao, (string)$profissional['nome']);
            }
            if ($solicitacao['status'] === 'confirmado') {
                throw new DomainException('SOLICITACAO_NAO_CONFIRMAVEL');
            }

            $lock = 'prontagenda:agenda:' . hash('sha256', implode('|', [
                $empresaId,
                (int)$solicitacao['profissional_id'],
                substr((string)$solicitacao['data_hora_inicio'], 0, 10),
            ]));
            if (!$this->obterLock($lock)) {
                throw new RuntimeException('AGENDA_OCUPADA_TEMPORARIAMENTE');
            }

            $this->pdo->beginTransaction();
            $solicitacao = $this->buscarSolicitacao($solicitacaoUuid, $empresaId, $conversaId, true);
            if ($solicitacao === null || !hash_equals((string)$solicitacao['confirmacao_token_hash'], hash('sha256', $token))) {
                throw new DomainException('CONFIRMACAO_INVALIDA');
            }
            if (
                $solicitacao['status'] === 'confirmado'
                && !empty($solicitacao['agendamento_id'])
                && $this->agendamentoConfirmadoAindaExiste($solicitacao, $empresaId)
            ) {
                $this->pdo->commit();
                $profissional = $this->buscarProfissional($empresaId, (int)$solicitacao['profissional_id']);
                return $this->confirmacaoExistente($solicitacao, (string)$profissional['nome']);
            }
            if ($solicitacao['status'] !== 'aguardando_confirmacao') {
                throw new DomainException('SOLICITACAO_NAO_CONFIRMAVEL');
            }
            if (($solicitacao['identidade_status'] ?? self::IDENTIDADE_CONFIRMADA) !== self::IDENTIDADE_CONFIRMADA
                || mb_strlen(trim((string)$solicitacao['paciente_nome']), 'UTF-8') < 2) {
                throw new DomainException('IDENTIDADE_NAO_CONFIRMADA');
            }
            if (new DateTimeImmutable((string)$solicitacao['expira_em']) < new DateTimeImmutable()) {
                $this->alterarStatus((int)$solicitacao['id'], 'expirado', 'SOLICITACAO_EXPIRADA');
                $this->pdo->commit();
                throw new DomainException('SOLICITACAO_EXPIRADA');
            }

            $inicio = new DateTimeImmutable((string)$solicitacao['data_hora_inicio']);
            $disponibilidade = (new AgendaDisponibilidadeService($this->pdo))->buscar(
                $empresaId,
                (int)$solicitacao['profissional_id'],
                $inicio->format('Y-m-d'),
                (int)$solicitacao['duracao_minutos'],
                true
            );
            if (!in_array($inicio->format('H:i'), $disponibilidade['horarios'], true)) {
                $this->alterarStatus((int)$solicitacao['id'], 'conflito', 'VAGA_NAO_DISPONIVEL');
                $this->pdo->commit();
                throw new DomainException('VAGA_NAO_DISPONIVEL');
            }

            $rotuloId = $this->obterOuCriarRotulo((int)$solicitacao['profissional_id']);
            $stmt = $this->pdo->prepare(
                'INSERT INTO agendamentos
                 (profissional_id, paciente_id, paciente_nome, telefone, enviar_whatsapp,
                  data_hora_inicio, data_hora_fim, rotulo_id, retorno, observacoes,
                  duracao_minutos, empresa_id, origem_agendamento, whatsapp_conversa_id,
                  whatsapp_solicitacao_id, criado_em, atualizado_em)
                 VALUES
                 (:profissional, :paciente, :nome, :telefone, 1, :inicio, :fim, :rotulo,
                  NULL, :observacoes, :duracao, :empresa, \'whatsapp_ia\', :conversa,
                  :solicitacao, NOW(), NOW())'
            );
            $stmt->execute([
                ':profissional' => (int)$solicitacao['profissional_id'],
                ':paciente' => $solicitacao['paciente_id'] !== null ? (int)$solicitacao['paciente_id'] : null,
                ':nome' => (string)$solicitacao['paciente_nome'],
                ':telefone' => (string)$solicitacao['telefone'],
                ':inicio' => (string)$solicitacao['data_hora_inicio'],
                ':fim' => (string)$solicitacao['data_hora_fim'],
                ':rotulo' => $rotuloId,
                ':observacoes' => 'Agendamento criado automaticamente pelo assistente do WhatsApp.',
                ':duracao' => (int)$solicitacao['duracao_minutos'],
                ':empresa' => $empresaId,
                ':conversa' => $conversaId,
                ':solicitacao' => (int)$solicitacao['id'],
            ]);
            $agendamentoId = (int)$this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare(
                "UPDATE whatsapp_agendamento_solicitacoes
                    SET status = 'confirmado', agendamento_id = :agendamento,
                        confirmado_em = NOW(), erro_codigo = NULL, atualizado_em = NOW()
                  WHERE id = :id"
            );
            $stmt->execute([':agendamento' => $agendamentoId, ':id' => (int)$solicitacao['id']]);
            $this->pdo->commit();

            try {
                (new WhatsAppIntegrationService($this->pdo))->enfileirarMensagensAgendamento($agendamentoId);
            } catch (Throwable $e) {
                error_log('[whatsapp_agendamento_notificacao] agendamento=' . $agendamentoId . ' erro=' . $e->getMessage());
            }

            $profissional = $this->buscarProfissional($empresaId, (int)$solicitacao['profissional_id']);
            return [
                'agendamento_id' => $agendamentoId,
                'ja_confirmado' => false,
                'data_hora_inicio' => (string)$solicitacao['data_hora_inicio'],
                'profissional_nome' => (string)$profissional['nome'],
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($lock !== null) {
                $this->liberarLock($lock);
            }
        }
    }

    public function temSolicitacaoPendente(int $empresaId, int $conversaId): bool
    {
        if (!$this->escritaAtiva($empresaId)) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM whatsapp_agendamento_solicitacoes
                  WHERE empresa_id = :empresa AND conversa_id = :conversa
                    AND status = 'aguardando_confirmacao' AND expira_em >= NOW()
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('[whatsapp_agendamento_pendente] ' . $e->getMessage());
            return false;
        }
    }

    public function cancelarPendentes(array $contexto, string $motivo = 'PREFERENCIA_ALTERADA'): int
    {
        $empresaId = (int)($contexto['empresa_id'] ?? 0);
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        if ($empresaId < 1 || $conversaId < 1) {
            throw new InvalidArgumentException('CONTEXTO_INVALIDO');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE whatsapp_agendamento_solicitacoes
                SET status = 'cancelado', erro_codigo = :motivo, atualizado_em = NOW()
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND status = 'aguardando_confirmacao'"
        );
        $stmt->execute([
            ':motivo' => substr(trim($motivo) ?: 'PREFERENCIA_ALTERADA', 0, 80),
            ':empresa' => $empresaId,
            ':conversa' => $conversaId,
        ]);
        return $stmt->rowCount();
    }

    /** @return array{agendamento_id:int,ja_confirmado:bool,data_hora_inicio:string,profissional_nome:string} */
    public function confirmarPendente(array $contexto): array
    {
        $empresaId = (int)($contexto['empresa_id'] ?? 0);
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        $stmt = $this->pdo->prepare(
            "SELECT solicitacao_uuid FROM whatsapp_agendamento_solicitacoes
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND status = 'aguardando_confirmacao' AND expira_em >= NOW()
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);
        $uuid = (string)($stmt->fetchColumn() ?: '');
        if ($uuid === '') {
            throw new DomainException('SOLICITACAO_NAO_CONFIRMAVEL');
        }
        return $this->confirmar($contexto, $uuid, $this->tokenConfirmacao($uuid));
    }

    private function escritaAtiva(int $empresaId): bool
    {
        $global = filter_var(getenv('PRONTAGENDA_AI_AGENDAMENTO_WRITE_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if (!$global || $empresaId < 1) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT escrita_ativa FROM whatsapp_ai_agendamento_config WHERE empresa_id = :empresa LIMIT 1');
            $stmt->execute([':empresa' => $empresaId]);
            return (int)($stmt->fetchColumn() ?: 0) === 1;
        } catch (PDOException $e) {
            error_log('[whatsapp_agendamento_config] ' . $e->getMessage());
            return false;
        }
    }

    /** @return array{0:int|null,1:string,2:string,3:string} */
    private function identidadePaciente(array $contexto, ?string $nomeInformado): array
    {
        $empresaId = (int)$contexto['empresa_id'];
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        $pacienteId = !empty($contexto['paciente_id']) ? (int)$contexto['paciente_id'] : null;
        $telefone = TelefoneNormalizer::variantesBrasil((string)($contexto['telefone'] ?? ''))[0];
        if ($conversaId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT agendamento_paciente_nome_pendente FROM whatsapp_conversas
                  WHERE id = :conversa AND empresa_id = :empresa LIMIT 1'
            );
            $stmt->execute([':conversa' => $conversaId, ':empresa' => $empresaId]);
            $nomeTerceiro = trim((string)($stmt->fetchColumn() ?: ''));
            if ($nomeTerceiro !== '') {
                $this->pdo->prepare(
                    'UPDATE whatsapp_conversas SET agendamento_paciente_nome_pendente = NULL
                      WHERE id = :conversa AND empresa_id = :empresa'
                )->execute([':conversa' => $conversaId, ':empresa' => $empresaId]);
                return [null, $nomeTerceiro, $telefone, self::IDENTIDADE_CONFIRMADA];
            }
        }
        if ($pacienteId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT nome, celular FROM pacientes
                  WHERE id = :paciente AND empresa_id = :empresa AND COALESCE(excluido, 0) = 0 LIMIT 1'
            );
            $stmt->execute([':paciente' => $pacienteId, ':empresa' => $empresaId]);
            $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$paciente) {
                throw new DomainException('PACIENTE_INVALIDO');
            }
            return [$pacienteId, (string)$paciente['nome'], $telefone, self::IDENTIDADE_AGUARDANDO_DESTINATARIO];
        }

        $nome = trim((string)$nomeInformado);
        if (mb_strlen($nome, 'UTF-8') > 255) {
            throw new DomainException('NOME_PACIENTE_INVALIDO');
        }
        if ($nome !== '') {
            return [null, $nome, $telefone, self::IDENTIDADE_AGUARDANDO_DESTINATARIO];
        }

        $variantes = TelefoneNormalizer::variantesBrasil($telefone);
        $expressao = TelefoneNormalizer::somenteDigitosSql('celular');
        $placeholders = [];
        $parametros = [':empresa' => $empresaId];
        foreach ($variantes as $indice => $variante) {
            $placeholder = ':telefone_' . $indice;
            $placeholders[] = $placeholder;
            $parametros[$placeholder] = $variante;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, nome FROM pacientes
              WHERE empresa_id = :empresa AND COALESCE(excluido, 0) = 0
                AND {$expressao} IN (" . implode(', ', $placeholders) . ")
              ORDER BY id LIMIT 2"
        );
        $stmt->execute($parametros);
        $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($pacientes) === 1) {
            return [
                (int)$pacientes[0]['id'],
                (string)$pacientes[0]['nome'],
                $telefone,
                self::IDENTIDADE_AGUARDANDO_DESTINATARIO,
            ];
        }

        // Nenhum ou mais de um cadastro: não escolhe por aproximação.
        return [null, '', $telefone, self::IDENTIDADE_AGUARDANDO_NOME];
    }

    private function buscarProfissional(int $empresaId, int $profissionalId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nome FROM usuarios
              WHERE id = :id AND empresa_id = :empresa AND LOWER(nivel_acesso) != 'secretaria' LIMIT 1"
        );
        $stmt->execute([':id' => $profissionalId, ':empresa' => $empresaId]);
        $profissional = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$profissional) {
            throw new DomainException('PROFISSIONAL_NAO_ENCONTRADO');
        }
        return $profissional;
    }

    public function temIdentidadePendente(int $empresaId, int $conversaId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM whatsapp_agendamento_solicitacoes
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND status = 'aguardando_confirmacao'
                AND identidade_status IN ('aguardando_destinatario', 'aguardando_nome')
                AND expira_em >= NOW()
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return array{resposta_template:string,identidade_confirmada:bool} */
    public function processarIdentidadePendente(array $contexto, string $mensagem): array
    {
        $empresaId = (int)($contexto['empresa_id'] ?? 0);
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM whatsapp_agendamento_solicitacoes
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND status = 'aguardando_confirmacao'
                AND identidade_status IN ('aguardando_destinatario', 'aguardando_nome')
                AND expira_em >= NOW()
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);
        $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$solicitacao) {
            throw new DomainException('SOLICITACAO_NAO_CONFIRMAVEL');
        }

        $estado = (string)$solicitacao['identidade_status'];
        if ($estado === self::IDENTIDADE_AGUARDANDO_NOME) {
            $nome = $this->extrairNomeCompleto($mensagem);
            if ($nome === null) {
                return [
                    'resposta_template' => WhatsAppAiTemplateService::solicitarNomeAtendido(),
                    'identidade_confirmada' => false,
                ];
            }
            $this->confirmarIdentidadeSolicitacao((int)$solicitacao['id'], null, $nome);
            return $this->respostaIdentidadeConfirmada($solicitacao, $nome);
        }

        $decisao = $this->decisaoDestinatario($mensagem);
        if ($decisao === 'titular') {
            $nome = trim((string)$solicitacao['paciente_nome']);
            if ($nome === '') {
                $this->aguardarNomeSolicitacao((int)$solicitacao['id'], false);
                return [
                    'resposta_template' => WhatsAppAiTemplateService::solicitarNomeAtendido(),
                    'identidade_confirmada' => false,
                ];
            }
            $this->confirmarIdentidadeSolicitacao(
                (int)$solicitacao['id'],
                $solicitacao['paciente_id'] !== null ? (int)$solicitacao['paciente_id'] : null,
                $nome
            );
            return $this->respostaIdentidadeConfirmada($solicitacao, $nome);
        }

        if ($decisao === 'terceiro') {
            $nome = $this->extrairNomeCompleto($mensagem, true);
            if ($nome !== null) {
                $this->confirmarIdentidadeSolicitacao((int)$solicitacao['id'], null, $nome);
                return $this->respostaIdentidadeConfirmada($solicitacao, $nome);
            }
            $this->aguardarNomeSolicitacao((int)$solicitacao['id'], true);
            return [
                'resposta_template' => WhatsAppAiTemplateService::solicitarNomeAtendido(),
                'identidade_confirmada' => false,
            ];
        }

        return [
            'resposta_template' => WhatsAppAiTemplateService::confirmarDestinatario(
                trim((string)$solicitacao['paciente_nome']) ?: null
            ),
            'identidade_confirmada' => false,
        ];
    }

    public function corrigirDestinatarioConfirmacao(array $contexto, string $mensagem): ?array
    {
        if (!preg_match(
            '/^\s*n[aã]o\b.{0,40}\b(?:[ée]\s+para|paciente\s+[ée]|consulta\s+[ée]\s+para)\b/iu',
            trim($mensagem)
        )) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM whatsapp_agendamento_solicitacoes
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND status = 'aguardando_confirmacao' AND expira_em >= NOW()
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([
            ':empresa' => (int)($contexto['empresa_id'] ?? 0),
            ':conversa' => (int)($contexto['conversa_id'] ?? 0),
        ]);
        $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$solicitacao) {
            return null;
        }
        $nome = $this->extrairNomeCompleto($mensagem, true);
        if ($nome === null) {
            $this->aguardarNomeSolicitacao((int)$solicitacao['id'], true);
            return [
                'resposta_template' => WhatsAppAiTemplateService::solicitarNomeAtendido(),
                'identidade_confirmada' => false,
            ];
        }
        $atualizar = $this->pdo->prepare(
            "UPDATE whatsapp_agendamento_solicitacoes
                SET paciente_id = NULL, paciente_nome = :nome,
                    identidade_status = 'confirmada', identidade_confirmada_em = NOW(),
                    atualizado_em = NOW()
              WHERE id = :id AND status = 'aguardando_confirmacao'"
        );
        $atualizar->execute([':nome' => $nome, ':id' => (int)$solicitacao['id']]);
        return $this->respostaIdentidadeConfirmada($solicitacao, $nome);
    }

    public function mensagemHorarioPendente(array $contexto, string $dataHoraInicio): string
    {
        $empresaId = (int)($contexto['empresa_id'] ?? 0);
        $conversaId = (int)($contexto['conversa_id'] ?? 0);
        try {
            $inicio = $this->dataHora($dataHoraInicio);
        } catch (Throwable) {
            return 'Qual horário você prefere? Pode me dizer a hora novamente?';
        }

        $dataPtBr = $inicio->format('d/m/Y');
        $stmt = $this->pdo->prepare(
            "SELECT mensagem FROM whatsapp_mensagens
              WHERE empresa_id = :empresa AND conversa_id = :conversa
                AND direction = 'outbound' AND mensagem LIKE :data
              ORDER BY id DESC LIMIT 10"
        );
        $stmt->execute([
            ':empresa' => $empresaId,
            ':conversa' => $conversaId,
            ':data' => '%' . $dataPtBr . '%',
        ]);

        $horarios = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mensagem) {
            $padrao = '/\b' . preg_quote($dataPtBr, '/')
                . '\s+(?:a|à|as|às)\s+(\d{1,2})(?:\s*(?:h|:)([0-5]\d))?/iu';
            preg_match_all($padrao, (string)$mensagem, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $hora = (int)$match[1];
                $minuto = isset($match[2]) && $match[2] !== '' ? (int)$match[2] : 0;
                if ($hora <= 23) {
                    $chave = sprintf('%02d:%02d', $hora, $minuto);
                    $horarios[$chave] = $minuto === 0 ? $hora . 'h' : sprintf('%d:%02d', $hora, $minuto);
                }
            }
            if (count($horarios) >= 2) {
                break;
            }
        }

        $opcoes = array_values($horarios);
        return WhatsAppAiTemplateService::perguntarHorarioDia($inicio->format('d'), $opcoes);
    }

    private function decisaoDestinatario(string $mensagem): ?string
    {
        $texto = $this->normalizarTexto($mensagem);
        if (preg_match('/\b(?:nao|outra pessoa|outra|outro|meu filho|minha filha|meu marido|minha esposa|minha mae|meu pai)\b/u', $texto)) {
            return 'terceiro';
        }
        if (preg_match('/^(?:sim|sou eu|e para mim|para mim|pra mim|eu mesmo|eu mesma|isso)(?:[.! ]*)$/u', $texto)) {
            return 'titular';
        }
        return null;
    }

    private function extrairNomeCompleto(string $mensagem, bool $removerContextoTerceiro = false): ?string
    {
        $nome = trim($mensagem);
        if ($removerContextoTerceiro) {
            $nome = preg_replace(
                '/^\s*(?:n[aã]o[, ]*)?(?:[ée]\s+)?(?:para\s+)?(?:outra pessoa[, ]*|meu filho[, ]*|minha filha[, ]*|meu marido[, ]*|minha esposa[, ]*|minha m[aã]e[, ]*|meu pai[, ]*)?(?:o nome (?:dele|dela) [ée]|o nome [ée]|[ée] para)?\s*/iu',
                '',
                $nome
            ) ?? $nome;
        } else {
            $nome = preg_replace('/^\s*(?:o nome (?:completo )?e|meu nome e|e para)\s+/iu', '', $nome) ?? $nome;
        }
        $nome = trim($nome, " \t\n\r\0\x0B.,!?;:");
        if (mb_strlen($nome, 'UTF-8') < 3 || mb_strlen($nome, 'UTF-8') > 255) {
            return null;
        }
        if (preg_match('/^(?:minha filha|meu filho|minha esposa|meu marido|minha m[aã]e|meu pai)$/iu', $nome)) {
            return null;
        }
        if (!preg_match('/^[\p{L}][\p{L}\p{M}\' -]+$/u', $nome)) {
            return null;
        }
        $partes = preg_split('/\s+/u', $nome, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($partes) < 2) {
            return null;
        }
        return mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
    }

    private function confirmarIdentidadeSolicitacao(int $id, ?int $pacienteId, string $nome): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE whatsapp_agendamento_solicitacoes
                SET paciente_id = :paciente, paciente_nome = :nome,
                    identidade_status = 'confirmada', identidade_confirmada_em = NOW(),
                    atualizado_em = NOW()
              WHERE id = :id AND status = 'aguardando_confirmacao'
                AND identidade_status IN ('aguardando_destinatario', 'aguardando_nome')"
        );
        $stmt->execute([':paciente' => $pacienteId, ':nome' => $nome, ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('SOLICITACAO_NAO_CONFIRMAVEL');
        }
    }

    private function aguardarNomeSolicitacao(int $id, bool $limparPaciente): void
    {
        $sql = "UPDATE whatsapp_agendamento_solicitacoes
                   SET identidade_status = 'aguardando_nome', atualizado_em = NOW()";
        if ($limparPaciente) {
            $sql .= ", paciente_id = NULL, paciente_nome = ''";
        }
        $sql .= " WHERE id = :id AND status = 'aguardando_confirmacao'
                   AND identidade_status = 'aguardando_destinatario'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id]);
    }

    private function respostaIdentidadeConfirmada(array $solicitacao, string $nome): array
    {
        $profissional = $this->buscarProfissional(
            (int)$solicitacao['empresa_id'],
            (int)$solicitacao['profissional_id']
        );
        return [
            'resposta_template' => WhatsAppAiTemplateService::confirmarOpcaoComPaciente(
                $nome,
                (string)$profissional['nome'],
                (string)$solicitacao['data_hora_inicio']
            ),
            'identidade_confirmada' => true,
        ];
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        return strtolower($ascii !== false ? $ascii : $texto);
    }

    private function validarEscolhaHorarioContextual(
        int $empresaId,
        int $conversaId,
        DateTimeImmutable $inicio
    ): void {
        $st = $this->pdo->prepare(
            "SELECT id, mensagem
               FROM whatsapp_mensagens
              WHERE empresa_id = :empresa
                AND conversa_id = :conversa
                AND direction = 'inbound'
           ORDER BY id DESC
              LIMIT 1"
        );
        $st->execute([':empresa' => $empresaId, ':conversa' => $conversaId]);
        $entrada = $st->fetch(PDO::FETCH_ASSOC);
        if (!$entrada) {
            throw new DomainException('HORARIO_NAO_ESCOLHIDO');
        }

        $mensagemOriginal = trim((string)$entrada['mensagem']);
        $ascii = $this->normalizarTexto($mensagemOriginal);
        $temHoraExplicita = preg_match(
            '/\b(?:[01]?\d|2[0-3])\s*(?:h(?:oras?)?|:[0-5]\d)\b|\b(?:as|pelas?)\s+(?:[01]?\d|2[0-3])\b/u',
            $ascii
        ) === 1;
        $temOrdemExplicita = preg_match(
            '/\b(?:primeir[oa]|segund[oa])(?:\s+(?:opcao|horario))?\b/u',
            $ascii
        ) === 1;
        $numeroPuroConfirmaHora = preg_match(
            '/^\s*(?:pode ser\s+|prefiro\s+|quero\s+)?(\d{1,2})\s*$/u',
            $ascii,
            $matchHora
        ) === 1 && (int)$matchHora[1] === (int)$inicio->format('G');
        if ($temHoraExplicita || $temOrdemExplicita || $numeroPuroConfirmaHora) {
            return;
        }

        $diaInformado = null;
        if (preg_match('/\b(?:pode ser\s+)?dia\s+(\d{1,2})\b/u', $ascii, $matchDia) === 1) {
            $diaInformado = (int)$matchDia[1];
            if ($diaInformado !== (int)$inicio->format('j')) {
                throw new DomainException('HORARIO_NAO_ESCOLHIDO');
            }
        }
        $selecaoContextual = in_array(
            $mensagemOriginal,
            ['👍', '👍🏻', '👍🏼', '👍🏽', '👍🏾', '👍🏿', '✅', '☑️'],
            true
        ) || preg_match(
            '/^\s*(?:sim(?:\s+por favor|\s+pode(?:\s+ser)?)?|pode(?:\s+ser(?:\s+entao)?)?|ok|certo|beleza|combinado|fechado|perfeito|confirmo|confirmado|isso|esse|essa|nesse horario|esse horario)[.!?]?\s*$/u',
            $ascii
        ) === 1;
        if ($diaInformado === null && !$selecaoContextual) {
            throw new DomainException('HORARIO_NAO_ESCOLHIDO');
        }

        $st = $this->pdo->prepare(
            "SELECT mensagem
               FROM whatsapp_mensagens
              WHERE empresa_id = :empresa
                AND conversa_id = :conversa
                AND direction = 'outbound'
                AND id < :entrada
           ORDER BY id DESC
              LIMIT 1"
        );
        $st->execute([
            ':empresa' => $empresaId,
            ':conversa' => $conversaId,
            ':entrada' => (int)$entrada['id'],
        ]);
        $oferta = (string)($st->fetchColumn() ?: '');
        $dataOferecida = $inicio->format('d/m/Y');
        $ocorrenciasData = substr_count($oferta, $dataOferecida);
        $hora = (int)$inicio->format('G');
        $minuto = $inicio->format('i');
        $padraoHora = '/(?<!\d)' . $hora . '\s*(?:h(?:oras?)?|:' . preg_quote($minuto, '/') . ')(?!\d)/iu';
        if ($ocorrenciasData !== 1 || preg_match($padraoHora, $oferta) !== 1) {
            throw new DomainException('HORARIO_NAO_ESCOLHIDO');
        }

        // Sem um dia explícito, "pode ser" só é seguro se a mensagem anterior
        // tiver uma única data completa. Com duas opções, exige nomear uma delas.
        if ($diaInformado === null) {
            preg_match_all('/\b\d{2}\/\d{2}\/\d{4}\b/u', $oferta, $datas);
            if (count($datas[0] ?? []) !== 1) {
                throw new DomainException('HORARIO_NAO_ESCOLHIDO');
            }
        }
    }

    private function duracaoProfissional(int $profissionalId): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COALESCE(intervalo_ia_minutos, intervalo_minutos) FROM configuracoes_agenda_usuario WHERE usuario_id = :id LIMIT 1'
            );
            $stmt->execute([':id' => $profissionalId]);
            $duracao = (int)($stmt->fetchColumn() ?: 30);
        } catch (PDOException $e) {
            if ((string)$e->getCode() !== '42S22') {
                throw $e;
            }
            $stmt = $this->pdo->prepare('SELECT intervalo_minutos FROM configuracoes_agenda_usuario WHERE usuario_id = :id LIMIT 1');
            $stmt->execute([':id' => $profissionalId]);
            $duracao = (int)($stmt->fetchColumn() ?: 30);
        }
        return ($duracao >= 5 && $duracao <= 480) ? $duracao : 30;
    }

    private function obterOuCriarRotulo(int $profissionalId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM agendamento_rotulos WHERE usuario_id = :usuario AND nome = :nome ORDER BY id LIMIT 1');
        $stmt->execute([':usuario' => $profissionalId, ':nome' => self::ROTULO_NOME]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $stmt = $this->pdo->prepare('INSERT INTO agendamento_rotulos (usuario_id, nome, cor) VALUES (:usuario, :nome, :cor)');
        $stmt->execute([':usuario' => $profissionalId, ':nome' => self::ROTULO_NOME, ':cor' => self::ROTULO_COR]);
        return (int)$this->pdo->lastInsertId();
    }

    private function buscarPorIdempotencia(string $chave): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM whatsapp_agendamento_solicitacoes WHERE idempotency_key = :chave LIMIT 1');
        $stmt->execute([':chave' => $chave]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        return $item ?: null;
    }

    private function agendamentoConfirmadoAindaExiste(array $solicitacao, int $empresaId): bool
    {
        $agendamentoId = (int)($solicitacao['agendamento_id'] ?? 0);
        if ($agendamentoId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM agendamentos
              WHERE id = :agendamento AND empresa_id = :empresa
                AND profissional_id = :profissional
                AND data_hora_inicio = :inicio
              LIMIT 1'
        );
        $stmt->execute([
            ':agendamento' => $agendamentoId,
            ':empresa' => $empresaId,
            ':profissional' => (int)$solicitacao['profissional_id'],
            ':inicio' => (string)$solicitacao['data_hora_inicio'],
        ]);
        return (bool)$stmt->fetchColumn();
    }

    private function invalidarConfirmacaoSemAgendamento(int $solicitacaoId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE whatsapp_agendamento_solicitacoes
                SET status = 'cancelado', agendamento_id = NULL,
                    confirmado_em = NULL, erro_codigo = 'AGENDAMENTO_ANTERIOR_REMOVIDO',
                    atualizado_em = NOW()
              WHERE id = :id AND status = 'confirmado'
                AND NOT EXISTS (
                    SELECT 1 FROM agendamentos a
                     WHERE a.id = whatsapp_agendamento_solicitacoes.agendamento_id
                       AND a.empresa_id = whatsapp_agendamento_solicitacoes.empresa_id
                )"
        );
        $stmt->execute([':id' => $solicitacaoId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('VAGA_JA_CONFIRMADA');
        }
    }

    private function buscarSolicitacao(string $uuid, int $empresaId, int $conversaId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM whatsapp_agendamento_solicitacoes
                 WHERE solicitacao_uuid = :uuid AND empresa_id = :empresa AND conversa_id = :conversa LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uuid' => $uuid, ':empresa' => $empresaId, ':conversa' => $conversaId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        return $item ?: null;
    }

    private function alterarStatus(int $id, string $status, string $erro): void
    {
        $stmt = $this->pdo->prepare('UPDATE whatsapp_agendamento_solicitacoes SET status = :status, erro_codigo = :erro, atualizado_em = NOW() WHERE id = :id');
        $stmt->execute([':status' => $status, ':erro' => $erro, ':id' => $id]);
    }

    private function reativarSolicitacao(
        array $existente,
        ?int $pacienteId,
        string $pacienteNome,
        string $telefone,
        DateTimeImmutable $fim,
        int $duracao,
        ?int $mensagemOrigemId,
        DateTimeImmutable $expiraEm,
        string $identidadeStatus
    ): void {
        $uuid = (string)$existente['solicitacao_uuid'];
        $stmt = $this->pdo->prepare(
            "UPDATE whatsapp_agendamento_solicitacoes
                SET paciente_id = :paciente,
                    paciente_nome = :nome,
                    telefone = :telefone,
                    data_hora_fim = :fim,
                    duracao_minutos = :duracao,
                    status = 'aguardando_confirmacao',
                    identidade_status = :identidade_status,
                    identidade_confirmada_em = NULL,
                    confirmacao_token_hash = :token_hash,
                    mensagem_origem_id = :mensagem,
                    agendamento_id = NULL,
                    expira_em = :expira,
                    confirmado_em = NULL,
                    erro_codigo = NULL,
                    atualizado_em = NOW()
              WHERE id = :id AND status != 'confirmado'"
        );
        $stmt->execute([
            ':paciente' => $pacienteId,
            ':nome' => $pacienteNome,
            ':telefone' => $telefone,
            ':fim' => $fim->format('Y-m-d H:i:s'),
            ':duracao' => $duracao,
            ':token_hash' => hash('sha256', $this->tokenConfirmacao($uuid)),
            ':mensagem' => $mensagemOrigemId,
            ':expira' => $expiraEm->format('Y-m-d H:i:s'),
            ':identidade_status' => $identidadeStatus,
            ':id' => (int)$existente['id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('SOLICITACAO_NAO_REATIVADA');
        }
    }

    private function obterLock(string $nome): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:nome, 5)');
        $stmt->execute([':nome' => substr($nome, 0, 64)]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function liberarLock(string $nome): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:nome)');
            $stmt->execute([':nome' => substr($nome, 0, 64)]);
        } catch (Throwable) {
        }
    }

    private function tokenConfirmacao(string $uuid): string
    {
        $segredo = (string)(getenv('PRONTAGENDA_AI_WHATSAPP_CONTEXT_SECRET') ?: '');
        if (strlen($segredo) < 32) {
            throw new RuntimeException('CONTEXTO_WHATSAPP_NAO_CONFIGURADO');
        }
        return hash_hmac('sha256', 'confirmar-agendamento|' . $uuid, $segredo);
    }

    private function respostaSolicitacao(array $item, string $profissionalNome): array
    {
        return [
            'solicitacao_id' => (int)$item['id'],
            'solicitacao_uuid' => (string)$item['solicitacao_uuid'],
            'confirmacao_token' => $this->tokenConfirmacao((string)$item['solicitacao_uuid']),
            'status' => (string)$item['status'],
            'expira_em' => (string)$item['expira_em'],
            'data_hora_inicio' => (string)$item['data_hora_inicio'],
            'data_hora_fim' => (string)$item['data_hora_fim'],
            'profissional_nome' => $profissionalNome,
            'paciente_nome' => (string)($item['paciente_nome'] ?? ''),
            'identidade_status' => (string)($item['identidade_status'] ?? self::IDENTIDADE_CONFIRMADA),
        ];
    }

    private function confirmacaoExistente(array $item, string $profissionalNome): array
    {
        return [
            'agendamento_id' => (int)$item['agendamento_id'],
            'ja_confirmado' => true,
            'data_hora_inicio' => (string)$item['data_hora_inicio'],
            'profissional_nome' => $profissionalNome,
        ];
    }

    private function dataHora(string $valor): DateTimeImmutable
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($valor));
        if (!$data || $data->format('Y-m-d H:i:s') !== trim($valor)) {
            throw new InvalidArgumentException('DATA_HORA_INVALIDA');
        }
        return $data;
    }

    private function uuidV4(): string
    {
        $dados = random_bytes(16);
        $dados[6] = chr((ord($dados[6]) & 0x0f) | 0x40);
        $dados[8] = chr((ord($dados[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($dados), 4));
    }
}
