<?php

namespace App\Soporte;

use App\Domain\Csv;
use App\Domain\Precio;
use App\Models\Categoria;
use App\Models\Importacion;
use App\Models\Producto;
use App\Models\Suplidor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Importación de catálogo en dos momentos (PRO-07, PRO-08).
 *
 * Primero se revisa: se lee el archivo, se valida fila por fila y se dice qué
 * pasaría con cada una. Nada se guarda todavía. Solo si el usuario confirma se
 * aplica, y se aplica entero o no se aplica nada.
 */
final class ImportadorProductos
{
    /** Encabezados de la plantilla, en el orden en que se descargan. */
    public const COLUMNAS = [
        'nombre' => 'Nombre del producto',
        'sku' => 'Codigo interno',
        'codigo_barras' => 'Codigo de barras',
        'categoria' => 'Categoria',
        'suplidor' => 'Suplidor',
        'unidad' => 'Unidad',
        'costo' => 'Costo',
        'precio' => 'Precio de venta',
        'impuesto' => 'Impuesto %',
        'existencia' => 'Existencia',
        'existencia_minima' => 'Existencia minima',
        'es_servicio' => 'Es servicio (si/no)',
    ];

    /** Nombres alternativos que la gente escribe de verdad. */
    private const SINONIMOS = [
        'nombre_del_producto' => 'nombre',
        'producto' => 'nombre',
        'descripcion_del_producto' => 'nombre',
        'codigo_interno' => 'sku',
        'codigo' => 'sku',
        'sku' => 'sku',
        'codigo_de_barras' => 'codigo_barras',
        'barras' => 'codigo_barras',
        'categoria' => 'categoria',
        'suplidor' => 'suplidor',
        'proveedor' => 'suplidor',
        'unidad' => 'unidad',
        'unidad_de_medida' => 'unidad',
        'costo' => 'costo',
        'precio_de_venta' => 'precio',
        'precio' => 'precio',
        'impuesto' => 'impuesto',
        'impuesto_2' => 'impuesto',
        'tax' => 'impuesto',
        'existencia' => 'existencia',
        'exist' => 'existencia',
        'existencia_minima' => 'existencia_minima',
        'exist_min' => 'existencia_minima',
        'minimo' => 'existencia_minima',
        'es_servicio' => 'es_servicio',
        'es_servicio_si_no' => 'es_servicio',
        'servicio' => 'es_servicio',
    ];

    public static function plantilla(): string
    {
        return Csv::escribir(
            array_values(self::COLUMNAS),
            [
                ['Pan de leche relleno', 'PAN-001', '7451000010015', 'Pasteleria', 'Harinas del Caribe', 'unidad', '5.99', '9.50', '11.5', '120', '20', 'no'],
                ['Bizcocho por encargo', 'ENC-001', '', 'Artesanal', '', 'servicio', '0', '150.00', '11.5', '', '', 'si'],
            ],
        );
    }

    /**
     * Lee el archivo y diagnostica cada fila. No toca la base de datos.
     */
    public static function previsualizar(string $contenido, string $nombreArchivo, bool $actualizarExistentes): Importacion
    {
        $leido = Csv::leer($contenido);
        $filas = [];
        $skusEnArchivo = [];
        $barrasEnArchivo = [];

        // Catálogos de la empresa, por nombre en minúsculas, para no consultar por fila.
        $categorias = Categoria::query()->pluck('id', 'nombre')
            ->mapWithKeys(fn ($id, $nombre) => [mb_strtolower($nombre) => $id])->all();
        $suplidores = Suplidor::query()->get(['id', 'razon_social', 'nombre_comercial'])
            ->flatMap(fn (Suplidor $s) => array_filter([
                mb_strtolower($s->razon_social) => $s->id,
                $s->nombre_comercial ? mb_strtolower($s->nombre_comercial) : null => $s->id,
            ], fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY))->all();

        foreach ($leido['filas'] as $indice => $cruda) {
            $numero = $indice + 2;   // +1 por el encabezado, +1 porque Excel cuenta desde 1
            $fila = self::mapear($cruda);
            $errores = [];

            // --- nombre ---
            if ($fila['nombre'] === '') {
                $errores[] = 'Falta el nombre del producto.';
            } elseif (mb_strlen($fila['nombre']) > 150) {
                $errores[] = 'El nombre no puede pasar de 150 caracteres.';
            }

            $esServicio = self::aBooleano($fila['es_servicio']);

            // --- código interno y de barras ---
            $sku = $fila['sku'];
            $existente = null;

            if ($sku !== '') {
                $llave = mb_strtolower($sku);

                if (isset($skusEnArchivo[$llave])) {
                    $errores[] = "El código interno {$sku} está repetido en la fila {$skusEnArchivo[$llave]} de este mismo archivo.";
                } else {
                    $skusEnArchivo[$llave] = $numero;
                }

                $existente = Producto::query()->whereRaw('lower(sku) = ?', [$llave])->first();

                if ($existente && ! $actualizarExistentes) {
                    $errores[] = "Ya tienes un producto con el código {$sku}. Marca «actualizar los que ya existen» si quieres cambiarlo.";
                }
            }

            if ($fila['codigo_barras'] !== '') {
                if (isset($barrasEnArchivo[$fila['codigo_barras']])) {
                    $errores[] = "El código de barras está repetido en la fila {$barrasEnArchivo[$fila['codigo_barras']]} de este mismo archivo.";
                } else {
                    $barrasEnArchivo[$fila['codigo_barras']] = $numero;
                }

                $duenoDeBarras = Producto::query()->where('codigo_barras', $fila['codigo_barras'])->first();

                if ($duenoDeBarras && (! $existente || $duenoDeBarras->id !== $existente->id)) {
                    $errores[] = "El código de barras ya lo usa «{$duenoDeBarras->nombre}».";
                }
            }

            // --- categoría y suplidor: tienen que existir ya ---
            $categoriaId = null;
            if ($fila['categoria'] !== '') {
                $categoriaId = $categorias[mb_strtolower($fila['categoria'])] ?? null;
                if (! $categoriaId) {
                    $errores[] = "La categoría «{$fila['categoria']}» no existe. Créala antes de importar.";
                }
            }

            $suplidorId = null;
            if ($fila['suplidor'] !== '') {
                $suplidorId = $suplidores[mb_strtolower($fila['suplidor'])] ?? null;
                if (! $suplidorId) {
                    $errores[] = "El suplidor «{$fila['suplidor']}» no existe. Créalo antes de importar.";
                }
            }

            // --- unidad ---
            $unidad = $fila['unidad'] !== '' ? mb_strtolower($fila['unidad']) : ($esServicio ? 'servicio' : 'unidad');
            if (! in_array($unidad, Producto::UNIDADES, true)) {
                $errores[] = "La unidad «{$fila['unidad']}» no es válida. Usa: ".implode(', ', Producto::UNIDADES).'.';
                $unidad = 'unidad';
            }

            // --- dinero ---
            $costo = $fila['costo'] === '' ? 0 : Precio::aCentavos($fila['costo']);
            if ($costo === null) {
                $errores[] = "El costo «{$fila['costo']}» no es un número.";
                $costo = 0;
            }

            $precio = $fila['precio'] === '' ? 0 : Precio::aCentavos($fila['precio']);
            if ($precio === null) {
                $errores[] = "El precio «{$fila['precio']}» no es un número.";
                $precio = 0;
            }

            $impuesto = $fila['impuesto'] === '' ? 0 : Precio::tasaAMilesimas($fila['impuesto']);
            if ($impuesto === null) {
                $errores[] = "El impuesto «{$fila['impuesto']}» no es un número.";
                $impuesto = 0;
            }

            // --- existencias: un servicio no lleva (PRO-03) ---
            $existencia = self::aNumero($fila['existencia']);
            $minima = self::aNumero($fila['existencia_minima']);

            if ($esServicio) {
                if ($existencia > 0) {
                    $errores[] = 'Un servicio no lleva existencia. Deja la columna vacía o quita la marca de servicio.';
                }
                $existencia = 0;
                $minima = 0;
            } elseif ($fila['existencia'] !== '' && self::aNumero($fila['existencia'], null) === null) {
                $errores[] = "La existencia «{$fila['existencia']}» no es un número.";
            }

            $filas[] = [
                'numero' => $numero,
                'accion' => $errores !== [] ? 'error' : ($existente ? 'actualiza' : 'nuevo'),
                'errores' => $errores,
                'nombre' => $fila['nombre'],
                'sku' => $sku ?: null,
                'producto_id' => $existente?->id,
                'datos' => [
                    'nombre' => $fila['nombre'],
                    'sku' => $sku ?: null,
                    'codigo_barras' => $fila['codigo_barras'] ?: null,
                    'categoria_id' => $categoriaId,
                    'suplidor_id' => $suplidorId,
                    'unidad' => $unidad,
                    'costo_centavos' => $costo,
                    'precio_centavos' => $precio,
                    'impuesto_milesimas' => $impuesto,
                    'existencia_minima' => $minima,
                    'es_servicio' => $esServicio,
                ],
                'existencia_inicial' => $existencia,
            ];
        }

        return Importacion::create([
            'tipo' => 'producto',
            'archivo_nombre' => mb_substr($nombreArchivo, 0, 200),
            'estado' => 'previsualizada',
            'actualizar_existentes' => $actualizarExistentes,
            'filas_total' => count($filas),
            'filas_nuevas' => count(array_filter($filas, fn ($f) => $f['accion'] === 'nuevo')),
            'filas_actualiza' => count(array_filter($filas, fn ($f) => $f['accion'] === 'actualiza')),
            'filas_error' => count(array_filter($filas, fn ($f) => $f['accion'] === 'error')),
            'filas' => $filas,
            'usuario_id' => Auth::guard('empresa')->id(),
        ]);
    }

    /**
     * Aplica la importación entera o ninguna fila (PRO-08).
     *
     * @return array{nuevos: int, actualizados: int}
     */
    public static function aplicar(Importacion $importacion): array
    {
        return DB::transaction(function () use ($importacion) {
            $nuevos = 0;
            $actualizados = 0;

            foreach ($importacion->filas ?? [] as $fila) {
                if ($fila['accion'] === 'error') {
                    continue;
                }

                if ($fila['accion'] === 'actualiza' && $fila['producto_id']) {
                    $producto = Producto::query()->find($fila['producto_id']);

                    if (! $producto) {
                        continue;   // lo borraron entre la vista previa y la confirmación
                    }

                    $producto->update($fila['datos']);
                    $actualizados++;
                    continue;
                }

                $producto = Producto::create($fila['datos']);
                $nuevos++;

                // La existencia entra como movimiento de apertura (INV-01, MIG-02).
                if (! $producto->es_servicio && ($fila['existencia_inicial'] ?? 0) > 0) {
                    Inventario::registrar(
                        $producto,
                        'apertura',
                        (float) $fila['existencia_inicial'],
                        'importación',
                        'Archivo '.$importacion->archivo_nombre,
                        $producto->costo_centavos ?: null,
                    );
                }
            }

            $importacion->update([
                'estado' => 'aplicada',
                'aplicada_en' => now(),
            ]);

            return ['nuevos' => $nuevos, 'actualizados' => $actualizados];
        });
    }

    /** Informe de errores descargable (PRO-07). */
    public static function informeDeErrores(Importacion $importacion): string
    {
        $filas = [];

        foreach ($importacion->filas ?? [] as $fila) {
            if ($fila['accion'] !== 'error') {
                continue;
            }

            $filas[] = [
                $fila['numero'],
                $fila['nombre'],
                $fila['sku'] ?? '',
                implode(' ', $fila['errores']),
            ];
        }

        return Csv::escribir(['Fila del archivo', 'Nombre', 'Codigo interno', 'Que hay que arreglar'], $filas);
    }

    /** @return array<string, string> */
    private static function mapear(array $cruda): array
    {
        $fila = array_fill_keys(array_keys(self::COLUMNAS), '');

        foreach ($cruda as $encabezado => $valor) {
            $campo = self::SINONIMOS[$encabezado] ?? null;
            if ($campo !== null) {
                $fila[$campo] = $valor;
            }
        }

        return $fila;
    }

    private static function aBooleano(string $valor): bool
    {
        return in_array(mb_strtolower(trim($valor)), ['si', 'sí', 'x', '1', 'true', 'verdadero', 'yes'], true);
    }

    private static function aNumero(string $valor, ?float $porDefecto = 0.0): ?float
    {
        $limpio = str_replace([' ', ','], ['', '.'], trim($valor));

        if ($limpio === '') {
            return $porDefecto;
        }

        return is_numeric($limpio) ? (float) $limpio : $porDefecto;
    }
}
