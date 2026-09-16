<?php

namespace App\Http\V1\Controllers;

use App\Domain\Precio;
use App\Models\Empresa;
use App\Soporte\ContextoRls;
use App\Soporte\Facturador;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Los datos de la empresa tal como salen impresos en un documento: el membrete.
 *
 * Es lo que la hoja de factura pone arriba a la izquierda, y también lo que
 * necesita saber para proponer el próximo folio y la tasa de impuesto de un
 * renglón escrito a mano (FAC-01, FAC-04).
 */
class EmpresaController extends Controller
{
    public function ver(): JsonResponse
    {
        /** @var Empresa $empresa */
        $empresa = Empresa::query()->findOrFail(ContextoRls::empresaActual());

        $serie = Facturador::serieDe('factura');
        $desglose = $empresa->impuesto_desglose ?? [];

        return response()->json([
            'id' => $empresa->ulid,
            'nombre' => $empresa->nombre(),
            'nombre_legal' => $empresa->nombre_legal,
            'registro_comerciante' => $empresa->registro_comerciante,
            'telefono' => $empresa->telefono,
            'email' => $empresa->email,
            'direccion' => $empresa->direccion_fisica,
            'pais' => $empresa->pais,
            'moneda' => $empresa->moneda,
            'logo' => $empresa->logo_ruta,
            'impuesto_desglose' => array_map(fn ($c) => [
                'nombre' => $c['nombre'],
                'tasa' => Precio::tasaATexto((int) $c['milesimas']),
            ], $desglose),
            // La suma de los componentes: lo que se le cobra a un renglón que
            // no viene del catálogo.
            'impuesto_tasa' => Precio::tasaATexto((int) array_sum(array_column($desglose, 'milesimas'))),
            'serie' => [
                'nombre' => $serie->nombre,
                // El folio que le tocaría a la próxima factura. Es una pista
                // para la hoja, no una reserva: el número de verdad se saca al
                // emitir, con la fila bloqueada (ARQ-08).
                'proximo_folio' => $serie->folio($serie->proximo_numero),
            ],
        ]);
    }
}
