<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/services/WhatsAppInboundRouterService.php';

$classe = new ReflectionClass(WhatsAppInboundRouterService::class);
$roteador = $classe->newInstanceWithoutConstructor();
$metodo = $classe->getMethod('ehConfirmacaoExplicitaAgendamento');

$casos = [
    // Confirmações explícitas permitidas.
    'sim' => true,
    'Sim, por favor!' => true,
    'confirmo' => true,
    'Pode confirmar?' => true,
    'pode agendar por favor' => true,
    'OK, pode confirmar.' => true,
    'Sim pode ser' => true,
    'Pode ser' => true,
    'ok' => true,
    'certo' => true,
    'beleza' => true,
    'combinado' => true,
    'essa' => true,
    '👍' => true,
    '✅' => true,

    // Respostas genéricas, ambíguas ou referentes a outro assunto.
    'quero' => false,
    'a primeira' => false,
    'tenho outra dúvida' => false,
    'preciso de mais ajuda' => false,
    'obrigada' => false,
    'valeu' => false,

    // Negativas e mudanças de preferência jamais confirmam.
    'não' => false,
    'não confirme' => false,
    'quero outro horário' => false,
    'prefiro quinta' => false,
    'pode cancelar' => false,
];

$falhas = [];
foreach ($casos as $mensagem => $esperado) {
    $obtido = $metodo->invoke($roteador, $mensagem);
    if ($obtido !== $esperado) {
        $falhas[] = sprintf(
            '%s: esperado=%s obtido=%s',
            json_encode($mensagem, JSON_UNESCAPED_UNICODE),
            $esperado ? 'true' : 'false',
            $obtido ? 'true' : 'false'
        );
    }
}

if ($falhas !== []) {
    fwrite(STDERR, "FALHA - confirmação segura de agendamento\n" . implode("\n", $falhas) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("OK - %d mensagens de confirmação verificadas.\n", count($casos)));

$identificarIntencao = $classe->getMethod('identificarIntencao');
$pedidosRemarcacao = [
    'quero trocar o horário',
    'preciso mudar horario',
    'quero remarcar minha consulta',
    'gostaria de reagendar',
    'Bom dia Mônica, Letícia pediu p trocar a data da consulta dela pois dia 1 de setembro ela tem prova e vai ficar o dia todo estudando, pode agendar p depois fazendo o favor na parte da tarde obrigada',
    'preciso alterar o dia do agendamento e pode agendar à tarde',
];
foreach ($pedidosRemarcacao as $mensagem) {
    $intencao = $identificarIntencao->invoke($roteador, $mensagem);
    if ($intencao !== 'remarcar') {
        fwrite(STDERR, sprintf(
            "FALHA - pedido de remarcação não reconhecido: %s => %s\n",
            json_encode($mensagem, JSON_UNESCAPED_UNICODE),
            $intencao
        ));
        exit(1);
    }
}

fwrite(STDOUT, sprintf("OK - %d pedidos de remarcação reconhecidos.\n", count($pedidosRemarcacao)));

$casosIntencao = [
    'agendar' => 'agendar',
    'marcar' => 'agendar',
    'tem horário' => 'consultar_horarios',
    'trocar' => 'remarcar',
    'reagendar' => 'remarcar',
    'não posso ir' => 'cancelar',
    'Boa tarde, poderia marcar pra dia 24' => 'agendar',
    'Pois vou no médico e já aproveitava' => 'nao_entendido',
    'oi' => 'saudacao',
    'Bom dia!' => 'saudacao',
    'boa tarde' => 'saudacao',
    'Oi Mônica, boa tarde!' => 'saudacao',
    'Bom dia, Dra. Mônica!' => 'saudacao',
    'Boa tarde, quero marcar uma consulta' => 'agendar',
    '...' => 'saudacao',
    '?' => 'saudacao',
    'quero marcar para o dia 24' => 'agendar',
    'Queria marcar um horário pra mim' => 'agendar',
    'quero trocar para o dia 24' => 'remarcar',
    'não vou poder ir' => 'cancelar',
    'quero mbarcar uma consulta' => 'nao_entendido',
];
foreach ($casosIntencao as $mensagem => $esperado) {
    $obtido = $identificarIntencao->invoke($roteador, $mensagem);
    if ($obtido !== $esperado) {
        fwrite(STDERR, sprintf(
            "FALHA - intenção incorreta: %s => esperado=%s obtido=%s\n",
            json_encode($mensagem, JSON_UNESCAPED_UNICODE),
            $esperado,
            $obtido
        ));
        exit(1);
    }
}

fwrite(STDOUT, sprintf("OK - %d mensagens contextuais e saudações verificadas.\n", count($casosIntencao)));

$ehSolicitacaoClinica = $classe->getMethod('ehSolicitacaoClinica');
foreach ([
    'Boa tarde, posso ir aí? Está machucando do lado da boca.',
    'A prótese está machucando minha boca.',
] as $mensagem) {
    if ($ehSolicitacaoClinica->invoke($roteador, $mensagem) !== true) {
        fwrite(STDERR, "FALHA - solicitação clínica não reconhecida: {$mensagem}\n");
        exit(1);
    }
}
fwrite(STDOUT, "OK - sintomas e problemas com prótese são encaminhados como questão clínica.\n");

$resolverProfissional = $classe->getMethod('resolverProfissionalEscolhido');
$profissionais = [
    ['id' => 10, 'nome' => 'Marratima Simões Coelho'],
    ['id' => 20, 'nome' => 'Monica Simões Coelho Novaes'],
    ['id' => 30, 'nome' => 'Teste'],
];
$escolhasProfissional = [
    ['2', 20],
    ['Monica', 20],
    ['teste', 30],
    ['Simões', null],
    ['4', null],
];
foreach ($escolhasProfissional as [$mensagem, $idEsperado]) {
    $profissional = $resolverProfissional->invoke($roteador, $mensagem, $profissionais);
    $idObtido = $profissional['id'] ?? null;
    if ($idObtido !== $idEsperado) {
        fwrite(STDERR, sprintf(
            "FALHA - escolha de profissional: %s => esperado=%s obtido=%s\n",
            json_encode($mensagem, JSON_UNESCAPED_UNICODE),
            var_export($idEsperado, true),
            var_export($idObtido, true)
        ));
        exit(1);
    }
}
fwrite(STDOUT, sprintf("OK - %d escolhas de profissional verificadas.\n", count($escolhasProfissional)));

$resolverMencaoProfissional = $classe->getMethod('resolverProfissionalMencionado');
$mencoesProfissional = [
    ['Gostaria de marcar uma consulta com a Dra Monica amanhã', 20],
    ['Quero marcar com Marratima', 10],
    ['Quero marcar uma consulta amanhã', null],
    ['Quero marcar com Simões', null],
];
foreach ($mencoesProfissional as [$mensagem, $idEsperado]) {
    $profissional = $resolverMencaoProfissional->invoke($roteador, $mensagem, $profissionais);
    $idObtido = $profissional['id'] ?? null;
    if ($idObtido !== $idEsperado) {
        fwrite(STDERR, "FALHA - profissional mencionado não foi resolvido com segurança: {$mensagem}\n");
        exit(1);
    }
}

$resolverData = $classe->getMethod('dataAgendamentoInformada');
$amanhaEsperado = (new DateTimeImmutable('tomorrow', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
if ($resolverData->invoke($roteador, 'Quero com a Dra Monica amanhã') !== $amanhaEsperado) {
    fwrite(STDERR, "FALHA - data relativa amanhã não foi preservada.\n");
    exit(1);
}
fwrite(STDOUT, "OK - profissional e data informados na primeira mensagem são preservados.\n");

$decidirFluxoConfirmacao = $classe->getMethod('devePermanecerNoFluxoConfirmacao');
$conversaComConsultaConfirmada = [
    'agendamento_id_ativo' => 34288,
    'expira_em' => '2099-12-31 23:59:59',
    'ultimo_tipo_saida' => 'confirmacao',
    'observacoes' => null,
];
$novosAssuntos = [
    ['Boa tarde', 'saudacao'],
    ['Queria marcar um horário', 'agendar'],
    ['Tem horário na quinta?', 'consultar_horarios'],
];
foreach ($novosAssuntos as [$mensagem, $intencao]) {
    $permaneceu = $decidirFluxoConfirmacao->invoke(
        $roteador,
        $conversaComConsultaConfirmada,
        $intencao,
        $mensagem
    );
    if ($permaneceu !== false) {
        fwrite(STDERR, "FALHA - novo assunto ficou preso na confirmação: {$mensagem}\n");
        exit(1);
    }
}
foreach ([
    ['Quero cancelar minha consulta', 'cancelar'],
    ['Quero trocar o horário', 'remarcar'],
] as [$mensagem, $intencao]) {
    $permaneceu = $decidirFluxoConfirmacao->invoke(
        $roteador,
        $conversaComConsultaConfirmada,
        $intencao,
        $mensagem
    );
    if ($permaneceu !== true) {
        fwrite(STDERR, "FALHA - alteração saiu do fluxo da consulta: {$mensagem}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK - consulta anterior não bloqueia saudação ou novo agendamento.\n");

$erroTecnicoProvedor = $classe->getMethod('ehErroTecnicoProvedor');
foreach ([
    'Server Error403 - Forbidden: Access is denied.',
    'Forbidden: Access is denied. Please contact your administrator.',
    '<!DOCTYPE html><html><title>403 Forbidden</title></html>',
] as $mensagemTecnica) {
    if ($erroTecnicoProvedor->invoke($roteador, $mensagemTecnica) !== true) {
        fwrite(STDERR, "FALHA - erro técnico poderia pausar o bot: {$mensagemTecnica}\n");
        exit(1);
    }
}
foreach (['Boa tarde', 'Quero marcar um horário'] as $mensagemHumana) {
    if ($erroTecnicoProvedor->invoke($roteador, $mensagemHumana) !== false) {
        fwrite(STDERR, "FALHA - mensagem humana identificada como erro técnico: {$mensagemHumana}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK - erros técnicos do provedor não pausam a IA.\n");

$respostaAutomatica = $classe->getMethod('textoPareceRespostaAutomaticaContato');
foreach ([
    'Agradecemos sua mensagem. Não estamos disponíveis no momento, mas entraremos em contato assim que possível. Nosso horário de atendimento é de 08 às 16h.',
    'Esta é uma mensagem automática. Estamos fora do horário de atendimento e retornaremos assim que possível.',
] as $mensagemAutomatica) {
    if ($respostaAutomatica->invoke($roteador, $mensagemAutomatica) !== true) {
        fwrite(STDERR, "FALHA - resposta automática não identificada: {$mensagemAutomatica}\n");
        exit(1);
    }
}
foreach ([
    'Não estou disponível hoje, pode ser amanhã?',
    'Qual é o horário de atendimento?',
    'Obrigado por sua mensagem, quero marcar uma consulta.',
] as $mensagemPaciente) {
    if ($respostaAutomatica->invoke($roteador, $mensagemPaciente) !== false) {
        fwrite(STDERR, "FALHA - mensagem do paciente pareceu automática: {$mensagemPaciente}\n");
        exit(1);
    }
}

fwrite(STDOUT, "OK - respostas automáticas do contato são reconhecidas com segurança.\n");

$preferenciaUnica = WhatsAppAiTemplateService::preferenciaUnica(
    'Monica Simões Coelho Novaes',
    ['data' => '2026-10-01', 'hora' => '17:30']
);
if ($preferenciaUnica !== 'Na quinta-feira, às 17:30, tenho horário para a Dra. Monica no dia 01/10/2026. Pode ser?') {
    fwrite(STDERR, "FALHA - resposta de preferência única inesperada: {$preferenciaUnica}\n");
    exit(1);
}

fwrite(STDOUT, "OK - alteração de preferência oferece somente a vaga encontrada.\n");

$agendaCheia = WhatsAppAiTemplateService::horariosData(
    'Dra. Monica',
    '2026-09-01',
    [],
    [
        ['data' => '2026-09-02', 'hora' => '15:30'],
        ['data' => '2026-09-02', 'hora' => '17:00'],
    ]
);
foreach (['02/09/2026 às 15:30', '02/09/2026 às 17h', 'encaminhar diretamente para a equipe'] as $trecho) {
    if (!str_contains($agendaCheia, $trecho)) {
        fwrite(STDERR, "FALHA - resposta de agenda cheia não contém: {$trecho}\n");
        exit(1);
    }
}
if (preg_match('/[\x{1F300}-\x{1FAFF}]/u', $agendaCheia) === 1) {
    fwrite(STDERR, "FALHA - resposta de agenda cheia contém emoji.\n");
    exit(1);
}

fwrite(STDOUT, "OK - agenda cheia oferece duas vagas reais sem emojis.\n");

$agendamentoClasse = new ReflectionClass(WhatsAppAgendamentoService::class);
$agendamento = $agendamentoClasse->newInstanceWithoutConstructor();
$decisaoDestinatario = $agendamentoClasse->getMethod('decisaoDestinatario');
foreach ([
    'sim' => 'titular',
    'Sou eu' => 'titular',
    'é para mim' => 'titular',
    'não, é para outra pessoa' => 'terceiro',
    'é para minha filha' => 'terceiro',
    'pode confirmar' => null,
] as $mensagem => $esperado) {
    $obtido = $decisaoDestinatario->invoke($agendamento, $mensagem);
    if ($obtido !== $esperado) {
        fwrite(STDERR, "FALHA - destinatário incorreto para '{$mensagem}'.\n");
        exit(1);
    }
}

$extrairNome = $agendamentoClasse->getMethod('extrairNomeCompleto');
foreach ([
    ['Maria Aparecida Souza', false, 'Maria Aparecida Souza'],
    ['Não, é para Letícia Almeida', true, 'Letícia Almeida'],
    ['minha filha', false, null],
    ['João', false, null],
] as [$mensagem, $terceiro, $esperado]) {
    $obtido = $extrairNome->invoke($agendamento, $mensagem, $terceiro);
    if ($obtido !== $esperado) {
        fwrite(STDERR, "FALHA - nome do atendido incorreto para '{$mensagem}': " . var_export($obtido, true) . "\n");
        exit(1);
    }
}

$perguntaIdentidade = WhatsAppAiTemplateService::confirmarDestinatario('Maria Aparecida Souza');
if ($perguntaIdentidade !== 'Antes de confirmar, essa consulta é para você, Maria Aparecida Souza?') {
    fwrite(STDERR, "FALHA - pergunta de identidade inesperada.\n");
    exit(1);
}
$resumoFinal = WhatsAppAiTemplateService::confirmarOpcaoComPaciente(
    'Letícia Almeida',
    'Monica Simões Coelho Novaes',
    '2026-10-01 17:30:00'
);
foreach (['Letícia Almeida', 'Dra. Monica', '01/10/2026', '17:30', 'Posso confirmar?'] as $trecho) {
    if (!str_contains($resumoFinal, $trecho)) {
        fwrite(STDERR, "FALHA - resumo final não contém '{$trecho}'.\n");
        exit(1);
    }
}

$fonteRoteador = (string)file_get_contents(__DIR__ . '/../../src/services/WhatsAppInboundRouterService.php');
$mensagemRepetitiva = 'Não entendi bem sua mensagem. Você poderia escrever novamente o que precisa?';
if (str_contains($fonteRoteador, $mensagemRepetitiva)) {
    fwrite(STDERR, "FALHA - mensagem repetitiva de não entendimento ainda está ativa.\n");
    exit(1);
}
$posClinica = strpos($fonteRoteador, 'if ($this->ehSolicitacaoClinica($texto))');
$posNaoEntendido = strpos($fonteRoteador, "\$intencao === 'nao_entendido'");
if ($posClinica === false || $posNaoEntendido === false || $posClinica >= $posNaoEntendido) {
    fwrite(STDERR, "FALHA - solicitação clínica não é tratada antes de mensagem não compreendida.\n");
    exit(1);
}
if (!str_contains($fonteRoteador, "trim((string)(\$conversa['estado_fluxo'] ?? '')) !== 'ia_whatsapp'")) {
    fwrite(STDERR, "FALHA - respostas contextuais do agendamento podem ser encaminhadas antes de chegar à IA.\n");
    exit(1);
}
$posIdentidade = strpos($fonteRoteador, 'temIdentidadePendente($empresaId, $conversaId)');
$posConfirmacao = strpos($fonteRoteador, '$confirmacaoExplicita = $this->ehConfirmacaoExplicitaAgendamento($texto)');
if ($posIdentidade === false || $posConfirmacao === false || $posIdentidade >= $posConfirmacao) {
    fwrite(STDERR, "FALHA - confirmação pode ocorrer antes da identificação do atendido.\n");
    exit(1);
}

fwrite(STDOUT, "OK - identidade do atendido e confirmação em duas etapas verificadas.\n");

$perguntaHora = WhatsAppAiTemplateService::perguntarHorarioDia('01', ['15h', '15:30']);
if ($perguntaHora !== 'Ok, dia 01. E a hora, você prefere 15h ou 15:30?') {
    fwrite(STDERR, "FALHA - pergunta de horário por dia inesperada: {$perguntaHora}\n");
    exit(1);
}

fwrite(STDOUT, "OK - escolha somente do dia pede um dos horários reais.\n");

$pedidoTerceiro = $classe->getMethod('pedidoAgendamentoParaTerceiro');
foreach ([
    'Queria marcar para minha filha',
    'Gostaria de agendar pra outra pessoa',
    'Quero marcar para o meu filho',
] as $mensagem) {
    if ($pedidoTerceiro->invoke($roteador, $mensagem) !== true) {
        fwrite(STDERR, "FALHA - agendamento para terceiro não reconhecido: {$mensagem}\n");
        exit(1);
    }
}

$nomeCompleto = $classe->getMethod('nomeCompletoInformado');
if ($nomeCompleto->invoke($roteador, 'Marratima Simões') !== 'Marratima Simões') {
    fwrite(STDERR, "FALHA - nome completo do paciente não foi preservado.\n");
    exit(1);
}
if ($nomeCompleto->invoke($roteador, 'Marratima') !== null) {
    fwrite(STDERR, "FALHA - nome incompleto foi aceito como identificação final.\n");
    exit(1);
}

fwrite(STDOUT, "OK - paciente de terceiro é identificado antes do profissional.\n");
