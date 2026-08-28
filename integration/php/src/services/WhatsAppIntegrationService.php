<?php
declare(strict_types=1);

require_once __DIR__ . '/EvolutionApiService.php';
require_once __DIR__ . '/WhatsAppTemplateService.php';

class WhatsAppIntegrationService
{
    private const CONVERSA_AGUARDANDO_REMARCACAO = 'aguardando_decisao_remarcacao';
    private const CONVERSA_ATENDIMENTO_HUMANO = 'atendimento_humano';
    private const CONVERSA_CANCELAMENTO_ENCERRADO = 'cancelamento_encerrado';
    private const CONVERSA_CONFIRMACAO_ENCERRADA = 'confirmacao_encerrada';
    private const CONVERSA_AGUARDANDO_CONSENTIMENTO_NOVIDADES = 'aguardando_consentimento_novidades';

    private PDO $pdo;
    private EvolutionApiService $evolution;
    private WhatsAppTemplateService $templates;

    public function __construct(PDO $pdo, ?EvolutionApiService $evolution = null, ?WhatsAppTemplateService $templates = null)
    {
        $this->pdo = $pdo;
        $this->evolution = $evolution ?? new EvolutionApiService();
        $this->templates = $templates ?? new WhatsAppTemplateService();
        $this->garantirSchemaConfiguracoes();
        $this->garantirSchemaAgendamentos();
    }

    public function getEvolution(): EvolutionApiService
    {
        return $this->evolution;
    }

    public function getEvolutionByEmpresa(int $empresaId): EvolutionApiService
    {
        return $this->evolution;
    }

    public function reativarBotsCelularExpirados(?int $empresaId = null): int
    {
        $sql = "UPDATE whatsapp_conversas
                   SET modo_atendimento = 'bot',
                       estado_fluxo = 'inicio',
                       intencao_atual = NULL,
                       bot_pausado_em = NULL,
                       ultimo_status = 'bot_reativado_automaticamente',
                       lock_version = lock_version + 1,
                       atualizado_em = NOW()
                 WHERE modo_atendimento = 'celular'
                   AND bot_pausado_em IS NOT NULL
                   AND DATE_ADD(bot_pausado_em, INTERVAL 24 HOUR) <= NOW()";
        $params = [];
        if ($empresaId !== null && $empresaId > 0) {
            $sql .= ' AND empresa_id = :empresa_id';
            $params[':empresa_id'] = $empresaId;
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function carregarConfiguracaoEmpresa(int $empresaId): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM whatsapp_configuracoes WHERE empresa_id = ? LIMIT 1");
        $st->execute([$empresaId]);
        $config = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($config)) {
            unset($config['evolution_base_url'], $config['evolution_api_key']);
        }
        return $config ?: null;
    }

    public function salvarConfiguracaoEmpresa(int $empresaId, array $dados): array
    {
        $configAtual = $this->carregarConfiguracaoEmpresa($empresaId);

        $payload = [
            'instancia' => trim((string)($dados['instancia'] ?? ($configAtual['instancia'] ?? ''))),
            'instance_token' => trim((string)($configAtual['instance_token'] ?? '')),
            'ativo' => !empty($dados['ativo']) ? 1 : 0,
            'enviar_confirmacao' => !empty($dados['enviar_confirmacao']) ? 1 : 0,
            'enviar_lembrete' => !empty($dados['enviar_lembrete']) ? 1 : 0,
            'antecedencia_confirmacao_horas' => max(1, (int)($dados['antecedencia_confirmacao_horas'] ?? ($configAtual['antecedencia_confirmacao_horas'] ?? 24))),
            'antecedencia_lembrete_horas' => max(1, (int)($dados['antecedencia_lembrete_horas'] ?? ($configAtual['antecedencia_lembrete_horas'] ?? 4))),
            'antecedencia_minima_mesmo_dia_horas' => max(1, (int)($dados['antecedencia_minima_mesmo_dia_horas'] ?? ($configAtual['antecedencia_minima_mesmo_dia_horas'] ?? 1))),
            'mensagem_confirmacao' => trim((string)($dados['mensagem_confirmacao'] ?? '')),
            'mensagem_lembrete' => trim((string)($dados['mensagem_lembrete'] ?? '')),
        ];

        if ($payload['instance_token'] === '') {
            $payload['instance_token'] = bin2hex(random_bytes(16));
        }

        if ($payload['mensagem_confirmacao'] === '') {
            $payload['mensagem_confirmacao'] = WhatsAppTemplateService::DEFAULT_CONFIRMACAO;
        }

        if ($payload['mensagem_lembrete'] === '') {
            $payload['mensagem_lembrete'] = WhatsAppTemplateService::DEFAULT_LEMBRETE;
        }

        if ($configAtual) {
            $sql = "UPDATE whatsapp_configuracoes
                       SET instancia = :instancia,
                           instance_token = :instance_token,
                           ativo = :ativo,
                           enviar_confirmacao = :enviar_confirmacao,
                           enviar_lembrete = :enviar_lembrete,
                           antecedencia_confirmacao_horas = :antecedencia_confirmacao_horas,
                           antecedencia_lembrete_horas = :antecedencia_lembrete_horas,
                           antecedencia_minima_mesmo_dia_horas = :antecedencia_minima_mesmo_dia_horas,
                           mensagem_confirmacao = :mensagem_confirmacao,
                           mensagem_lembrete = :mensagem_lembrete,
                           atualizado_em = NOW()
                     WHERE empresa_id = :empresa_id";
        } else {
            $sql = "INSERT INTO whatsapp_configuracoes
                        (empresa_id, instancia, instance_token, ativo, enviar_confirmacao, enviar_lembrete, antecedencia_confirmacao_horas, antecedencia_lembrete_horas, antecedencia_minima_mesmo_dia_horas, mensagem_confirmacao, mensagem_lembrete, status_conexao, criado_em, atualizado_em)
                    VALUES
                        (:empresa_id, :instancia, :instance_token, :ativo, :enviar_confirmacao, :enviar_lembrete, :antecedencia_confirmacao_horas, :antecedencia_lembrete_horas, :antecedencia_minima_mesmo_dia_horas, :mensagem_confirmacao, :mensagem_lembrete, 'desconectado', NOW(), NOW())";
        }

        $payload['empresa_id'] = $empresaId;
        $st = $this->pdo->prepare($sql);
        $st->execute($payload);

        return $this->carregarConfiguracaoEmpresa($empresaId) ?? [];
    }

    public function gerarNomeInstancia(int $empresaId, string $empresaNome): string
    {
        $base = $this->normalizarSlug($empresaNome);
        if ($base === '') {
            $base = 'empresa-' . $empresaId;
        }
        return substr($base . '-' . $empresaId, 0, 60);
    }

    public function enfileirarMensagensAgendamento(int $agendamentoId, bool $somenteRecalcularPendentes = false): array
    {
        $agendamento = $this->buscarAgendamento($agendamentoId);
        if (!$agendamento) {
            return [];
        }

        if ((int)($agendamento['enviar_whatsapp'] ?? 1) !== 1) {
            $this->removerMensagensPendentesAgendamento($agendamentoId);
            return [];
        }

        if (!empty($agendamento['paciente_id'])) {
            $pref = $this->pdo->prepare('SELECT aceita_atendimento, aceita_agendamento, canal_whatsapp, bloqueado
                FROM comunicacao_preferencias
                WHERE empresa_id=:empresa_id AND paciente_id=:paciente_id LIMIT 1');
            $pref->execute([
                ':empresa_id'=>(int)$agendamento['empresa_id'],
                ':paciente_id'=>(int)$agendamento['paciente_id'],
            ]);
            $preferencia = $pref->fetch(PDO::FETCH_ASSOC);
            if ($preferencia && (
                (int)$preferencia['aceita_atendimento'] !== 1
                || (int)$preferencia['aceita_agendamento'] !== 1
                || (int)$preferencia['canal_whatsapp'] !== 1
                || (int)$preferencia['bloqueado'] === 1
            )) {
                $this->removerMensagensPendentesAgendamento($agendamentoId);
                return [];
            }
        }

        $config = $this->carregarConfiguracaoEmpresa((int)$agendamento['empresa_id']);
        if (!$config || (int)($config['ativo'] ?? 0) !== 1) {
            return [];
        }

        $telefone = $this->normalizarTelefone((string)($agendamento['telefone'] ?? ''));
        if ($telefone === '') {
            return [];
        }

        $ids = [];

        if ((int)($config['enviar_confirmacao'] ?? 0) === 1) {
            if ($somenteRecalcularPendentes && $this->tipoAgendamentoJaEnviado($agendamentoId, 'confirmacao')) {
                $this->removerMensagensAgendadasInvalidas($agendamentoId, 'confirmacao');
            } else {
                $mensagemConfirmacao = $this->montarMensagemAgendamento($agendamento, $config, 'confirmacao');
                if ($mensagemConfirmacao !== '') {
                    $agendadoParaConfirmacao = $this->calcularAgendamentoEnvio(
                        (string)$agendamento['data_hora_inicio'],
                        (int)($config['antecedencia_confirmacao_horas'] ?? 24)
                    );
                    if ($this->deveAgendarConfirmacao((string)$agendamento['data_hora_inicio'])) {
                        $idConfirmacao = $this->salvarOuAtualizarMensagemFila(
                            $agendamento,
                            'confirmacao',
                            $telefone,
                            $mensagemConfirmacao,
                            $agendadoParaConfirmacao
                        );
                        if ($idConfirmacao !== null) {
                            $ids['confirmacao'] = $idConfirmacao;
                        }
                    } else {
                        $this->removerMensagensAgendadasInvalidas((int)$agendamento['id'], 'confirmacao');
                    }
                }
            }
        } else {
            $this->removerMensagensAgendadasInvalidas($agendamentoId, 'confirmacao');
        }

        if ((int)($config['enviar_lembrete'] ?? 0) === 1) {
            if ($somenteRecalcularPendentes && $this->tipoAgendamentoJaEnviado($agendamentoId, 'lembrete')) {
                $this->removerMensagensAgendadasInvalidas($agendamentoId, 'lembrete');
            } else {
                $mensagemLembrete = $this->montarMensagemAgendamento($agendamento, $config, 'lembrete');
                if ($mensagemLembrete !== '') {
                    $agendadoParaLembrete = $this->calcularAgendamentoLembrete($agendamento, $config);
                    if ($agendadoParaLembrete !== null && $this->deveAgendarMensagem($agendadoParaLembrete)) {
                        $idLembrete = $this->salvarOuAtualizarMensagemFila(
                            $agendamento,
                            'lembrete',
                            $telefone,
                            $mensagemLembrete,
                            $agendadoParaLembrete
                        );
                        if ($idLembrete !== null) {
                            $ids['lembrete'] = $idLembrete;
                        }
                    } else {
                        $this->removerMensagensAgendadasInvalidas((int)$agendamento['id'], 'lembrete');
                    }
                }
            }
        } else {
            $this->removerMensagensAgendadasInvalidas($agendamentoId, 'lembrete');
        }

        return $ids;
    }

    public function enfileirarAgendamentosAPartirDe(string $dataInicio, ?int $empresaId = null): array
    {
        $inicio = $this->normalizarDataInicioBackfill($dataInicio);

        $sql = "SELECT a.id
                  FROM agendamentos a
                 WHERE a.data_hora_inicio >= :inicio";

        $params = [':inicio' => $inicio];

        if ($empresaId !== null && $empresaId > 0) {
            $sql .= " AND a.empresa_id = :empresa_id";
            $params[':empresa_id'] = $empresaId;
        }

        $sql .= " ORDER BY a.data_hora_inicio ASC, a.id ASC";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $idsAgendamento = $st->fetchAll(PDO::FETCH_COLUMN);

        $resultado = [
            'inicio' => $inicio,
            'empresa_id' => $empresaId,
            'agendamentos_lidos' => count($idsAgendamento),
            'agendamentos_com_fila' => 0,
            'mensagens_criadas' => 0,
            'por_tipo' => [
                'confirmacao' => 0,
                'lembrete' => 0,
            ],
        ];

        foreach ($idsAgendamento as $agendamentoId) {
            $mensagens = $this->enfileirarMensagensAgendamento((int)$agendamentoId, true);
            if ($mensagens !== []) {
                $resultado['agendamentos_com_fila']++;
                $resultado['mensagens_criadas'] += count($mensagens);

                foreach (array_keys($mensagens) as $tipo) {
                    if (isset($resultado['por_tipo'][$tipo])) {
                        $resultado['por_tipo'][$tipo]++;
                    }
                }
            }
        }

        return $resultado;
    }

    public function removerMensagensPendentesAgendamento(int $agendamentoId): int
    {
        $st = $this->pdo->prepare("UPDATE whatsapp_jobs
                                      SET status = 'cancelado',
                                          cancelado_em = NOW(),
                                          locked_at = NULL,
                                          worker_id = NULL,
                                          atualizado_em = NOW()
                                    WHERE agendamento_id = :agendamento_id
                                      AND status IN ('pendente', 'erro', 'processando')");
        $st->execute([
            ':agendamento_id' => $agendamentoId,
        ]);

        return $st->rowCount();
    }

    public function processarFila(int $limite = 20): array
    {
        $workerId = sprintf(
            '%s-%s-%s',
            php_sapi_name(),
            gethostname() ?: 'worker',
            bin2hex(random_bytes(4))
        );

        $sql = "SELECT wj.*,
                       cfg.instancia,
                       cfg.instance_token,
                       cfg.ativo
                  FROM whatsapp_jobs wj
                  JOIN whatsapp_configuracoes cfg ON cfg.empresa_id = wj.empresa_id
                 WHERE cfg.ativo = 1
                   AND COALESCE(cfg.instancia, '') <> ''
                   AND wj.status IN ('pendente', 'erro')
                   AND COALESCE(wj.tentativas, 0) < 5
                   AND wj.agendado_para <= NOW()
              ORDER BY wj.agendado_para ASC, wj.id ASC
                 LIMIT " . max(1, (int)$limite);
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $resultado = ['processadas' => 0, 'enviadas' => 0, 'erros' => 0];

        foreach ($rows as $row) {
            $stProcessando = $this->pdo->prepare("UPDATE whatsapp_jobs
                                                     SET status = 'processando',
                                                         locked_at = NOW(),
                                                         worker_id = :worker_id,
                                                         atualizado_em = NOW()
                                                   WHERE id = :id
                                                     AND status IN ('pendente', 'erro')");
            $stProcessando->execute([
                ':id' => (int)$row['id'],
                ':worker_id' => $workerId,
            ]);
            if ($stProcessando->rowCount() < 1) {
                continue;
            }

            $tipoJob = strtolower(trim((string)($row['tipo'] ?? '')));
            if (in_array($tipoJob, ['confirmacao', 'lembrete'], true)
                && !$this->jobPodeSerEnviado((int)$row['id'], (int)($row['agendamento_id'] ?? 0))) {
                $this->cancelarJob((int)$row['id']);
                continue;
            }

            $resultado['processadas']++;

            try {
                $payload = $this->decodificarPayloadJob((string)($row['payload_json'] ?? ''));
                $mensagem = trim((string)($payload['mensagem'] ?? ''));
                if ($mensagem === '') {
                    throw new RuntimeException('Payload do job sem mensagem.');
                }

                $evolution = $this->evolution;
                $resp = $evolution->sendText((string)$row['instancia'], (string)$row['telefone'], $mensagem);
                $messageId = $this->extrairMessageId($resp['data']);

                $stHistorico = $this->pdo->prepare("INSERT INTO whatsapp_mensagens
                    (empresa_id, profissional_id, paciente_id, agendamento_id, conversa_id, direction, tipo, telefone, mensagem, evolution_message_id, status, processado, tentativas, agendado_para, enviado_em, criado_em, atualizado_em)
                    VALUES
                    (:empresa_id, :profissional_id, :paciente_id, :agendamento_id, :conversa_id, 'outbound', :tipo, :telefone, :mensagem, :evolution_message_id, 'enviada', 1, 1, :agendado_para, NOW(), NOW(), NOW())");
                $stHistorico->execute([
                    ':empresa_id' => (int)$row['empresa_id'],
                    ':profissional_id' => !empty($row['profissional_id']) ? (int)$row['profissional_id'] : null,
                    ':paciente_id' => !empty($row['paciente_id']) ? (int)$row['paciente_id'] : null,
                    ':agendamento_id' => !empty($row['agendamento_id']) ? (int)$row['agendamento_id'] : null,
                    ':conversa_id' => !empty($row['conversa_id']) ? (int)$row['conversa_id'] : null,
                    ':tipo' => (string)$row['tipo'],
                    ':telefone' => (string)$row['telefone'],
                    ':mensagem' => $mensagem,
                    ':evolution_message_id' => $messageId,
                    ':agendado_para' => (string)$row['agendado_para'],
                ]);
                $mensagemId = (int)$this->pdo->lastInsertId();

                $conversaId = $this->sincronizarConversaSaida($mensagemId, [
                    'empresa_id' => (int)$row['empresa_id'],
                    'telefone' => (string)$row['telefone'],
                    'tipo' => (string)$row['tipo'],
                    'paciente_id' => !empty($row['paciente_id']) ? (int)$row['paciente_id'] : null,
                    'profissional_id' => !empty($row['profissional_id']) ? (int)$row['profissional_id'] : null,
                    'agendamento_id' => !empty($row['agendamento_id']) ? (int)$row['agendamento_id'] : null,
                    'data_hora_inicio' => $payload['data_hora_inicio'] ?? null,
                    'data_hora_fim' => $payload['data_hora_fim'] ?? null,
                ], $messageId);

                $st = $this->pdo->prepare("UPDATE whatsapp_jobs
                                              SET conversa_id = COALESCE(:conversa_id, conversa_id),
                                                  status = 'enviado',
                                                  enviado_em = NOW(),
                                                  tentativas = COALESCE(tentativas, 0) + 1,
                                                  ultimo_erro = NULL,
                                                  locked_at = NULL,
                                                  worker_id = NULL,
                                                  atualizado_em = NOW()
                                            WHERE id = :id");
                $st->execute([
                    ':conversa_id' => $conversaId,
                    ':id' => (int)$row['id'],
                ]);

                $resultado['enviadas']++;
            } catch (Throwable $e) {
                $st = $this->pdo->prepare("UPDATE whatsapp_jobs
                                              SET status = 'erro',
                                                  tentativas = COALESCE(tentativas, 0) + 1,
                                                  ultimo_erro = :erro,
                                                  locked_at = NULL,
                                                  worker_id = NULL,
                                                  atualizado_em = NOW()
                                            WHERE id = :id");
                $st->execute([
                    ':erro' => mb_substr($e->getMessage(), 0, 1000, 'UTF-8'),
                    ':id' => (int)$row['id'],
                ]);
                $resultado['erros']++;
            }
        }

        return $resultado;
    }

    private function jobPodeSerEnviado(int $jobId, int $agendamentoId): bool
    {
        if ($jobId <= 0 || $agendamentoId <= 0) {
            return false;
        }

        $st = $this->pdo->prepare("SELECT a.status
                                    FROM whatsapp_jobs wj
                                    JOIN agendamentos a
                                      ON a.id = wj.agendamento_id
                                     AND a.empresa_id = wj.empresa_id
                                   WHERE wj.id = :job_id
                                     AND wj.status = 'processando'
                                     AND a.id = :agendamento_id
                                   LIMIT 1");
        $st->execute([':job_id' => $jobId, ':agendamento_id' => $agendamentoId]);
        $status = strtolower(trim((string)($st->fetchColumn() ?: '')));

        return $status !== '' && !in_array($status, [
            'desmarcado pelo profissional',
            'desmarcado pelo paciente',
            'cancelado',
            'remarcar',
            'remarcado',
            'falta',
            'atendido',
        ], true);
    }

    private function cancelarJob(int $jobId): void
    {
        $st = $this->pdo->prepare("UPDATE whatsapp_jobs
                                      SET status = 'cancelado',
                                          cancelado_em = NOW(),
                                          locked_at = NULL,
                                          worker_id = NULL,
                                          atualizado_em = NOW()
                                    WHERE id = :id
                                      AND status = 'processando'");
        $st->execute([':id' => $jobId]);
    }

    public function enviarMensagemManual(int $empresaId, string $telefone, string $mensagem, bool $sincronizarConversa = true): array
    {
        $config = $this->carregarConfiguracaoEmpresa($empresaId);
        if (!$config || empty($config['instancia'])) {
            throw new RuntimeException('Nenhuma instância configurada.');
        }

        $telefoneNormalizado = $this->normalizarTelefone($telefone);
        $mensagem = trim($mensagem);

        if ($telefoneNormalizado === '' || $mensagem === '') {
            throw new InvalidArgumentException('Informe telefone e mensagem para o envio manual.');
        }

        $evolution = $this->evolution;
        $resp = $evolution->sendText((string)$config['instancia'], $telefoneNormalizado, $mensagem);
        $messageId = $this->extrairMessageId($resp['data']);

        $st = $this->pdo->prepare("INSERT INTO whatsapp_mensagens
            (empresa_id, tipo, telefone, mensagem, evolution_message_id, status, processado, tentativas, agendado_para, enviado_em, criado_em, atualizado_em)
            VALUES
            (:empresa_id, 'manual', :telefone, :mensagem, :evolution_message_id, 'enviada', 1, 1, NOW(), NOW(), NOW(), NOW())");
        $st->execute([
            ':empresa_id' => $empresaId,
            ':telefone' => $telefoneNormalizado,
            ':mensagem' => $mensagem,
            ':evolution_message_id' => $messageId !== '' ? $messageId : null,
        ]);
        $registroId = (int)$this->pdo->lastInsertId();

        if ($sincronizarConversa) {
            $this->sincronizarConversaSaida($registroId, [
                'empresa_id' => $empresaId,
                'telefone' => $telefoneNormalizado,
                'tipo' => 'manual',
                'paciente_id' => null,
                'profissional_id' => null,
                'agendamento_id' => null,
                'data_hora_inicio' => null,
                'data_hora_fim' => null,
            ], $messageId);
        }

        return [
            'message_id' => $messageId,
            'registro_id' => $registroId,
            'response' => $resp['data'],
        ];
    }

    public function enviarMidiaCampanha(
        int $empresaId,
        string $telefone,
        string $mensagem,
        string $mediaType,
        string $mimeType,
        string $mediaBase64,
        string $fileName
    ): array {
        $config = $this->carregarConfiguracaoEmpresa($empresaId);
        if (!$config || empty($config['instancia'])) {
            throw new RuntimeException('Nenhuma instância configurada.');
        }
        $telefone = $this->normalizarTelefone($telefone);
        if ($telefone === '' || $mediaBase64 === '') {
            throw new InvalidArgumentException('Telefone ou anexo inválido.');
        }
        $resp = $this->evolution->sendMedia(
            (string)$config['instancia'], $telefone, $mediaType, $mimeType,
            trim($mensagem), $mediaBase64, $fileName
        );
        $messageId = $this->extrairMessageId($resp['data']);
        $st = $this->pdo->prepare("INSERT INTO whatsapp_mensagens
            (empresa_id, tipo, telefone, mensagem, evolution_message_id, status, processado, tentativas, agendado_para, enviado_em, payload_json, criado_em, atualizado_em)
            VALUES (:empresa_id, 'manual', :telefone, :mensagem, :message_id, 'enviada', 1, 1, NOW(), NOW(), :payload, NOW(), NOW())");
        $st->execute([
            ':empresa_id' => $empresaId,
            ':telefone' => $telefone,
            ':mensagem' => trim($mensagem),
            ':message_id' => $messageId !== '' ? $messageId : null,
            ':payload' => json_encode(['origem' => 'campanha', 'anexo_nome' => $fileName, 'anexo_mime' => $mimeType], JSON_UNESCAPED_UNICODE),
        ]);
        return ['message_id' => $messageId, 'registro_id' => (int)$this->pdo->lastInsertId(), 'response' => $resp['data']];
    }

    public function enviarMidiaManual(
        int $empresaId,
        string $telefone,
        string $mensagem,
        string $mediaType,
        string $mimeType,
        string $mediaBase64,
        string $fileName,
        ?int $pacienteId = null,
        ?int $profissionalId = null
    ): array {
        $resultado = $this->enviarMidiaCampanha(
            $empresaId, $telefone, $mensagem, $mediaType, $mimeType, $mediaBase64, $fileName
        );
        $registroId = (int)($resultado['registro_id'] ?? 0);
        if ($registroId > 0) {
            $payload = json_encode([
                'origem' => 'prontuario',
                'anexo_nome' => $fileName,
                'anexo_mime' => $mimeType,
                'anexo_tipo' => $mediaType,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->pdo->prepare("UPDATE whatsapp_mensagens
                                   SET paciente_id = :paciente_id,
                                       profissional_id = :profissional_id,
                                       payload_json = :payload,
                                       atualizado_em = NOW()
                                 WHERE id = :id AND empresa_id = :empresa_id")
                ->execute([
                    ':paciente_id' => $pacienteId,
                    ':profissional_id' => $profissionalId,
                    ':payload' => $payload,
                    ':id' => $registroId,
                    ':empresa_id' => $empresaId,
                ]);
            $this->sincronizarConversaSaida($registroId, [
                'empresa_id' => $empresaId,
                'telefone' => $telefone,
                'tipo' => 'manual',
                'paciente_id' => $pacienteId,
                'profissional_id' => $profissionalId,
                'agendamento_id' => null,
                'data_hora_inicio' => null,
                'data_hora_fim' => null,
            ], (string)($resultado['message_id'] ?? ''));
        }
        return $resultado;
    }

    public function processarWebhook(array $payload, array $headers = []): array
    {
        $evento = $this->extrairEvento($payload);
        $instancia = $this->extrairInstancia($payload);
        $empresaId = $instancia !== '' ? $this->buscarEmpresaPorInstancia($instancia) : null;
        $telefoneEvento = $this->normalizarTelefone($this->extrairTelefoneMensagem($payload));
        $messageIdEvento = $this->extrairMessageId($payload);
        $eventoId = $this->registrarEventoWebhook($empresaId, $instancia, $evento, $telefoneEvento, $messageIdEvento, $payload);

        $resultado = [
            'evento' => $evento,
            'instancia' => $instancia,
            'empresa_id' => $empresaId,
            'acao' => 'ignorada',
        ];

        try {
            if ($empresaId && (str_contains($evento, 'contacts.update') || str_contains($evento, 'contacts.upsert'))) {
                $total = $this->registrarContatosPublicos($empresaId, $payload);
                $resultado['acao'] = 'contatos_atualizados';
                $resultado['total'] = $total;
            }

            if ($instancia !== '' && $empresaId) {
                $statusConexao = $this->extrairStatusConexao($payload);
                if ($statusConexao !== '') {
                    $this->atualizarConexaoEmpresa($empresaId, [
                        'status_conexao' => $statusConexao,
                        'numero_whatsapp' => $this->extrairNumeroWhatsapp($payload),
                        'nome_whatsapp' => $this->extrairNomeWhatsapp($payload),
                        'ultima_conexao' => in_array($statusConexao, ['open', 'connected', 'conectado'], true) ? date('Y-m-d H:i:s') : null,
                    ]);
                    $resultado['acao'] = 'status_atualizado';
                }
            }

            if ($empresaId && $this->ehEventoStatusMensagem($evento, $payload)) {
                $messageId = $messageIdEvento;
                $status = $this->extrairStatusMensagem($payload);
                if ($messageId !== '' && $status !== '') {
                    $this->atualizarStatusMensagemPorWebhook($messageId, $status);
                    $resultado['acao'] = 'mensagem_atualizada';
                    $resultado['message_id'] = $messageId;
                    $resultado['status'] = $status;
                }
            }

            if ($empresaId && $this->ehMensagemRecebidaDoPaciente($evento, $payload)) {
                $texto = $this->extrairTextoMensagem($payload);
                $telefone = $telefoneEvento;
                if ($texto !== '' && $telefone !== '') {
                    $conversaAtual = $this->buscarConversaAtivaPorTelefone($empresaId, $telefone);
                    $estadoConversa = (string)($conversaAtual['observacoes'] ?? '');
                    $ultimoTipoSaida = (string)($conversaAtual['ultimo_tipo_saida'] ?? '');
                    $aguardandoDecisaoRemarcacao = $estadoConversa === self::CONVERSA_AGUARDANDO_REMARCACAO
                        || $this->ultimaMensagemSolicitaNovoHorario($empresaId, $telefone);
                    $decisaoRemarcacao = '';
                    $preservarStatusAgendamento = false;
                    $statusAgendamentoForcado = null;
                    $suprimirRespostaAutomatica = false;
                    $textoRetornoContextual = '';
                    $proximoEstadoConversa = null;
                    $cancelamentoInicial = false;
                    $descadastroNovidades = $this->processarDescadastroNovidades($empresaId, $telefone, $texto);

                    if ($descadastroNovidades) {
                        $interpretacao = 'descadastro_novidades';
                        $preservarStatusAgendamento = true;
                        $this->atualizarEstadoConversa($empresaId, $telefone, self::CONVERSA_CONFIRMACAO_ENCERRADA);
                        $textoRetornoContextual = 'Tudo certo. Você não receberá mais novidades e conteúdos. Mensagens necessárias ao atendimento continuam ativas.';
                    } elseif ($estadoConversa === self::CONVERSA_AGUARDANDO_CONSENTIMENTO_NOVIDADES) {
                        $decisaoConsentimento = $this->interpretarConsentimentoNovidades($texto);
                        $interpretacao = $decisaoConsentimento === '' ? 'consentimento_nao_entendido' : 'consentimento_' . $decisaoConsentimento;
                        $preservarStatusAgendamento = true;
                        if ($decisaoConsentimento !== '') {
                            $this->salvarConsentimentoNovidadesPorTelefone($empresaId, $telefone, $decisaoConsentimento === 'sim');
                            $this->atualizarEstadoConversa($empresaId, $telefone, self::CONVERSA_CONFIRMACAO_ENCERRADA);
                            $textoRetornoContextual = $decisaoConsentimento === 'sim'
                                ? 'Obrigado! Sua autorização para receber novidades e conteúdos foi registrada.'
                                : 'Tudo certo. Você não receberá novidades e conteúdos. Mensagens necessárias ao atendimento continuam ativas.';
                            $proximoEstadoConversa = self::CONVERSA_CONFIRMACAO_ENCERRADA;
                        } else {
                            $textoRetornoContextual = 'Para escolher, responda somente SIM ou NÃO.';
                        }
                    } elseif ($aguardandoDecisaoRemarcacao) {
                        $decisaoRemarcacao = $this->interpretarDecisaoRemarcacao($texto);
                        $preservarStatusAgendamento = true;

                        if ($decisaoRemarcacao === 'sim') {
                            $interpretacao = 'remarcar';
                            $statusAgendamentoForcado = 'remarcar';
                            $textoRetornoContextual = 'Vou passar nossa conversa para nossa equipe e já vejo novo horário para você. Para já ir adiantando, tem alguma preferência?';
                            $proximoEstadoConversa = self::CONVERSA_ATENDIMENTO_HUMANO;
                        } elseif ($decisaoRemarcacao === 'nao') {
                            $interpretacao = 'cancelar';
                            $statusAgendamentoForcado = 'desmarcado pelo paciente';
                            $textoRetornoContextual = 'Tudo bem. Qualquer coisa estamos aqui para ajudar. Só chamar.';
                            $proximoEstadoConversa = self::CONVERSA_CANCELAMENTO_ENCERRADO;
                        } else {
                            $interpretacao = 'nao_entendido';
                            $suprimirRespostaAutomatica = true;
                        }
                    } elseif ($estadoConversa === self::CONVERSA_CONFIRMACAO_ENCERRADA
                        && $this->ehMensagemCortesia($texto)) {
                        $interpretacao = 'cortesia_pos_confirmacao';
                        $preservarStatusAgendamento = true;
                        $suprimirRespostaAutomatica = true;
                    } elseif (in_array($estadoConversa, [
                        self::CONVERSA_ATENDIMENTO_HUMANO,
                        self::CONVERSA_CANCELAMENTO_ENCERRADO,
                    ], true) && !in_array($ultimoTipoSaida, ['confirmacao', 'lembrete'], true)) {
                        $interpretacao = $this->interpretarResposta($texto);
                        $preservarStatusAgendamento = true;
                        $suprimirRespostaAutomatica = true;
                    } else {
                        $interpretacao = $this->interpretarResposta($texto);
                        // A primeira negativa abre a pergunta sobre um novo horario.
                        // O status definitivo sera decidido pela proxima resposta.
                        if ($interpretacao === 'cancelar') {
                            $preservarStatusAgendamento = true;
                            $cancelamentoInicial = true;
                        }
                    }

                    $registroResposta = $this->registrarRespostaPaciente(
                        $empresaId,
                        $telefone,
                        $texto,
                        $interpretacao,
                        $messageIdEvento,
                        $preservarStatusAgendamento,
                        $statusAgendamentoForcado
                    );
                    $mensagemId = (int)($registroResposta['id'] ?? 0);
                    if ($cancelamentoInicial && !empty($registroResposta['agendamento_id'])) {
                        $this->removerMensagensPendentesAgendamento(
                            (int)$registroResposta['agendamento_id']
                        );
                    }
                    if ($cancelamentoInicial) {
                        // Persiste o passo antes do envio da resposta. Assim, uma
                        // falha momentanea na Evolution nao perde o contexto.
                        $this->atualizarEstadoConversa(
                            $empresaId,
                            $telefone,
                            self::CONVERSA_AGUARDANDO_REMARCACAO
                        );
                    }
                    $resultado['acao'] = 'resposta_registrada';
                    $resultado['interpretacao'] = $interpretacao;
                    $resultado['mensagem_id'] = $mensagemId;
                    if ($estadoConversa !== '') {
                        $resultado['estado_conversa'] = $estadoConversa;
                    }
                    if ($decisaoRemarcacao !== '') {
                        $resultado['decisao_remarcacao'] = $decisaoRemarcacao;
                    }

                    if (!empty($registroResposta) && empty($registroResposta['duplicada'])) {
                        $textoRetorno = $textoRetornoContextual !== ''
                            ? $textoRetornoContextual
                            : $this->montarRetornoInterpretacao($interpretacao);

                        if (!$suprimirRespostaAutomatica && $textoRetorno !== '' && $instancia !== '') {
                            try {
                                $idRetorno = $this->enviarRespostaAutomatica(
                                    $empresaId,
                                    $instancia,
                                    $telefone,
                                    $textoRetorno,
                                    $registroResposta
                                );
                                if ($idRetorno !== null) {
                                    $resultado['mensagem_retorno_id'] = $idRetorno;

                                    if ($proximoEstadoConversa !== null) {
                                        $this->atualizarEstadoConversa(
                                            $empresaId,
                                            $telefone,
                                            $proximoEstadoConversa,
                                            $interpretacao === 'remarcar'
                                        );
                                        $resultado['proximo_estado_conversa'] = $proximoEstadoConversa;
                                    } elseif ($interpretacao === 'confirmar') {
                                        $this->atualizarEstadoConversa(
                                            $empresaId,
                                            $telefone,
                                            self::CONVERSA_CONFIRMACAO_ENCERRADA,
                                            true
                                        );
                                        $resultado['proximo_estado_conversa'] = self::CONVERSA_CONFIRMACAO_ENCERRADA;
                                    } elseif ($interpretacao === 'remarcar') {
                                        $this->atualizarEstadoConversa(
                                            $empresaId,
                                            $telefone,
                                            self::CONVERSA_ATENDIMENTO_HUMANO,
                                            true
                                        );
                                        $resultado['proximo_estado_conversa'] = self::CONVERSA_ATENDIMENTO_HUMANO;
                                    } elseif ($cancelamentoInicial) {
                                        $resultado['proximo_estado_conversa'] = self::CONVERSA_AGUARDANDO_REMARCACAO;
                                    }
                                }
                            } catch (Throwable $e) {
                                $resultado['erro_retorno'] = $e->getMessage();
                            }
                        }
                    }
                }
            }

            $this->finalizarEventoWebhook($eventoId, true);
            $this->registrarLogWebhook([
                'headers' => $headers,
                'payload' => $payload,
                'resultado' => $resultado,
            ]);

            return $resultado;
        } catch (Throwable $e) {
            $resultado['acao'] = 'erro';
            $resultado['erro'] = $e->getMessage();
            $this->finalizarEventoWebhook($eventoId, false, $e->getMessage());
            $this->registrarLogWebhook([
                'headers' => $headers,
                'payload' => $payload,
                'resultado' => $resultado,
            ]);
            throw $e;
        }
    }

    public function enviarBoasVindasNovoPaciente(int $empresaId, int $pacienteId, string $telefone): array
    {
        $st = $this->pdo->prepare('SELECT nome FROM empresas WHERE id=:id LIMIT 1');
        $st->execute([':id'=>$empresaId]);
        $empresaNome = trim((string)($st->fetchColumn() ?: 'nossa clínica'));
        $mensagem = "Olá! Seja bem-vindo(a) à {$empresaNome}.\n\nPodemos enviar por aqui novidades e conteúdos da clínica? Responda SIM para autorizar ou NÃO para recusar. Você poderá mudar essa escolha a qualquer momento.";
        $resultado = $this->enviarMensagemManual($empresaId, $telefone, $mensagem, true);
        $this->pdo->prepare('UPDATE whatsapp_mensagens SET paciente_id=:paciente_id, origem_envio=\'boas_vindas\', atualizado_em=NOW() WHERE id=:id AND empresa_id=:empresa_id')
            ->execute([':paciente_id'=>$pacienteId, ':id'=>$resultado['registro_id'], ':empresa_id'=>$empresaId]);
        $this->pdo->prepare('UPDATE whatsapp_conversas SET paciente_id=:paciente_id WHERE empresa_id=:empresa_id AND telefone=:telefone')
            ->execute([':paciente_id'=>$pacienteId, ':empresa_id'=>$empresaId, ':telefone'=>$this->normalizarTelefone($telefone)]);
        $this->atualizarEstadoConversa($empresaId, $telefone, self::CONVERSA_AGUARDANDO_CONSENTIMENTO_NOVIDADES);
        return $resultado;
    }

    private function registrarContatosPublicos(int $empresaId, array $payload): int
    {
        $dados = $payload['data'] ?? [];
        if (!is_array($dados)) return 0;
        $itens = array_is_list($dados) ? $dados : [$dados];
        $st = $this->pdo->prepare("INSERT INTO whatsapp_contatos_publicos
            (empresa_id, remote_jid, telefone, push_name, criado_em, atualizado_em)
            VALUES (:empresa_id, :remote_jid, :telefone, :push_name, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                telefone = COALESCE(VALUES(telefone), telefone),
                push_name = COALESCE(NULLIF(VALUES(push_name), ''), push_name),
                atualizado_em = NOW()");
        $total = 0;
        foreach ($itens as $item) {
            if (!is_array($item)) continue;
            $jid = trim((string)($item['remoteJid'] ?? $item['id'] ?? ''));
            $nome = trim((string)($item['pushName'] ?? $item['name'] ?? $item['verifiedName'] ?? ''));
            if ($jid === '' || $nome === '') continue;
            $telefone = null;
            if (str_contains($jid, '@s.whatsapp')) {
                $normalizado = $this->normalizarTelefone((string)preg_replace('/@.+$/', '', $jid));
                $telefone = $normalizado !== '' ? $normalizado : null;
            }
            $st->execute([
                ':empresa_id' => $empresaId,
                ':remote_jid' => mb_substr($jid, 0, 255, 'UTF-8'),
                ':telefone' => $telefone,
                ':push_name' => mb_substr($nome, 0, 150, 'UTF-8'),
            ]);
            $total++;
        }
        return $total;
    }

    public function interpretarResposta(string $texto): string
    {
        $original = trim($texto);
        if ($original === '') {
            return 'nao_entendido';
        }

        $decisaoEmoji = $this->interpretarEmojiBinario($original);
        $texto = $this->simplificarTexto($original);

        // Pedido explicito de troca de horario prevalece sobre a indisponibilidade.
        if (
            preg_match('/\b(remarc\w*|reagend\w*|trocar?\s+(?:o\s+)?horario)\b/u', $texto)
            || preg_match('/\b(outro\s+(?:horario|dia)|sexta\s+pode)\b/u', $texto)
        ) {
            return 'remarcar';
        }

        // Intencoes negativas precisam ser resolvidas antes de palavras como
        // "vou", "ok" ou "confirmar" que possam aparecer na mesma resposta.
        if (
            preg_match('/\b(cancel\w*|desmarc\w*)\b/u', $texto)
            || preg_match('/\bnao\s+(?:vou|posso|poderei|consigo|irei|estarei|comparec\w*)\b/u', $texto)
            || preg_match('/\b(?:nao\s+da|impossivel)\s+(?:de\s+)?(?:ir|comparecer)\b/u', $texto)
            || preg_match('/\bnao\s+(?:pode|quero)\s+confirmar\b/u', $texto)
            || $texto === 'nao'
        ) {
            return 'cancelar';
        }

        if ($decisaoEmoji === 'nao') {
            return 'cancelar';
        }

        if ($decisaoEmoji === 'sim') {
            return 'confirmar';
        }

        $grupos = [
            'cancelar' => [
                'nao, pode desmarcar',
                'nao pode desmarcar',
                'nao, pode cancelar',
                'nao pode cancelar',
                'pode desmarcar',
                'pode cancelar',
                'nao vou',
                'nao consigo comparecer',
                'nao poderei',
                'cancelar',
                'desmarcar',
                'cancelamento',
                'nao',
            ],
            'remarcar' => ['remarcar', 'reagendar', 'outro horario', 'trocar horario', 'sexta pode', 'outro dia', 'outro horario pode'],
            'duvida' => ['quem esta falando', 'qual consulta', 'que consulta', 'qual horario', 'quem e', 'onde fica'],
            'confirmar' => ['sim', 'confirmo', 'confirmada', 'confirmado', 'ok', 'okay', 'vou', 'estarei ai', 'pode confirmar', 'confirmar'],
        ];

        foreach ($grupos as $categoria => $expressoes) {
            foreach ($expressoes as $expressao) {
                if ($texto === $expressao || str_contains($texto, $expressao)) {
                    return $categoria;
                }
            }
        }

        if (preg_match('/\b(cancel|desmarc|nao\b)/u', $texto)) {
            return 'cancelar';
        }
        if (preg_match('/\b(remarc|reagend|trocar)\b/u', $texto)) {
            return 'remarcar';
        }
        if (preg_match('/\b(quem|qual|duvida|d[úu]vida)\b/u', $texto)) {
            return 'duvida';
        }

        if (preg_match('/\b(sim|confirm|ok|okay|vou|estarei)\b/u', $texto)) {
            return 'confirmar';
        }

        return 'nao_entendido';
    }

    private function buscarAgendamento(int $agendamentoId): ?array
    {
        $sql = "SELECT a.*,
                       e.nome AS empresa_nome,
                       u.nome AS profissional_nome
                  FROM agendamentos a
             LEFT JOIN empresas e ON e.id = a.empresa_id
             LEFT JOIN usuarios u ON u.id = a.profissional_id
                 WHERE a.id = ?
                 LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([$agendamentoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function montarMensagemAgendamento(array $agendamento, array $config, string $tipo): string
    {
        $template = $this->templates->getTemplate($config, $tipo);
        if ($template === '') {
            return '';
        }

        $inicio = new DateTime((string)$agendamento['data_hora_inicio']);
        $contexto = [
            'paciente_nome' => $this->extrairPrimeiroNome((string)($agendamento['paciente_nome'] ?? 'Paciente')),
            'empresa_nome' => trim((string)($agendamento['empresa_nome'] ?? 'sua clínica')),
            'profissional_nome' => trim((string)($agendamento['profissional_nome'] ?? 'profissional')),
            'data' => $inicio->format('d/m/Y'),
            'hora' => $inicio->format('H:i'),
            'data_hora' => $inicio->format('d/m/Y H:i'),
        ];

        return $this->templates->render($template, $contexto);
    }

    private function salvarOuAtualizarMensagemFila(array $agendamento, string $tipo, string $telefone, string $mensagem, string $agendadoPara): ?int
    {
        $payload = json_encode([
            'mensagem' => $mensagem,
            'data_hora_inicio' => (string)($agendamento['data_hora_inicio'] ?? ''),
            'data_hora_fim' => (string)($agendamento['data_hora_fim'] ?? ''),
            'paciente_nome' => (string)($agendamento['paciente_nome'] ?? ''),
            'empresa_nome' => (string)($agendamento['empresa_nome'] ?? ''),
            'profissional_nome' => (string)($agendamento['profissional_nome'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sqlBusca = "SELECT id
                       FROM whatsapp_jobs
                      WHERE empresa_id = :empresa_id
                        AND agendamento_id = :agendamento_id
                        AND tipo = :tipo
                        AND status IN ('pendente', 'erro')
                   ORDER BY id DESC";
        $stBusca = $this->pdo->prepare($sqlBusca);
        $stBusca->execute([
            ':empresa_id' => (int)$agendamento['empresa_id'],
            ':agendamento_id' => (int)$agendamento['id'],
            ':tipo' => $tipo,
        ]);
        $idsExistentes = array_map('intval', $stBusca->fetchAll(PDO::FETCH_COLUMN));
        $idExistente = $idsExistentes[0] ?? 0;

        if (count($idsExistentes) > 1) {
            $idsDuplicados = array_slice($idsExistentes, 1);
            $placeholders = implode(',', array_fill(0, count($idsDuplicados), '?'));
            $stCancelar = $this->pdo->prepare("UPDATE whatsapp_jobs
                                                  SET status = 'cancelado',
                                                      cancelado_em = NOW(),
                                                      locked_at = NULL,
                                                      worker_id = NULL,
                                                      atualizado_em = NOW()
                                                WHERE id IN ({$placeholders})");
            $stCancelar->execute($idsDuplicados);
        }

        if ($idExistente) {
            $st = $this->pdo->prepare("UPDATE whatsapp_jobs
                                          SET profissional_id = :profissional_id,
                                              paciente_id = :paciente_id,
                                              telefone = :telefone,
                                              payload_json = :payload_json,
                                              status = 'pendente',
                                              ultimo_erro = NULL,
                                              cancelado_em = NULL,
                                              locked_at = NULL,
                                              worker_id = NULL,
                                              agendado_para = :agendado_para,
                                              atualizado_em = NOW()
                                        WHERE id = :id");
            $st->execute([
                ':profissional_id' => $agendamento['profissional_id'] ?: null,
                ':paciente_id' => $agendamento['paciente_id'] ?: null,
                ':telefone' => $telefone,
                ':payload_json' => $payload ?: null,
                ':agendado_para' => $agendadoPara,
                ':id' => (int)$idExistente,
            ]);
            return (int)$idExistente;
        }

        $st = $this->pdo->prepare("INSERT INTO whatsapp_jobs
            (empresa_id, profissional_id, paciente_id, agendamento_id, tipo, telefone, payload_json, agendado_para, status, tentativas, criado_em, atualizado_em)
            VALUES
            (:empresa_id, :profissional_id, :paciente_id, :agendamento_id, :tipo, :telefone, :payload_json, :agendado_para, 'pendente', 0, NOW(), NOW())");
        $st->execute([
            ':empresa_id' => (int)$agendamento['empresa_id'],
            ':profissional_id' => $agendamento['profissional_id'] ?: null,
            ':paciente_id' => $agendamento['paciente_id'] ?: null,
            ':agendamento_id' => (int)$agendamento['id'],
            ':tipo' => $tipo,
            ':telefone' => $telefone,
            ':payload_json' => $payload ?: null,
            ':agendado_para' => $agendadoPara,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function calcularAgendamentoEnvio(string $dataHoraInicio, int $antecedenciaHoras): string
    {
        $inicio = new DateTime($dataHoraInicio);
        $envio = clone $inicio;
        $envio->modify('-' . max(1, $antecedenciaHoras) . ' hours');
        return $envio->format('Y-m-d H:i:s');
    }

    private function calcularAgendamentoLembrete(array $agendamento, array $config): ?string
    {
        $dataHoraInicio = (string)($agendamento['data_hora_inicio'] ?? '');
        if ($dataHoraInicio === '') {
            return null;
        }

        $antecedenciaHoras = (int)($config['antecedencia_lembrete_horas'] ?? 4);

        try {
            $inicio = new DateTime($dataHoraInicio);
            $agora = new DateTime();

            if ($inicio->format('Y-m-d') === $agora->format('Y-m-d')) {
                $antecedenciaHoras = (int)($config['antecedencia_minima_mesmo_dia_horas'] ?? 1);
            }
        } catch (Throwable $e) {
            return null;
        }

        return $this->calcularAgendamentoEnvio($dataHoraInicio, $antecedenciaHoras);
    }

    private function deveAgendarMensagem(string $agendadoPara): bool
    {
        try {
            $agendamento = new DateTime($agendadoPara);
            $agora = new DateTime();
            return $agendamento > $agora;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function deveAgendarConfirmacao(string $dataHoraInicio): bool
    {
        try {
            $inicio = new DateTime($dataHoraInicio);
            $agora = new DateTime();

            // Se o horario ideal ja passou, o job vencido sera coletado no
            // proximo cron. Confirmacao continua restrita a consultas futuras.
            return $inicio > $agora
                && $inicio->format('Y-m-d') > $agora->format('Y-m-d');
        } catch (Throwable $e) {
            return false;
        }
    }

    private function removerMensagensAgendadasInvalidas(int $agendamentoId, string $tipo): void
    {
        $st = $this->pdo->prepare("UPDATE whatsapp_jobs
                                      SET status = 'cancelado',
                                          cancelado_em = NOW(),
                                          locked_at = NULL,
                                          worker_id = NULL,
                                          atualizado_em = NOW()
                                    WHERE agendamento_id = :agendamento_id
                                      AND tipo = :tipo
                                      AND status IN ('pendente', 'erro', 'processando')");
        $st->execute([
            ':agendamento_id' => $agendamentoId,
            ':tipo' => $tipo,
        ]);
    }

    private function tipoAgendamentoJaEnviado(int $agendamentoId, string $tipo): bool
    {
        $st = $this->pdo->prepare("SELECT 1
                                    FROM whatsapp_mensagens
                                   WHERE agendamento_id = :agendamento_id
                                     AND tipo = :tipo
                                     AND direction = 'outbound'
                                     AND status IN ('enviada', 'entregue', 'lida', 'respondida')
                                   LIMIT 1");
        $st->execute([
            ':agendamento_id' => $agendamentoId,
            ':tipo' => $tipo,
        ]);
        return (bool)$st->fetchColumn();
    }

    private function decodificarPayloadJob(string $payloadJson): array
    {
        if (trim($payloadJson) === '') {
            return [];
        }

        $payload = json_decode($payloadJson, true);
        return is_array($payload) ? $payload : [];
    }

    private function normalizarDataInicioBackfill(string $dataInicio): string
    {
        $valor = trim($dataInicio);
        if ($valor === '') {
            throw new InvalidArgumentException('Informe a data inicial no formato YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            $valor .= ' 00:00:00';
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $valor);
        if (!$dt) {
            throw new InvalidArgumentException('Data inicial inválida. Use YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS.');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    private function extrairMessageId(array $data): string
    {
        $candidatos = [
            $data['key']['id'] ?? null,
            $data['message']['key']['id'] ?? null,
            $data['data']['key']['id'] ?? null,
            // Evolution v2 envia o ID real do WhatsApp em `keyId` nos
            // eventos MESSAGES_UPDATE. `messageId` nesse payload e um ID
            // interno da Evolution e nao corresponde ao valor persistido.
            $data['keyId'] ?? null,
            $data['data']['keyId'] ?? null,
            $data['data']['id'] ?? null,
            $data['id'] ?? null,
            $data['messageId'] ?? null,
            $data['data']['messageId'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') {
                return trim($candidato);
            }
        }

        return '';
    }

    private function extrairEvento(array $payload): string
    {
        $evento = $payload['event'] ?? $payload['type'] ?? $payload['eventName'] ?? '';
        return is_string($evento) ? strtolower(trim($evento)) : '';
    }

    private function extrairInstancia(array $payload): string
    {
        $candidatos = [
            $payload['instance'] ?? null,
            $payload['instanceName'] ?? null,
            $payload['data']['instance'] ?? null,
            $payload['data']['instanceName'] ?? null,
            $payload['sender'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') {
                return trim($candidato);
            }
            if (is_array($candidato)) {
                $nome = $candidato['instanceName'] ?? $candidato['name'] ?? '';
                if (is_string($nome) && trim($nome) !== '') {
                    return trim($nome);
                }
            }
        }

        return '';
    }

    private function buscarEmpresaPorInstancia(string $instancia): ?int
    {
        $st = $this->pdo->prepare("SELECT empresa_id FROM whatsapp_configuracoes WHERE instancia = ? LIMIT 1");
        $st->execute([$instancia]);
        $empresaId = $st->fetchColumn();
        return $empresaId ? (int)$empresaId : null;
    }

    private function extrairStatusConexao(array $payload): string
    {
        $candidatos = [
            $payload['instance']['state'] ?? null,
            $payload['instance']['status'] ?? null,
            $payload['data']['state'] ?? null,
            $payload['data']['status'] ?? null,
            $payload['data']['instance']['state'] ?? null,
            $payload['data']['instance']['status'] ?? null,
            $payload['state'] ?? null,
            $payload['status'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') {
                return strtolower(trim($candidato));
            }
        }

        return '';
    }

    private function extrairNumeroWhatsapp(array $payload): ?string
    {
        $candidatos = [
            $payload['data']['number'] ?? null,
            $payload['data']['ownerJid'] ?? null,
            $payload['data']['wid'] ?? null,
            $payload['data']['owner'] ?? null,
            $payload['data']['profileName'] ?? null,
            $payload['number'] ?? null,
            $payload['ownerJid'] ?? null,
            $payload['wid'] ?? null,
            $payload['owner'] ?? null,
            $payload['instance']['ownerJid'] ?? null,
            $payload['instance']['wid'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato)) {
                $candidato = preg_replace('/@.+$/', '', $candidato) ?? $candidato;
                $numero = $this->normalizarTelefone($candidato);
                if ($numero !== '') {
                    return $numero;
                }
            }
        }

        return null;
    }

    private function extrairNomeWhatsapp(array $payload): ?string
    {
        $candidatos = [
            $payload['data']['profileName'] ?? null,
            $payload['data']['pushName'] ?? null,
            $payload['data']['name'] ?? null,
            $payload['profileName'] ?? null,
            $payload['pushName'] ?? null,
            $payload['instance']['profileName'] ?? null,
            $payload['instance']['pushName'] ?? null,
            $payload['instance']['name'] ?? null,
        ];

        foreach ($candidatos as $candidato) {
            if (is_string($candidato) && trim($candidato) !== '') {
                return trim($candidato);
            }
        }

        return null;
    }

    private function atualizarConexaoEmpresa(int $empresaId, array $dados): void
    {
        $campos = ['status_conexao = :status_conexao', 'atualizado_em = NOW()'];
        $params = [
            ':empresa_id' => $empresaId,
            ':status_conexao' => $dados['status_conexao'] ?? 'desconectado',
        ];

        if (!empty($dados['numero_whatsapp'])) {
            $campos[] = 'numero_whatsapp = :numero_whatsapp';
            $params[':numero_whatsapp'] = $dados['numero_whatsapp'];
        }

        if (!empty($dados['nome_whatsapp'])) {
            $campos[] = 'nome_whatsapp = :nome_whatsapp';
            $params[':nome_whatsapp'] = $dados['nome_whatsapp'];
        }

        if (!empty($dados['ultima_conexao'])) {
            $campos[] = 'ultima_conexao = :ultima_conexao';
            $params[':ultima_conexao'] = $dados['ultima_conexao'];
        }

        $sql = "UPDATE whatsapp_configuracoes SET " . implode(', ', $campos) . " WHERE empresa_id = :empresa_id";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    public function sincronizarStatusInstancia(
        int $empresaId,
        array $config,
        array $statusData,
        ?array $instanceInfo = null
    ): array
    {
        $status = $this->extrairStatusConexao($statusData);
        if ($status === '') {
            $status = strtolower(trim((string)($config['status_conexao'] ?? 'desconectado')));
        }
        if ($status === '') {
            $status = 'desconectado';
        }

        $numeroWhatsapp = $instanceInfo !== null ? $this->extrairNumeroWhatsapp($instanceInfo) : null;
        if ($numeroWhatsapp === null) {
            $numeroWhatsapp = $this->extrairNumeroWhatsapp($statusData);
        }

        $nomeWhatsapp = $instanceInfo !== null ? $this->extrairNomeWhatsapp($instanceInfo) : null;
        if ($nomeWhatsapp === null) {
            $nomeWhatsapp = $this->extrairNomeWhatsapp($statusData);
        }

        $this->atualizarConexaoEmpresa($empresaId, [
            'status_conexao' => $status,
            'numero_whatsapp' => $numeroWhatsapp,
            'nome_whatsapp' => $nomeWhatsapp,
            'ultima_conexao' => in_array($status, ['open', 'connected', 'conectado'], true) ? date('Y-m-d H:i:s') : null,
        ]);

        return $this->carregarConfiguracaoEmpresa($empresaId) ?? $config;
    }

    private function ehEventoStatusMensagem(string $evento, array $payload): bool
    {
        if (str_contains($evento, 'send.message.update') || str_contains($evento, 'messages.update') || str_contains($evento, 'send_message_update')) {
            return true;
        }

        $status = $this->extrairStatusMensagem($payload);
        return $status !== '';
    }

    private function extrairStatusMensagem(array $payload): string
    {
        $status = $payload['data']['status'] ?? $payload['status'] ?? $payload['data']['messageStatus'] ?? null;
        $ack = $payload['data']['ack'] ?? $payload['ack'] ?? null;

        if (is_numeric($ack)) {
            $map = [
                1 => 'enviada',
                2 => 'entregue',
                3 => 'lida',
                4 => 'lida',
            ];
            $ackInt = (int)$ack;
            if (isset($map[$ackInt])) {
                return $map[$ackInt];
            }
        }

        if (is_string($status)) {
            $status = strtolower(trim($status));
            $map = [
                'sent' => 'enviada',
                'server_ack' => 'enviada',
                'delivery_ack' => 'entregue',
                'delivered' => 'entregue',
                'read' => 'lida',
                'read_ack' => 'lida',
            ];
            return $map[$status] ?? '';
        }

        return '';
    }

    private function atualizarStatusMensagemPorWebhook(string $messageId, string $status): void
    {
        $colunaData = match ($status) {
            'enviada' => 'enviado_em',
            'entregue' => 'entregue_em',
            'lida' => 'lido_em',
            default => null,
        };

        $sql = "UPDATE whatsapp_mensagens
                   SET status = :status,
                       atualizado_em = NOW()";
        if ($colunaData) {
            $sql .= ", {$colunaData} = COALESCE({$colunaData}, NOW())";
        }
        $sql .= " WHERE evolution_message_id = :message_id";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':status' => $status,
            ':message_id' => $messageId,
        ]);
    }

    private function ehMensagemRecebidaDoPaciente(string $evento, array $payload): bool
    {
        if (!str_contains($evento, 'messages.upsert') && !str_contains($evento, 'messages.set')) {
            return false;
        }

        $fromMe = $payload['data']['key']['fromMe'] ?? $payload['key']['fromMe'] ?? false;
        return !$fromMe;
    }

    private function extrairTextoMensagem(array $payload): string
    {
        $mensagem = $payload['data']['message'] ?? $payload['message'] ?? [];
        if (isset($mensagem['conversation']) && is_string($mensagem['conversation'])) {
            return trim($mensagem['conversation']);
        }
        if (isset($mensagem['extendedTextMessage']['text']) && is_string($mensagem['extendedTextMessage']['text'])) {
            return trim($mensagem['extendedTextMessage']['text']);
        }
        if (isset($payload['data']['text']) && is_string($payload['data']['text'])) {
            return trim($payload['data']['text']);
        }

        return '';
    }

    private function extrairTelefoneMensagem(array $payload): string
    {
        $remoteJid = $payload['data']['key']['remoteJid'] ?? $payload['key']['remoteJid'] ?? $payload['data']['remoteJid'] ?? '';
        if (!is_string($remoteJid)) {
            return '';
        }
        return preg_replace('/@.+$/', '', $remoteJid) ?? '';
    }

    private function registrarRespostaPaciente(
        int $empresaId,
        string $telefone,
        string $texto,
        string $interpretacao,
        string $messageId = '',
        bool $preservarStatusAgendamento = false,
        ?string $statusAgendamentoForcado = null
    ): array
    {
        if ($messageId !== '') {
            $stDuplicada = $this->pdo->prepare("SELECT id
                                                  FROM whatsapp_mensagens
                                                 WHERE empresa_id = :empresa_id
                                                   AND tipo = 'recebida'
                                                   AND evolution_message_id = :message_id
                                              ORDER BY id DESC
                                                 LIMIT 1");
            $stDuplicada->execute([
                ':empresa_id' => $empresaId,
                ':message_id' => $messageId,
            ]);
            $idDuplicada = $stDuplicada->fetchColumn();
            if ($idDuplicada) {
                return [
                    'id' => (int)$idDuplicada,
                    'duplicada' => true,
                ];
            }
        }

        $telefoneNormalizado = preg_replace('/\D+/', '', $telefone) ?? '';
        $telefonesVariantes = $this->gerarVariantesTelefone($telefoneNormalizado);
        $conversa = $this->buscarConversaAtivaPorTelefone($empresaId, $telefoneNormalizado);
        $mensagem = null;
        $referencias = [
            'conversa_id' => $conversa['id'] ?? null,
            'profissional_id' => $conversa['profissional_id'] ?? null,
            'paciente_id' => $conversa['paciente_id'] ?? null,
            'agendamento_id' => $conversa['agendamento_id_ativo'] ?? null,
        ];

        if (!empty($referencias['conversa_id']) && !empty($conversa['ultima_mensagem_outbound_id'])) {
            $stMensagem = $this->pdo->prepare("SELECT id, agendamento_id, profissional_id, paciente_id
                                                 FROM whatsapp_mensagens
                                                WHERE id = :id
                                                LIMIT 1");
            $stMensagem->execute([
                ':id' => (int)$conversa['ultima_mensagem_outbound_id'],
            ]);
            $mensagem = $stMensagem->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($mensagem && empty($mensagem['agendamento_id'])) {
                $mensagem = null;
            }
        }

        if (!$mensagem) {
            $job = $this->buscarContextoJobPorTelefone($empresaId, $telefonesVariantes);
            if ($job) {
                $referencias = [
                    'conversa_id' => $job['conversa_id'] ?? $referencias['conversa_id'],
                    'profissional_id' => $job['profissional_id'] ?? $referencias['profissional_id'],
                    'paciente_id' => $job['paciente_id'] ?? $referencias['paciente_id'],
                    'agendamento_id' => $job['agendamento_id'] ?? $referencias['agendamento_id'],
                    'mensagem_tipo' => $job['tipo'] ?? null,
                ];

                if (!empty($referencias['conversa_id'])) {
                    $stMensagem = $this->pdo->prepare("SELECT id, agendamento_id, profissional_id, paciente_id, tipo
                                                         FROM whatsapp_mensagens
                                                        WHERE empresa_id = :empresa_id
                                                          AND conversa_id = :conversa_id
                                                          AND direction = 'outbound'
                                                     ORDER BY id DESC
                                                        LIMIT 1");
                    $stMensagem->execute([
                        ':empresa_id' => $empresaId,
                        ':conversa_id' => (int)$referencias['conversa_id'],
                    ]);
                    $mensagem = $stMensagem->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            }
        }

        if (empty($referencias['agendamento_id'])) {
            [$clausulaTelefoneAgendamento, $paramsTelefoneAgendamento] = $this->montarClausulaTelefoneNormalizado(
                "COALESCE(a.telefone, '')",
                $telefonesVariantes,
                'telefone_ag_'
            );

            $sqlAgendamento = "SELECT a.id AS agendamento_id,
                                      a.profissional_id,
                                      a.paciente_id
                                 FROM agendamentos a
                                WHERE a.empresa_id = :empresa_id
                                  AND {$clausulaTelefoneAgendamento}
                                  AND a.data_hora_inicio >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                             ORDER BY CASE WHEN a.data_hora_inicio >= NOW() THEN 0 ELSE 1 END,
                                      ABS(TIMESTAMPDIFF(MINUTE, NOW(), a.data_hora_inicio)),
                                      a.id DESC
                                LIMIT 1";
            $stAgendamento = $this->pdo->prepare($sqlAgendamento);
            $stAgendamento->execute(array_merge([
                ':empresa_id' => $empresaId,
            ], $paramsTelefoneAgendamento));
            $agendamento = $stAgendamento->fetch(PDO::FETCH_ASSOC) ?: [];

            if ($agendamento) {
                $referencias = [
                    'conversa_id' => $referencias['conversa_id'],
                    'profissional_id' => $agendamento['profissional_id'] ?? null,
                    'paciente_id' => $agendamento['paciente_id'] ?? null,
                    'agendamento_id' => $agendamento['agendamento_id'] ?? null,
                    'mensagem_tipo' => $referencias['mensagem_tipo'] ?? null,
                ];
            }
        }

        $stRecebida = $this->pdo->prepare("INSERT INTO whatsapp_mensagens
            (empresa_id, profissional_id, paciente_id, agendamento_id, conversa_id, direction, tipo, telefone, mensagem, evolution_message_id, status, resposta_texto, interpretacao, processado, respondido_em, recebido_em, criado_em, atualizado_em)
            VALUES
            (:empresa_id, :profissional_id, :paciente_id, :agendamento_id, :conversa_id, 'inbound', 'recebida', :telefone, :mensagem, :evolution_message_id, 'recebida', :resposta_texto, :interpretacao, 1, NOW(), NOW(), NOW(), NOW())");
        $stRecebida->execute([
            ':empresa_id' => $empresaId,
            ':profissional_id' => $referencias['profissional_id'] ?: null,
            ':paciente_id' => $referencias['paciente_id'] ?: null,
            ':agendamento_id' => $referencias['agendamento_id'] ?: null,
            ':conversa_id' => $referencias['conversa_id'] ?: null,
            ':telefone' => $telefone,
            ':mensagem' => $texto,
            ':evolution_message_id' => $messageId !== '' ? $messageId : null,
            ':resposta_texto' => $texto,
            ':interpretacao' => $interpretacao,
        ]);
        $mensagemRecebidaId = (int)$this->pdo->lastInsertId();

        $conversaId = $this->sincronizarConversaEntrada($empresaId, $telefone, $mensagemRecebidaId, $referencias, $interpretacao);
        if ($conversaId !== null && empty($referencias['conversa_id'])) {
            $referencias['conversa_id'] = $conversaId;
            $upConversaMensagem = $this->pdo->prepare("UPDATE whatsapp_mensagens
                                                          SET conversa_id = :conversa_id,
                                                              atualizado_em = NOW()
                                                        WHERE id = :id");
            $upConversaMensagem->execute([
                ':conversa_id' => $conversaId,
                ':id' => $mensagemRecebidaId,
            ]);
        }

        if ($mensagem) {
            $up = $this->pdo->prepare("UPDATE whatsapp_mensagens
                                          SET resposta_texto = :resposta_texto,
                                              interpretacao = :interpretacao,
                                              status = 'respondida',
                                              processado = 1,
                                              respondido_em = NOW(),
                                              atualizado_em = NOW()
                                        WHERE id = :id");
            $up->execute([
                ':resposta_texto' => $texto,
                ':interpretacao' => $interpretacao,
                ':id' => (int)$mensagem['id'],
            ]);
        }

        if (
            (!$preservarStatusAgendamento || $statusAgendamentoForcado !== null)
            && !empty($referencias['agendamento_id'])
        ) {
            $stStatusAtual = $this->pdo->prepare("SELECT status
                                                    FROM agendamentos
                                                   WHERE id = :id
                                                   LIMIT 1");
            $stStatusAtual->execute([
                ':id' => (int)$referencias['agendamento_id'],
            ]);
            $statusAtual = (string)($stStatusAtual->fetchColumn() ?: '');
            $mensagemTipo = (string)($referencias['mensagem_tipo'] ?? '');

            $novoStatus = $statusAgendamentoForcado ?? match ($interpretacao) {
                    'confirmar' => ($mensagemTipo === 'lembrete' && $statusAtual === 'confirmado') ? null : 'confirmado',
                    'cancelar' => 'desmarcado pelo paciente',
                    'remarcar' => 'remarcacao_solicitada',
                    default => null,
                };

            if ($novoStatus !== null) {
                $ag = $this->pdo->prepare("UPDATE agendamentos SET status = :status WHERE id = :id");
                $ag->execute([
                    ':status' => $novoStatus,
                    ':id' => (int)$referencias['agendamento_id'],
                ]);

                if ($interpretacao === 'cancelar') {
                    $this->removerMensagensPendentesAgendamento((int)$referencias['agendamento_id']);
                }
            }
        }

        return [
            'id' => $mensagemRecebidaId,
            'duplicada' => false,
            'conversa_id' => !empty($referencias['conversa_id']) ? (int)$referencias['conversa_id'] : null,
            'agendamento_id' => !empty($referencias['agendamento_id']) ? (int)$referencias['agendamento_id'] : null,
            'paciente_id' => !empty($referencias['paciente_id']) ? (int)$referencias['paciente_id'] : null,
            'profissional_id' => !empty($referencias['profissional_id']) ? (int)$referencias['profissional_id'] : null,
        ];
    }

    private function montarRetornoInterpretacao(string $interpretacao): string
    {
        return match ($interpretacao) {
            'confirmar' => 'Obrigada por confirmar, estamos te esperando!',
            'cancelar' => 'Que pena que não poderá comparecer. Gostaria de deixar outro horário marcado?',
            'remarcar' => 'Sem problemas! Podemos te ajudar a remarcar. Qual horário você prefere?',
            default => '',
        };
    }

    private function ehMensagemCortesia(string $mensagem): bool
    {
        if (preg_match('/^(?:\s|[👍🙏👏👌✅🙂😊😁❤️❤])+$/u', $mensagem) === 1) {
            return true;
        }
        $texto = $this->simplificarTexto($mensagem);
        $texto = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $texto) ?? $texto);
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

        return in_array($texto, [
            'ok', 'okay', 'obrigado', 'obrigada', 'muito obrigado', 'muito obrigada',
            'obrigado viu', 'obrigada viu', 'agradeco', 'agradecido', 'agradecida',
            'valeu', 'ta bom', 'tudo bem', 'certo', 'perfeito', 'combinado',
            'blz', 'beleza', 'show', 'joia', 'bom dia obrigado', 'bom dia obrigada',
            'boa tarde obrigado', 'boa tarde obrigada', 'boa noite obrigado',
            'boa noite obrigada',
        ], true);
    }

    private function interpretarDecisaoRemarcacao(string $texto): string
    {
        $decisaoEmoji = $this->interpretarEmojiBinario($texto);
        $texto = $this->simplificarTexto($texto);

        if (
            preg_match('/^(nao|n|negativo)(?:\b|$)/u', $texto)
            || preg_match('/\b(nao quero|deixa pra la|agora nao|nao precisa)\b/u', $texto)
        ) {
            return 'nao';
        }

        if (
            preg_match('/^(sim|s|ok|quero|gostaria|pode)(?:\b|$)/u', $texto)
            || preg_match('/\b(sim por favor|quero remarcar|gostaria de remarcar|pode remarcar)\b/u', $texto)
        ) {
            return 'sim';
        }

        return $decisaoEmoji;
    }

    private function interpretarEmojiBinario(string $texto): string
    {
        $temNegativo = preg_match('/[👎❌🚫]/u', $texto) === 1;
        $temPositivo = preg_match('/[👍✅👌]/u', $texto) === 1;

        if ($temNegativo === $temPositivo) {
            return '';
        }

        if ($temNegativo) {
            return 'nao';
        }

        if ($temPositivo) {
            return 'sim';
        }

        return '';
    }

    private function ultimaMensagemSolicitaNovoHorario(int $empresaId, string $telefone): bool
    {
        try {
            [$clausulaTelefone, $paramsTelefone] = $this->montarClausulaTelefoneNormalizado(
                'telefone',
                $this->gerarVariantesTelefone($telefone),
                'decisao_remarcacao_tel_'
            );

            $st = $this->pdo->prepare("SELECT mensagem
                                         FROM whatsapp_mensagens
                                        WHERE empresa_id = :empresa_id
                                          AND direction = 'outbound'
                                          AND {$clausulaTelefone}
                                     ORDER BY id DESC
                                        LIMIT 1");
            $st->execute(array_merge([
                ':empresa_id' => $empresaId,
            ], $paramsTelefone));

            $mensagem = $this->simplificarTexto((string)($st->fetchColumn() ?: ''));
            return str_contains($mensagem, 'gostaria de deixar outro horario marcado');
        } catch (Throwable $e) {
            return false;
        }
    }

    private function atualizarEstadoConversa(
        int $empresaId,
        string $telefone,
        string $estado,
        bool $limparAgendamentoAtivo = false
    ): void
    {
        $telefone = $this->normalizarTelefone($telefone);
        if ($empresaId <= 0 || $telefone === '') {
            return;
        }

        [$clausulaTelefone, $paramsTelefone] = $this->montarClausulaTelefoneExato(
            'telefone',
            $this->gerarVariantesTelefone($telefone),
            'estado_conversa_tel_'
        );

        $st = $this->pdo->prepare("UPDATE whatsapp_conversas
                                      SET observacoes = :observacoes,
                                          ultimo_status = :ultimo_status,
                                          agendamento_id_ativo = CASE
                                              WHEN :limpar_agendamento_ativo = 1 THEN NULL
                                              ELSE agendamento_id_ativo
                                          END,
                                          expira_em = GREATEST(COALESCE(expira_em, NOW()), DATE_ADD(NOW(), INTERVAL 2 DAY)),
                                          atualizado_em = NOW()
                                    WHERE empresa_id = :empresa_id
                                      AND {$clausulaTelefone}");
        $st->execute(array_merge([
            ':observacoes' => $estado,
            ':ultimo_status' => $estado,
            ':limpar_agendamento_ativo' => $limparAgendamentoAtivo ? 1 : 0,
            ':empresa_id' => $empresaId,
        ], $paramsTelefone));
    }

    private function enviarRespostaAutomatica(int $empresaId, string $instancia, string $telefone, string $mensagem, array $contexto = []): ?int
    {
        $config = $this->carregarConfiguracaoEmpresa($empresaId);
        if (!$config) {
            return null;
        }

        $evolution = $this->evolution;
        $resp = $evolution->sendText($instancia, $telefone, $mensagem);
        $messageId = $this->extrairMessageId($resp['data']);

        $st = $this->pdo->prepare("INSERT INTO whatsapp_mensagens
            (empresa_id, profissional_id, paciente_id, agendamento_id, tipo, direction, origem_envio, telefone, mensagem, evolution_message_id, status, processado, tentativas, agendado_para, enviado_em, criado_em, atualizado_em)
            VALUES
            (:empresa_id, :profissional_id, :paciente_id, :agendamento_id, 'manual', 'outbound', 'atendente_virtual', :telefone, :mensagem, :evolution_message_id, 'enviada', 1, 1, NOW(), NOW(), NOW(), NOW())");
        $st->execute([
            ':empresa_id' => $empresaId,
            ':profissional_id' => $contexto['profissional_id'] ?? null,
            ':paciente_id' => $contexto['paciente_id'] ?? null,
            ':agendamento_id' => $contexto['agendamento_id'] ?? null,
            ':telefone' => $telefone,
            ':mensagem' => $mensagem,
            ':evolution_message_id' => $messageId !== '' ? $messageId : null,
        ]);
        $registroId = (int)$this->pdo->lastInsertId();

        $this->sincronizarConversaSaida($registroId, [
            'empresa_id' => $empresaId,
            'telefone' => $telefone,
            'tipo' => 'manual',
            'paciente_id' => $contexto['paciente_id'] ?? null,
            'profissional_id' => $contexto['profissional_id'] ?? null,
            'agendamento_id' => $contexto['agendamento_id'] ?? null,
            'data_hora_inicio' => null,
            'data_hora_fim' => null,
        ], $messageId);

        return $registroId;
    }

    private function buscarConversaAtivaPorTelefone(int $empresaId, string $telefone): ?array
    {
        try {
            [$clausulaTelefone, $paramsTelefone] = $this->montarClausulaTelefoneExato(
                'c.telefone',
                $this->gerarVariantesTelefone($telefone),
                'conversa_tel_'
            );

            $st = $this->pdo->prepare("SELECT c.id,
                                              c.empresa_id,
                                              c.telefone,
                                              c.paciente_id,
                                              c.agendamento_id_ativo,
                                              c.ultima_mensagem_outbound_id,
                                              c.ultima_mensagem_inbound_id,
                                              c.ultimo_tipo,
                                              c.ultimo_status,
                                              c.observacoes,
                                              c.expira_em,
                                              mo.tipo AS ultimo_tipo_saida,
                                              a.profissional_id
                                         FROM whatsapp_conversas c
                                    LEFT JOIN agendamentos a ON a.id = c.agendamento_id_ativo
                                    LEFT JOIN whatsapp_mensagens mo ON mo.id = c.ultima_mensagem_outbound_id
                                        WHERE c.empresa_id = :empresa_id
                                          AND {$clausulaTelefone}
                                          AND (c.expira_em IS NULL OR c.expira_em >= NOW())
                                     ORDER BY CASE WHEN c.agendamento_id_ativo IS NULL THEN 1 ELSE 0 END,
                                              c.atualizado_em DESC,
                                              c.id DESC
                                        LIMIT 1");
            $st->execute(array_merge([
                ':empresa_id' => $empresaId,
            ], $paramsTelefone));
            $conversa = $st->fetch(PDO::FETCH_ASSOC);
            return $conversa ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function buscarContextoJobPorTelefone(int $empresaId, array $telefonesVariantes): ?array
    {
        try {
            [$clausulaTelefone, $paramsTelefone] = $this->montarClausulaTelefoneNormalizado(
                'telefone',
                $telefonesVariantes,
                'job_tel_'
            );

            $sql = "SELECT id,
                           conversa_id,
                           agendamento_id,
                           profissional_id,
                           paciente_id,
                           tipo
                      FROM whatsapp_jobs
                     WHERE empresa_id = :empresa_id
                       AND agendamento_id IS NOT NULL
                       AND tipo IN ('confirmacao', 'lembrete', 'cancelamento', 'cobranca', 'orcamento')
                       AND status IN ('pendente', 'processando', 'enviado')
                       AND {$clausulaTelefone}
                  ORDER BY CASE status
                               WHEN 'enviado' THEN 0
                               WHEN 'processando' THEN 1
                               ELSE 2
                           END,
                           COALESCE(enviado_em, agendado_para) DESC,
                           id DESC
                     LIMIT 1";
            $st = $this->pdo->prepare($sql);
            $st->execute(array_merge([
                ':empresa_id' => $empresaId,
            ], $paramsTelefone));

            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function sincronizarConversaSaida(int $mensagemId, array $dados, string $messageId = ''): ?int
    {
        $empresaId = (int)($dados['empresa_id'] ?? 0);
        $telefone = $this->normalizarTelefone((string)($dados['telefone'] ?? ''));
        if ($empresaId <= 0 || $telefone === '') {
            return null;
        }

        $expiraEm = $this->calcularExpiracaoConversa($dados);

        try {
            $st = $this->pdo->prepare("INSERT INTO whatsapp_conversas
                (empresa_id, telefone, paciente_id, agendamento_id_ativo, ultima_mensagem_outbound_id, ultimo_tipo, ultimo_status, ultima_interacao_em, expira_em, criado_em, atualizado_em)
                VALUES
                (:empresa_id, :telefone, :paciente_id, :agendamento_id_ativo, :ultima_mensagem_outbound_id, :ultimo_tipo, 'enviada', NOW(), :expira_em, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    paciente_id = VALUES(paciente_id),
                    agendamento_id_ativo = VALUES(agendamento_id_ativo),
                    ultima_mensagem_outbound_id = VALUES(ultima_mensagem_outbound_id),
                    ultimo_tipo = VALUES(ultimo_tipo),
                    ultimo_status = 'enviada',
                    ultima_interacao_em = NOW(),
                    observacoes = CASE
                        WHEN VALUES(ultimo_tipo) IN ('confirmacao', 'lembrete') THEN NULL
                        ELSE observacoes
                    END,
                    expira_em = VALUES(expira_em),
                    atualizado_em = NOW()");
            $st->execute([
                ':empresa_id' => $empresaId,
                ':telefone' => $telefone,
                ':paciente_id' => !empty($dados['paciente_id']) ? (int)$dados['paciente_id'] : null,
                ':agendamento_id_ativo' => !empty($dados['agendamento_id']) ? (int)$dados['agendamento_id'] : null,
                ':ultima_mensagem_outbound_id' => $mensagemId,
                ':ultimo_tipo' => (string)($dados['tipo'] ?? 'manual'),
                ':expira_em' => $expiraEm,
            ]);

            $conversaId = $this->buscarIdConversa($empresaId, $telefone);
            if ($conversaId !== null) {
                $params = [
                    ':id' => $mensagemId,
                    ':conversa_id' => $conversaId,
                ];
                $sql = "UPDATE whatsapp_mensagens
                           SET conversa_id = :conversa_id,
                               direction = 'outbound',
                               atualizado_em = NOW()";
                if ($messageId !== '') {
                    $sql .= ", evolution_message_id = :message_id";
                    $params[':message_id'] = $messageId;
                }
                $sql .= " WHERE id = :id";
                $up = $this->pdo->prepare($sql);
                $up->execute($params);
            }

            return $conversaId;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function sincronizarConversaEntrada(int $empresaId, string $telefone, int $mensagemRecebidaId, array $referencias, string $status): ?int
    {
        $telefone = $this->normalizarTelefone($telefone);
        if ($empresaId <= 0 || $telefone === '') {
            return null;
        }

        try {
            if (!empty($referencias['conversa_id'])) {
                $st = $this->pdo->prepare("UPDATE whatsapp_conversas
                                              SET telefone = :telefone,
                                                  paciente_id = COALESCE(:paciente_id, paciente_id),
                                                  agendamento_id_ativo = COALESCE(:agendamento_id_ativo, agendamento_id_ativo),
                                                  ultima_mensagem_inbound_id = :ultima_mensagem_inbound_id,
                                                  ultimo_tipo = 'recebida',
                                                  ultimo_status = :ultimo_status,
                                                  ultima_interacao_em = NOW(),
                                                  expira_em = GREATEST(COALESCE(expira_em, NOW()), DATE_ADD(NOW(), INTERVAL 2 DAY)),
                                                  atualizado_em = NOW()
                                            WHERE id = :id
                                              AND empresa_id = :empresa_id");
                $st->execute([
                    ':id' => (int)$referencias['conversa_id'],
                    ':empresa_id' => $empresaId,
                    ':telefone' => $telefone,
                    ':paciente_id' => !empty($referencias['paciente_id']) ? (int)$referencias['paciente_id'] : null,
                    ':agendamento_id_ativo' => !empty($referencias['agendamento_id']) ? (int)$referencias['agendamento_id'] : null,
                    ':ultima_mensagem_inbound_id' => $mensagemRecebidaId,
                    ':ultimo_status' => $status,
                ]);

                return (int)$referencias['conversa_id'];
            }

            $st = $this->pdo->prepare("INSERT INTO whatsapp_conversas
                (empresa_id, telefone, paciente_id, agendamento_id_ativo, ultima_mensagem_inbound_id, ultimo_tipo, ultimo_status, ultima_interacao_em, expira_em, criado_em, atualizado_em)
                VALUES
                (:empresa_id, :telefone, :paciente_id, :agendamento_id_ativo, :ultima_mensagem_inbound_id, 'recebida', :ultimo_status, NOW(), DATE_ADD(NOW(), INTERVAL 2 DAY), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    paciente_id = COALESCE(VALUES(paciente_id), paciente_id),
                    agendamento_id_ativo = COALESCE(VALUES(agendamento_id_ativo), agendamento_id_ativo),
                    ultima_mensagem_inbound_id = VALUES(ultima_mensagem_inbound_id),
                    ultimo_tipo = 'recebida',
                    ultimo_status = VALUES(ultimo_status),
                    ultima_interacao_em = NOW(),
                    expira_em = GREATEST(COALESCE(expira_em, NOW()), DATE_ADD(NOW(), INTERVAL 2 DAY)),
                    atualizado_em = NOW()");
            $st->execute([
                ':empresa_id' => $empresaId,
                ':telefone' => $telefone,
                ':paciente_id' => !empty($referencias['paciente_id']) ? (int)$referencias['paciente_id'] : null,
                ':agendamento_id_ativo' => !empty($referencias['agendamento_id']) ? (int)$referencias['agendamento_id'] : null,
                ':ultima_mensagem_inbound_id' => $mensagemRecebidaId,
                ':ultimo_status' => $status,
            ]);

            return $this->buscarIdConversa($empresaId, $telefone);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function buscarIdConversa(int $empresaId, string $telefone): ?int
    {
        [$clausulaTelefone, $paramsTelefone] = $this->montarClausulaTelefoneExato(
            'telefone',
            $this->gerarVariantesTelefone($telefone),
            'conversa_id_tel_'
        );

        $st = $this->pdo->prepare("SELECT id
                                     FROM whatsapp_conversas
                                    WHERE empresa_id = :empresa_id
                                      AND {$clausulaTelefone}
                                 ORDER BY CASE WHEN agendamento_id_ativo IS NULL THEN 1 ELSE 0 END,
                                          atualizado_em DESC,
                                          id DESC
                                    LIMIT 1");
        $st->execute(array_merge([
            ':empresa_id' => $empresaId,
        ], $paramsTelefone));
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }

    private function calcularExpiracaoConversa(array $dados): string
    {
        $inicio = trim((string)($dados['data_hora_inicio'] ?? ''));
        $fim = trim((string)($dados['data_hora_fim'] ?? ''));
        $tipo = (string)($dados['tipo'] ?? '');

        try {
            if ($fim !== '') {
                $base = new DateTime($fim);
            } elseif ($inicio !== '') {
                $base = new DateTime($inicio);
            } else {
                $base = new DateTime();
            }
        } catch (Throwable $e) {
            $base = new DateTime();
        }

        if ($tipo === 'confirmacao') {
            $base->modify('+2 day');
        } elseif ($tipo === 'lembrete') {
            $base->modify('+1 day');
        } else {
            $base->modify('+2 day');
        }

        return $base->format('Y-m-d H:i:s');
    }

    private function registrarLogWebhook(array $dados): void
    {
        $dir = defined('STORAGE_PATH') ? STORAGE_PATH . '/logs' : dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $linha = sprintf(
            "[%s] %s\n",
            date('Y-m-d H:i:s'),
            json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        @file_put_contents($dir . '/evolution-webhook.log', $linha, FILE_APPEND);
    }

    private function registrarEventoWebhook(?int $empresaId, string $instancia, string $evento, string $telefone, string $messageId, array $payload): ?int
    {
        try {
            $st = $this->pdo->prepare("INSERT INTO whatsapp_eventos
                (empresa_id, instancia, evento, telefone, evolution_message_id, payload_json, processado, criado_em)
                VALUES
                (:empresa_id, :instancia, :evento, :telefone, :evolution_message_id, :payload_json, 0, NOW())");
            $st->execute([
                ':empresa_id' => $empresaId ?: null,
                ':instancia' => $instancia !== '' ? $instancia : null,
                ':evento' => $evento !== '' ? $evento : 'desconhecido',
                ':telefone' => $telefone !== '' ? $telefone : null,
                ':evolution_message_id' => $messageId !== '' ? $messageId : null,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function finalizarEventoWebhook(?int $eventoId, bool $processado, ?string $erro = null): void
    {
        if (empty($eventoId)) {
            return;
        }

        try {
            $st = $this->pdo->prepare("UPDATE whatsapp_eventos
                                          SET processado = :processado,
                                              erro_processamento = :erro_processamento
                                        WHERE id = :id");
            $st->execute([
                ':processado' => $processado ? 1 : 0,
                ':erro_processamento' => $erro !== null && $erro !== '' ? $erro : null,
                ':id' => (int)$eventoId,
            ]);
        } catch (Throwable $e) {
        }
    }

    public function normalizarTelefone(string $telefone): string
    {
        $digits = preg_replace('/\D+/', '', $telefone) ?? '';
        if ($digits === '') {
            return '';
        }
        if (!str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }
        return $digits;
    }

    private function gerarVariantesTelefone(string $telefone): array
    {
        $telefone = $this->normalizarTelefone($telefone);
        if ($telefone === '') {
            return [];
        }

        $variantes = [$telefone];

        if (str_starts_with($telefone, '55')) {
            $ddd = substr($telefone, 2, 2);
            $restante = substr($telefone, 4);

            if (strlen($restante) === 9 && str_starts_with($restante, '9')) {
                $variantes[] = '55' . $ddd . substr($restante, 1);
            } elseif (strlen($restante) === 8) {
                $variantes[] = '55' . $ddd . '9' . $restante;
            }
        }

        return array_values(array_unique(array_filter($variantes)));
    }

    private function montarClausulaTelefoneExato(string $coluna, array $telefones, string $prefixo): array
    {
        $telefones = array_values(array_unique(array_filter($telefones)));
        if (!$telefones) {
            return ['1 = 0', []];
        }

        $partes = [];
        $params = [];
        foreach ($telefones as $indice => $telefone) {
            $placeholder = ':' . $prefixo . $indice;
            $partes[] = "{$coluna} = {$placeholder}";
            $params[$placeholder] = $telefone;
        }

        return ['(' . implode(' OR ', $partes) . ')', $params];
    }

    private function montarClausulaTelefoneNormalizado(string $coluna, array $telefones, string $prefixo): array
    {
        $telefones = array_values(array_unique(array_filter($telefones)));
        if (!$telefones) {
            return ['1 = 0', []];
        }

        $expressao = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$coluna}, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')";
        $partes = [];
        $params = [];
        foreach ($telefones as $indice => $telefone) {
            $placeholder = ':' . $prefixo . $indice;
            $partes[] = "{$expressao} = {$placeholder}";
            $params[$placeholder] = $telefone;
        }

        return ['(' . implode(' OR ', $partes) . ')', $params];
    }

    private function simplificarTexto(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        // A transliteracao do iconv pode transformar "ã" em "~a" em alguns
        // servidores. Normalize primeiro os caracteres usados em portugues.
        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
        $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
        return trim($texto);
    }

    private function extrairPrimeiroNome(string $nomeCompleto): string
    {
        $nomeCompleto = trim($nomeCompleto);
        if ($nomeCompleto === '') {
            return 'Paciente';
        }

        $partes = preg_split('/\s+/u', $nomeCompleto);
        if (!$partes || trim((string)($partes[0] ?? '')) === '') {
            return 'Paciente';
        }

        return trim((string)$partes[0]);
    }

    private function normalizarSlug(string $texto): string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return '';
        }
        $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
        $texto = strtolower($texto);
        $texto = preg_replace('/[^a-z0-9]+/', '-', $texto) ?? $texto;
        return trim($texto, '-');
    }

    private function garantirSchemaConfiguracoes(): void
    {
        static $feito = false;
        if ($feito) {
            return;
        }

        try {
            $st = $this->pdo->query("SHOW COLUMNS FROM whatsapp_configuracoes LIKE 'antecedencia_confirmacao_horas'");
            $temColuna = (bool)$st->fetch(PDO::FETCH_ASSOC);
            if (!$temColuna) {
                $this->pdo->exec("ALTER TABLE whatsapp_configuracoes ADD COLUMN antecedencia_confirmacao_horas INT NULL DEFAULT 24 AFTER enviar_lembrete");
            }
        } catch (Throwable $e) {
            error_log('whatsapp_configuracoes schema check: ' . $e->getMessage());
        }

        try {
            $st = $this->pdo->query("SHOW COLUMNS FROM whatsapp_configuracoes LIKE 'antecedencia_minima_mesmo_dia_horas'");
            $temColuna = (bool)$st->fetch(PDO::FETCH_ASSOC);
            if (!$temColuna) {
                $this->pdo->exec("ALTER TABLE whatsapp_configuracoes ADD COLUMN antecedencia_minima_mesmo_dia_horas INT NULL DEFAULT 1 AFTER antecedencia_lembrete_horas");
            }
        } catch (Throwable $e) {
            error_log('whatsapp_configuracoes antecedencia_minima_mesmo_dia_horas check: ' . $e->getMessage());
        }

        $feito = true;
    }

    private function processarDescadastroNovidades(int $empresaId, string $telefone, string $texto): bool
    {
        $comando = mb_strtoupper(trim($texto), 'UTF-8');
        if (!in_array($comando, ['SAIR', 'PARAR', 'DESCADASTRAR'], true)) {
            return false;
        }
        $numero = preg_replace('/\D+/', '', $telefone) ?: '';
        if ($numero === '') return false;

        $normalizado = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.celular,''),' ',''),'(',''),')',''),'-',''),'+',''),'.','')";
        $st = $this->pdo->prepare("SELECT p.id, cp.id preferencia_id
            FROM pacientes p
            JOIN comunicacao_preferencias cp ON cp.empresa_id=p.empresa_id AND cp.paciente_id=p.id
            WHERE p.empresa_id=:empresa_id AND COALESCE(p.excluido,0)=0
              AND RIGHT({$normalizado}, 11)=RIGHT(:telefone, 11) LIMIT 1");
        $st->execute([':empresa_id'=>$empresaId, ':telefone'=>$numero]);
        $paciente = $st->fetch(PDO::FETCH_ASSOC);
        if (!$paciente) return false;

        $this->pdo->prepare("UPDATE comunicacao_preferencias
            SET aceita_educativo=0, aceita_promocional=0, revogado_em=NOW(),
                motivo_revogacao='Solicitado pelo WhatsApp', atualizado_em=NOW()
            WHERE id=:id AND empresa_id=:empresa_id")
            ->execute([':id'=>$paciente['preferencia_id'], ':empresa_id'=>$empresaId]);
        $this->pdo->prepare("INSERT INTO comunicacao_consentimento_eventos
            (empresa_id,paciente_id,preferencia_id,finalidade,canal,acao,origem,motivo,detalhes_json,ocorrido_em)
            VALUES (:empresa_id,:paciente_id,:preferencia_id,'novidades_conteudos','whatsapp','revogou','paciente',
                    'Solicitado pelo WhatsApp',:detalhes,NOW())")
            ->execute([
                ':empresa_id'=>$empresaId, ':paciente_id'=>$paciente['id'],
                ':preferencia_id'=>$paciente['preferencia_id'],
                ':detalhes'=>json_encode(['comando'=>$comando], JSON_UNESCAPED_UNICODE),
            ]);
        return true;
    }

    private function interpretarConsentimentoNovidades(string $texto): string
    {
        $resposta = mb_strtoupper(trim($texto), 'UTF-8');
        if (in_array($resposta, ['SIM', 'S', 'QUERO', 'ACEITO', 'AUTORIZO'], true)) return 'sim';
        if (in_array($resposta, ['NÃO', 'NAO', 'N', 'NÃO QUERO', 'NAO QUERO', 'RECUSO'], true)) return 'nao';
        return '';
    }

    private function salvarConsentimentoNovidadesPorTelefone(int $empresaId, string $telefone, bool $aceita): void
    {
        $numero = preg_replace('/\D+/', '', $telefone) ?: '';
        $normalizado = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.celular,''),' ',''),'(',''),')',''),'-',''),'+',''),'.','')";
        $st = $this->pdo->prepare("SELECT p.id, cp.id preferencia_id
            FROM pacientes p
            JOIN comunicacao_preferencias cp ON cp.empresa_id=p.empresa_id AND cp.paciente_id=p.id
            WHERE p.empresa_id=:empresa_id AND COALESCE(p.excluido,0)=0
              AND RIGHT({$normalizado},11)=RIGHT(:telefone,11) LIMIT 1");
        $st->execute([':empresa_id'=>$empresaId, ':telefone'=>$numero]);
        $paciente = $st->fetch(PDO::FETCH_ASSOC);
        if (!$paciente) throw new RuntimeException('Paciente da conversa não encontrado.');

        $this->pdo->prepare('UPDATE comunicacao_preferencias
            SET aceita_educativo=:aceita_educativo, aceita_promocional=:aceita_promocional,
                consentido_em=IF(:aceita_consentimento=1,NOW(),consentido_em),
                revogado_em=IF(:aceita_revogacao=0,NOW(),NULL), origem_consentimento=\'whatsapp\',
                motivo_revogacao=IF(:aceita_motivo=0,\'Recusado na mensagem de boas-vindas\',NULL), atualizado_em=NOW()
            WHERE id=:id AND empresa_id=:empresa_id')
            ->execute([
                ':aceita_educativo'=>$aceita ? 1 : 0, ':aceita_promocional'=>$aceita ? 1 : 0,
                ':aceita_consentimento'=>$aceita ? 1 : 0, ':aceita_revogacao'=>$aceita ? 1 : 0,
                ':aceita_motivo'=>$aceita ? 1 : 0, ':id'=>$paciente['preferencia_id'], ':empresa_id'=>$empresaId,
            ]);
        $this->pdo->prepare("INSERT INTO comunicacao_consentimento_eventos
            (empresa_id,paciente_id,preferencia_id,finalidade,canal,acao,origem,motivo,detalhes_json,ocorrido_em)
            VALUES (:empresa_id,:paciente_id,:preferencia_id,'novidades_conteudos','whatsapp',:acao,'paciente',:motivo,:detalhes,NOW())")
            ->execute([
                ':empresa_id'=>$empresaId, ':paciente_id'=>$paciente['id'], ':preferencia_id'=>$paciente['preferencia_id'],
                ':acao'=>$aceita ? 'consentiu' : 'revogou',
                ':motivo'=>$aceita ? 'Resposta SIM à mensagem de boas-vindas' : 'Resposta NÃO à mensagem de boas-vindas',
                ':detalhes'=>json_encode(['fluxo'=>'boas_vindas'], JSON_UNESCAPED_UNICODE),
            ]);
    }

    private function garantirSchemaAgendamentos(): void
    {
        static $feito = false;
        if ($feito) {
            return;
        }

        try {
            $st = $this->pdo->query("SHOW COLUMNS FROM agendamentos LIKE 'enviar_whatsapp'");
            $temColuna = (bool)$st->fetch(PDO::FETCH_ASSOC);
            if (!$temColuna) {
                $this->pdo->exec("ALTER TABLE agendamentos ADD COLUMN enviar_whatsapp TINYINT(1) NOT NULL DEFAULT 1 AFTER telefone");
            }
        } catch (Throwable $e) {
            error_log('agendamentos enviar_whatsapp schema check: ' . $e->getMessage());
        }

        $feito = true;
    }

}
