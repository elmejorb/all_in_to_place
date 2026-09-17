<?php

namespace App\Domain;

/**
 * La aritmética de la hoja de cuadre (CAJ-11). Sin framework ni base de datos.
 *
 * Es el cierre que la panadería lleva a mano por turno: se anota lo que había
 * en la gaveta al empezar, lo que marcó la registradora, lo que se cobró con
 * tarjeta y con ATH Móvil, lo que se aparta para el cambio de mañana y lo que
 * se gastó. De ahí sale cuánto efectivo se deposita.
 *
 *   Total venta y cambio = efectivo al comienzo + ventas según lectura
 *   Total efectivo       = total venta y cambio − efectivo para cambio
 *   Gastos               = suma de compras y gastos
 *   A depositar          = total efectivo − gastos
 *   Total ventas         = a depositar + tarjeta + ATH Móvil
 *
 * **La lectura es solo el efectivo.** Lo cobrado con tarjeta y con ATH Móvil va
 * aparte y no se resta de la gaveta: se suma al final para llegar al total
 * vendido. Las fórmulas están comprobadas contra el sistema actual con las
 * cifras que hay en `CuadreTest`, así que una hoja hecha aquí da exactamente lo
 * mismo que la de allá.
 *
 * Un apunte que conviene tener presente: el *total de ventas* así calculado
 * arrastra el fondo de cambio del comienzo y descuenta los gastos, de modo que
 * no coincide con la suma limpia de lo vendido. Se reproduce tal cual porque es
 * la cifra con la que la panadería lleva años comparando (docs/16).
 *
 * Todo en centavos enteros (ARQ-09). Los resultados pueden salir negativos y
 * se devuelven tal cual: un depósito en negativo es justo lo que hay que ver.
 */
final class Cuadre
{
    /**
     * @param  array{efectivo_inicial: int, ventas_lectura: int, efectivo_cambio: int, tarjeta: int, ath_movil: int}  $hoja
     * @param  list<int>  $gastos  montos en centavos
     * @return array{venta_y_cambio: int, total_efectivo: int, gastos: int, a_depositar: int, total_ventas: int}
     */
    public static function calcular(array $hoja, array $gastos = []): array
    {
        $inicial = (int) ($hoja['efectivo_inicial'] ?? 0);
        $lectura = (int) ($hoja['ventas_lectura'] ?? 0);
        $cambio = (int) ($hoja['efectivo_cambio'] ?? 0);
        $tarjeta = (int) ($hoja['tarjeta'] ?? 0);
        $athMovil = (int) ($hoja['ath_movil'] ?? 0);

        $ventaYCambio = $inicial + $lectura;
        $totalEfectivo = $ventaYCambio - $cambio;
        $totalGastos = array_sum(array_map('intval', $gastos));
        $aDepositar = $totalEfectivo - $totalGastos;

        return [
            'venta_y_cambio' => $ventaYCambio,
            'total_efectivo' => $totalEfectivo,
            'gastos' => $totalGastos,
            'a_depositar' => $aDepositar,
            // Lo que se deposita más lo que no pasó por la gaveta.
            'total_ventas' => $aDepositar + $tarjeta + $athMovil,
        ];
    }

    /**
     * Lo escrito a mano frente a lo que el sistema facturó (CAJ-02, CAJ-12).
     *
     * No corrige nada ni rellena nada: la hoja se sigue escribiendo a mano,
     * porque eso es justamente lo que la hace servir de control. Esto solo dice
     * en cuánto difieren, para que se vea en el momento y no tres días después.
     *
     * @param  array<string, int>  $declarado  lo que se escribió, en centavos
     * @param  array<string, int>  $facturado  lo que el sistema tiene, en centavos
     * @return array<string, array{declarado: int, facturado: int, diferencia: int, cuadra: bool}>
     */
    public static function comparar(array $declarado, array $facturado): array
    {
        $comparacion = [];

        foreach ($facturado as $concepto => $delSistema) {
            $aMano = (int) ($declarado[$concepto] ?? 0);

            $comparacion[$concepto] = [
                'declarado' => $aMano,
                'facturado' => $delSistema,
                'diferencia' => $aMano - $delSistema,
                'cuadra' => $aMano === $delSistema,
            ];
        }

        return $comparacion;
    }
}
