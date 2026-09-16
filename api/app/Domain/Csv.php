<?php

namespace App\Domain;

/**
 * Lectura y escritura de CSV pensada para Excel en español.
 *
 * Tres cosas que se dan por hechas y no lo son:
 *  - Excel en español guarda con punto y coma, no con coma. Se detecta.
 *  - Los archivos que salen de Excel suelen venir en Windows-1252, no en UTF-8.
 *  - Sin BOM, Excel abre un UTF-8 y muestra "Panadería" como "PanaderÃ­a".
 */
final class Csv
{
    public const BOM = "\xEF\xBB\xBF";

    /** Separador más probable según la primera línea con contenido. */
    public static function separador(string $contenido): string
    {
        $primera = strtok($contenido, "\n") ?: '';

        return substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
    }

    /**
     * Devuelve las filas como arreglos asociativos por encabezado.
     *
     * @return array{encabezados: list<string>, filas: list<array<string, string>>}
     */
    public static function leer(string $contenido): array
    {
        // Fuera el BOM, que si no el primer encabezado nunca coincide.
        if (str_starts_with($contenido, self::BOM)) {
            $contenido = substr($contenido, strlen(self::BOM));
        }

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $separador = self::separador($contenido);
        $lineas = preg_split("/\r\n|\n|\r/", $contenido) ?: [];

        $encabezados = [];
        $filas = [];

        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }

            $campos = str_getcsv($linea, $separador, '"', '\\');

            if ($encabezados === []) {
                $encabezados = array_map(self::normalizarEncabezado(...), $campos);
                continue;
            }

            $fila = [];
            foreach ($encabezados as $i => $encabezado) {
                if ($encabezado === '') {
                    continue;
                }
                $fila[$encabezado] = trim((string) ($campos[$i] ?? ''));
            }

            $filas[] = $fila;
        }

        return ['encabezados' => $encabezados, 'filas' => $filas];
    }

    /** "Precio de Venta" y "precio_venta" tienen que llegar al mismo sitio. */
    public static function normalizarEncabezado(string $texto): string
    {
        $texto = trim(mb_strtolower($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $texto = preg_replace('/[^a-z0-9]+/', '_', $texto) ?? '';

        return trim($texto, '_');
    }

    /**
     * Arma un CSV con BOM y punto y coma, que es lo que Excel en español abre
     * de un doble clic sin pedir nada.
     *
     * @param  list<string>  $encabezados
     * @param  list<list<string|int|float|null>>  $filas
     */
    public static function escribir(array $encabezados, array $filas, string $separador = ';'): string
    {
        $salida = fopen('php://temp', 'r+');
        fputcsv($salida, $encabezados, $separador, '"', '\\');

        foreach ($filas as $fila) {
            fputcsv($salida, $fila, $separador, '"', '\\');
        }

        rewind($salida);
        $contenido = stream_get_contents($salida) ?: '';
        fclose($salida);

        return self::BOM.$contenido;
    }
}
