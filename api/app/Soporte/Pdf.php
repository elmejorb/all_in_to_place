<?php

namespace App\Soporte;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Convierte HTML en un PDF (CAJ-07, y más adelante FAC-10).
 *
 * El HTML del PDF no es el de la pantalla y no debe serlo: dompdf no entiende
 * flex ni grid, así que las plantillas de `resources/views/pdf` se maquetan con
 * tablas, a la antigua. Intentar reaprovechar los estilos de la aplicación aquí
 * produce un documento descuadrado, y un papel torcido es peor que uno sobrio.
 *
 * Va sin acceso a la red ni a archivos remotos: un PDF nunca debería poder
 * traerse nada de fuera por lo que diga su contenido (SEG-13).
 */
final class Pdf
{
    public static function de(string $html, string $tamano = 'letter', string $orientacion = 'portrait'): string
    {
        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isPhpEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');   // trae los acentos y la ñ
        $opciones->set('chroot', resource_path('views/pdf'));

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($tamano, $orientacion);
        $dompdf->render();

        return $dompdf->output();
    }

    /** Un nombre de archivo que se pueda guardar en cualquier sistema. */
    public static function nombre(string ...$partes): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9\-_]+/', '-', implode('-', array_filter($partes)));

        return trim((string) $limpio, '-').'.pdf';
    }
}
