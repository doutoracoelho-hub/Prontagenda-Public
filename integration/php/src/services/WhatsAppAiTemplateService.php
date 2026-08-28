<?php

declare(strict_types=1);

final class WhatsAppAiTemplateService
{
    /** @param list<array{id:int,nome:string}> $profissionais */
    public static function profissionais(array $profissionais): string
    {
        if ($profissionais === []) {
            return 'Essa parte precisa ser vista pela nossa equipe. Vou deixar sua mensagem para continuarem o atendimento por aqui.';
        }
        $linhas = ['Com qual profissional você gostaria de agendar?'];
        foreach ($profissionais as $indice => $profissional) {
            $linhas[] = ($indice + 1) . '. ' . $profissional['nome'];
        }
        $linhas[] = '';
        $linhas[] = 'Qual deles você prefere? Pode responder com o nome ou o número.';
        return implode("\n", $linhas);
    }

    /** @param list<array{dia_inicio:string,dia_fim:string,hora_inicio:string,hora_fim:string}> $faixas */
    public static function expediente(string $nomeProfissional, array $faixas): string
    {
        return self::resumoExpediente($nomeProfissional, $faixas)
            . "\n\nQual dia e horário é melhor para você?";
    }

    /** @param list<array{dia_inicio:string,dia_fim:string,hora_inicio:string,hora_fim:string}> $faixas */
    public static function resumoExpediente(string $nomeProfissional, array $faixas): string
    {
        return self::formatarExpediente($nomeProfissional, $faixas);
    }

    /** @param list<array{dia_inicio:string,dia_fim:string,hora_inicio:string,hora_fim:string}> $faixas */
    public static function horarioForaExpediente(string $nomeProfissional, array $faixas): string
    {
        return "Nesse horário não temos atendimento.\n\n"
            . self::formatarExpediente($nomeProfissional, $faixas)
            . "\n\nQual dia e horário é melhor para você?";
    }

    public static function proximaDisponibilidade(string $profissional, ?array $preferencia, ?array $alternativa, array $alternativas = []): string
    {
        if ($preferencia === null && $alternativa === null) {
            return 'Poxa, a agenda da Dra. ' . self::primeiroNome($profissional)
                . ' está cheia nesse período. Posso deixar sua mensagem para nossa equipe verificar outras possibilidades.';
        }
        $partes = [];
        $primeiroNome = self::primeiroNome($profissional);
        if ($preferencia !== null) {
            $dia = self::diaSemana($preferencia['data']);
            $partes[] = 'Na ' . $dia . ', às ' . self::horaNatural($preferencia['hora'])
                . ', tenho horário para a Dra. ' . $primeiroNome . ' no dia '
                . self::dataPtBr($preferencia['data']) . '.';
        } else {
            $partes[] = 'Poxa, não encontrei um horário livre exatamente como você pediu.';
        }
        $opcoes = $alternativas;
        if ($opcoes === [] && $alternativa !== null) $opcoes = [$alternativa];
        if ($opcoes !== []) {
            $formatadas = array_map(
                static fn(array $vaga): string => self::dataPtBr($vaga['data']) . ' às ' . self::horaNatural($vaga['hora']),
                array_slice($opcoes, 0, 2)
            );
            $partes[] = 'Os primeiros horários livres que encontrei foram ' . self::listaNatural($formatadas) . '.';
        }
        $partes[] = 'Qual deles fica melhor? Se preferir, também posso deixar sua mensagem para a equipe.';
        return implode("\n\n", $partes);
    }

    public static function confirmarOpcao(string $profissional, string $dataHora): string
    {
        $data = new DateTimeImmutable($dataHora);
        return 'Perfeito! A vaga selecionada é dia ' . $data->format('d/m/Y') . ' às '
            . self::horaNatural($data->format('H:i')) . ' com a Dra. '
            . self::primeiroNome($profissional) . ".\n\nPosso confirmar?";
    }

    public static function confirmarDestinatario(?string $nomeTitular): string
    {
        $nomeTitular = trim((string)$nomeTitular);
        if ($nomeTitular !== '') {
            return 'Antes de confirmar, essa consulta é para você, ' . $nomeTitular . '?';
        }
        return 'Antes de confirmar, essa consulta é para você ou para outra pessoa?';
    }

    public static function solicitarNomeAtendido(): string
    {
        return 'Qual é o nome completo da pessoa que será atendida?';
    }

    /** @param list<string> $horarios */
    public static function perguntarHorarioDia(string $dia, array $horarios): string
    {
        $horarios = array_values(array_unique(array_filter(array_map('trim', $horarios))));
        if (count($horarios) >= 2) {
            $ultima = array_pop($horarios);
            return 'Ok, dia ' . $dia . '. E a hora, você prefere '
                . implode(', ', $horarios) . ' ou ' . $ultima . '?';
        }
        return 'Ok, dia ' . $dia . '. E qual horário você prefere?';
    }

    public static function confirmarOpcaoComPaciente(
        string $pacienteNome,
        string $profissional,
        string $dataHora
    ): string {
        $data = new DateTimeImmutable($dataHora);
        return 'Só confirmando: a consulta será para ' . trim($pacienteNome)
            . ', com a Dra. ' . self::primeiroNome($profissional)
            . ', no dia ' . $data->format('d/m/Y') . ' às '
            . self::horaNatural($data->format('H:i')) . '. Posso confirmar?';
    }

    public static function preferenciaUnica(string $profissional, array $preferencia): string
    {
        return 'Na ' . self::diaSemana((string)$preferencia['data']) . ', às '
            . self::horaNatural((string)$preferencia['hora'])
            . ', tenho horário para a Dra. ' . self::primeiroNome($profissional)
            . ' no dia ' . self::dataPtBr((string)$preferencia['data']) . '. Pode ser?';
    }

    public static function horarioForaGrade(
        string $profissional,
        string $horarioSolicitado,
        int $intervaloMinutos,
        ?array $proximaVaga
    ): string {
        $mensagem = 'Os horários da Dra. ' . self::primeiroNome($profissional)
            . ' são disponibilizados em intervalos de ' . $intervaloMinutos
            . ' minutos, por isso não temos uma vaga às ' . self::horaNatural($horarioSolicitado) . '.';
        if ($proximaVaga === null) {
            return $mensagem . "\n\nNão encontrei outra vaga disponível próxima desse horário. Qual outro horário funciona para você?";
        }
        return $mensagem . "\n\nO horário disponível mais próximo é no dia "
            . self::dataPtBr($proximaVaga['data']) . ' às '
            . self::horaNatural($proximaVaga['hora'])
            . '. Esse horário funciona para você?';
    }

    public static function agendamentoConfirmado(string $profissional, string $dataHora): string
    {
        $data = new DateTimeImmutable($dataHora);
        return 'Pronto, ficou marcado. Sua consulta com a Dra. ' . self::primeiroNome($profissional)
            . ' será no dia ' . $data->format('d/m/Y') . ' às '
            . self::horaNatural($data->format('H:i')) . '.';
    }

    /** @param list<string> $horarios */
    public static function horariosData(string $profissional, string $data, array $horarios, array $alternativas = []): string
    {
        if ($horarios === []) {
            if ($alternativas === []) {
                return 'Poxa, nesse dia a agenda já está cheia. Posso procurar um horário próximo para você ou prefere que eu encaminhe diretamente para a equipe?';
            }
            $formatadas = array_map(
                static fn(array $vaga): string => self::dataPtBr($vaga['data']) . ' às ' . self::horaNatural($vaga['hora']),
                array_slice($alternativas, 0, 2)
            );
            return 'Poxa, nesse dia a agenda já está cheia. Os primeiros horários livres que encontrei foram '
                . self::listaNatural($formatadas)
                . '. Algum deles serve? Se preferir, posso encaminhar diretamente para a equipe.';
        }
        return 'Os horários livres com ' . $profissional . ' em ' . self::dataPtBr($data)
            . ' são: ' . implode(', ', array_slice($horarios, 0, 8)) . '. Qual deles fica melhor?';
    }

    /** @param list<array<string,mixed>> $agendamentos */
    public static function agendamentos(array $agendamentos): string
    {
        if ($agendamentos === []) {
            return 'Não consegui localizar uma consulta futura por aqui.';
        }
        $linhas = ['Encontrei as seguintes consultas:'];
        foreach ($agendamentos as $indice => $item) {
            $linhas[] = ($indice + 1) . '. ' . self::dataPtBr($item['data']) . ' às ' . $item['hora_inicio']
                . ', com ' . $item['profissional_nome'] . ' — status: ' . $item['status'] . '.';
        }
        return implode("\n", $linhas);
    }

    public static function identidade(string $nome): string
    {
        return 'Esta conversa está vinculada ao cadastro de ' . $nome . '.';
    }

    private static function dataPtBr(string $data): string
    {
        $valor = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        return $valor ? $valor->format('d/m/Y') : $data;
    }

    private static function primeiroNome(string $nomeProfissional): string
    {
        $nome = preg_replace('/^(?:dra?\.?|prof(?:a|essora)?\.?)\s+/iu', '', trim($nomeProfissional)) ?? trim($nomeProfissional);
        return preg_split('/\s+/u', $nome, -1, PREG_SPLIT_NO_EMPTY)[0] ?? $nome;
    }

    private static function diaSemana(string $data): string
    {
        $valor = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$valor) return 'data escolhida';
        $dias = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
        return $dias[(int)$valor->format('N')];
    }

    private static function horaNatural(string $hora): string
    {
        $partes = explode(':', substr($hora, 0, 5));
        $h = (int)($partes[0] ?? 0);
        $m = (int)($partes[1] ?? 0);
        return $m === 0 ? $h . 'h' : sprintf('%02d:%02d', $h, $m);
    }

    /** @param list<string> $itens */
    private static function listaNatural(array $itens): string
    {
        if (count($itens) <= 1) return $itens[0] ?? '';
        $ultimo = array_pop($itens);
        return implode(', ', $itens) . ' ou ' . $ultimo;
    }

    /** @param list<array{dia_inicio:string,dia_fim:string,hora_inicio:string,hora_fim:string}> $faixas */
    private static function formatarExpediente(string $nomeProfissional, array $faixas): string
    {
        $primeiroNome = self::primeiroNome($nomeProfissional);
        if ($faixas === []) {
            return 'Não há expediente configurado para a Dra. ' . $primeiroNome . '.';
        }

        $linhas = ['A Dra. ' . $primeiroNome . ' atende:'];
        foreach ($faixas as $faixa) {
            $dias = $faixa['dia_inicio'] === $faixa['dia_fim']
                ? ucfirst($faixa['dia_inicio'])
                : 'De ' . $faixa['dia_inicio'] . ' a ' . $faixa['dia_fim'];
            $linhas[] = $dias . ', das ' . $faixa['hora_inicio'] . ' às ' . $faixa['hora_fim'] . '.';
        }
        return implode("\n\n", $linhas);
    }
}
