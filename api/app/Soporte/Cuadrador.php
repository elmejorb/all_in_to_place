<?php

namespace App\Soporte;

use App\Models\Documento;
use App\Models\Empresa;
use App\Models\GastoCuadre;
use App\Models\HojaCuadre;
use App\Models\Pago;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Guardar una hoja de cuadre y averiguar qué dice el sistema de ese turno.
 */
final class Cuadrador
{
    /** El turno de mañana va hasta el mediodía; el de tarde, de ahí en adelante. */
    public const CORTE = 12;

    /**
     * Guarda la hoja con sus gastos. Los gastos se reescriben enteros: son
     * cuatro renglones de una hoja, no un historial que preservar.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function guardar(array $datos, ?HojaCuadre $hoja = null): HojaCuadre
    {
        return DB::transaction(function () use ($datos, $hoja) {
            $campos = [
                'fecha' => $datos['fecha'],
                'turno' => $datos['turno'],
                'efectivo_inicial_centavos' => $datos['efectivo_inicial'],
                'ventas_lectura_centavos' => $datos['ventas_lectura'],
                'efectivo_cambio_centavos' => $datos['efectivo_cambio'],
                'tarjeta_centavos' => $datos['tarjeta'],
                'ath_movil_centavos' => $datos['ath_movil'],
                'notas' => $datos['notas'] ?? null,
            ];

            if ($hoja) {
                $hoja->update($campos);
            } else {
                $hoja = HojaCuadre::create($campos + ['usuario_id' => Auth::guard('empresa')->id()]);
            }

            $hoja->gastos()->delete();

            foreach (array_values($datos['gastos'] ?? []) as $i => $gasto) {
                GastoCuadre::create([
                    'hoja_id' => $hoja->id,
                    'orden' => $i,
                    'descripcion' => $gasto['descripcion'],
                    'monto_centavos' => $gasto['monto'],
                ]);
            }

            return $hoja->fresh(['gastos', 'usuario']);
        });
    }

    /**
     * Lo que el sistema facturó en ese turno, para ponerlo al lado de lo que se
     * escribió a mano (CAJ-12).
     *
     * Las facturas anuladas no cuentan: no se cobraron. Y las fechas se miran
     * en la zona horaria de la empresa, no en la del servidor, porque un turno
     * de tarde en Puerto Rico ya es del día siguiente en UTC.
     *
     * @return array{ventas: int, tarjeta: int, ath_movil: int, efectivo: int, facturas: int}
     */
    public static function loFacturado(string $fecha, string $turno): array
    {
        [$desde, $hasta] = self::ventana($fecha, $turno);

        // Con el desfase incluido ("2026-09-17T00:00:00-04:00"). Si se pasa la
        // fecha a secas, Postgres la interpreta en la zona de su sesión y la
        // ventana se corre unas horas: el turno de la mañana acababa buscando
        // facturas de la madrugada anterior.
        $documentos = Documento::query()
            ->where('tipo', 'factura')
            ->where('estado', '!=', 'anulada')
            ->whereBetween('emitida_en', [$desde->toIso8601String(), $hasta->toIso8601String()]);

        $ventas = (int) (clone $documentos)->sum('total_centavos');
        $cuantas = (clone $documentos)->count();

        $porMetodo = Pago::query()
            ->whereIn('documento_id', (clone $documentos)->select('id'))
            ->selectRaw('metodo, coalesce(sum(monto_centavos), 0) as total')
            ->groupBy('metodo')
            ->pluck('total', 'metodo');

        return [
            'ventas' => $ventas,
            'tarjeta' => (int) ($porMetodo['tarjeta'] ?? 0),
            'ath_movil' => (int) ($porMetodo['ath_movil'] ?? 0),
            'efectivo' => (int) ($porMetodo['efectivo'] ?? 0),
            'facturas' => $cuantas,
        ];
    }

    /**
     * De cuándo a cuándo va un turno, en hora de la empresa.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function ventana(string $fecha, string $turno): array
    {
        $zona = self::zonaHoraria();
        $dia = Carbon::parse($fecha, $zona)->startOfDay();

        return $turno === 'am'
            ? [$dia, (clone $dia)->setTime(self::CORTE, 0)->subSecond()]
            : [(clone $dia)->setTime(self::CORTE, 0), (clone $dia)->endOfDay()];
    }

    /** El turno en el que se está ahora mismo, en hora de la empresa. */
    public static function turnoActual(): string
    {
        return Carbon::now(self::zonaHoraria())->hour < self::CORTE ? 'am' : 'pm';
    }

    public static function hoy(): string
    {
        return Carbon::now(self::zonaHoraria())->toDateString();
    }

    private static function zonaHoraria(): string
    {
        $id = ContextoRls::empresaActual();

        return ($id ? Empresa::query()->find($id)?->zona_horaria : null) ?: config('app.timezone');
    }
}
