<?php

declare(strict_types=1);

final class TelefoneNormalizer
{
    /** @return list<string> */
    public static function variantesBrasil(string $telefone): array
    {
        $digitos = preg_replace('/\D+/', '', $telefone) ?? '';
        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        }

        if (strlen($digitos) === 10 || strlen($digitos) === 11) {
            $nacional = $digitos;
        } elseif ((strlen($digitos) === 12 || strlen($digitos) === 13) && str_starts_with($digitos, '55')) {
            $nacional = substr($digitos, 2);
        } else {
            throw new InvalidArgumentException('TELEFONE_INVALIDO');
        }

        $variantesNacionais = [$nacional];
        $ddd = substr($nacional, 0, 2);
        $numero = substr($nacional, 2);

        // Cadastros antigos e alguns provedores de WhatsApp podem representar
        // o mesmo celular brasileiro com ou sem o nono digito.
        if (strlen($numero) === 9 && str_starts_with($numero, '9')) {
            $variantesNacionais[] = $ddd . substr($numero, 1);
        } elseif (strlen($numero) === 8) {
            $variantesNacionais[] = $ddd . '9' . $numero;
        }

        $variantes = [];
        foreach ($variantesNacionais as $variante) {
            $variantes[] = $variante;
            $variantes[] = '55' . $variante;
        }

        return array_values(array_unique($variantes));
    }

    public static function somenteDigitosSql(string $coluna): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL({$coluna}, ''), '+', ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', '')";
    }
}
