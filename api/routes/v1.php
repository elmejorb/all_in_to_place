<?php

use App\Http\Middleware\ExigirEmpresa;
use App\Http\Middleware\ExigirPermiso;
use App\Http\V1\Controllers\CategoriaController;
use App\Http\V1\Controllers\ClienteController;
use App\Http\V1\Controllers\CuadreController;
use App\Http\V1\Controllers\EmpresaController;
use App\Http\V1\Controllers\FacturaController;
use App\Http\V1\Controllers\ImportacionProductoController;
use App\Http\V1\Controllers\ProductoController;
use App\Http\V1\Controllers\SesionController;
use App\Http\V1\Controllers\SuplidorController;
use App\Soporte\Permisos;
use Illuminate\Support\Facades\Route;

/*
 * Superficie de la app de empresa (ARQ-07).
 * Todo endpoint exige sesión salvo los declarados aquí como públicos (SEG-11).
 */

// Entrega la cookie de CSRF antes del primer envío (SEG-26).
Route::get('sesion/csrf', fn () => response()->noContent());

// Preguntar si hay sesión no es un dato protegido: responde 200 con autenticado
// true o false. Así la app no arranca con un 401 en la consola del navegador.
Route::get('sesion', [SesionController::class, 'estado'])->name('v1.sesion.estado');
Route::post('sesion', [SesionController::class, 'iniciar'])->name('v1.sesion.iniciar');

Route::middleware('auth:empresa')->group(function () {
    Route::get('yo', [SesionController::class, 'yo'])->name('v1.yo');
    Route::post('sesion/empresa', [SesionController::class, 'cambiarEmpresa'])->name('v1.sesion.empresa');
    Route::delete('sesion', [SesionController::class, 'cerrar'])->name('v1.sesion.cerrar');

    // --- negocio: exige empresa activa en la sesión ----------------------
    Route::middleware(ExigirEmpresa::class)->group(function () {
        Route::get('suplidores', [SuplidorController::class, 'index'])
            ->middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_VER)
            ->name('v1.suplidores.index');

        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_EDITAR)->group(function () {
            Route::post('suplidores', [SuplidorController::class, 'store'])->name('v1.suplidores.store');
            Route::put('suplidores/{suplidor}', [SuplidorController::class, 'update'])->name('v1.suplidores.update');
        });

        Route::get('categorias', [CategoriaController::class, 'index'])
            ->middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_VER)
            ->name('v1.categorias.index');

        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_EDITAR)->group(function () {
            Route::post('categorias', [CategoriaController::class, 'store'])->name('v1.categorias.store');
            Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])->name('v1.categorias.update');
        });

        // Los datos del membrete: van en la hoja de la factura, así que los ve
        // cualquiera que tenga la empresa activa (FAC-01).
        Route::get('empresa', [EmpresaController::class, 'ver'])->name('v1.empresa.ver');

        // --- facturación (FAC-01 a FAC-09) ---
        Route::middleware(ExigirPermiso::class.':'.Permisos::FACTURAR)->group(function () {
            Route::get('facturas', [FacturaController::class, 'index'])->name('v1.facturas.index');
            Route::post('facturas/calcular', [FacturaController::class, 'calcular'])->name('v1.facturas.calcular');

            // Los borradores van antes que {documento}: si no, "borradores" se
            // toma por el identificador de una factura.
            Route::post('facturas/borradores', [FacturaController::class, 'guardarBorrador'])->name('v1.facturas.borrador');

            Route::get('facturas/{documento}', [FacturaController::class, 'ver'])->name('v1.facturas.ver');
            Route::put('facturas/{documento}', [FacturaController::class, 'actualizarBorrador'])->name('v1.facturas.actualizar');
            Route::delete('facturas/{documento}', [FacturaController::class, 'descartarBorrador'])->name('v1.facturas.descartar');

            Route::post('facturas', [FacturaController::class, 'emitir'])->name('v1.facturas.emitir');
            Route::post('facturas/{documento}/emitir', [FacturaController::class, 'emitirBorrador'])->name('v1.facturas.emitir-borrador');
            Route::post('facturas/{documento}/cobrar', [FacturaController::class, 'cobrar'])->name('v1.facturas.cobrar');
        });

        Route::post('facturas/{documento}/anular', [FacturaController::class, 'anular'])
            ->middleware(ExigirPermiso::class.':'.Permisos::FACTURAS_ANULAR)
            ->name('v1.facturas.anular');

        // --- hoja de cuadre (CAJ-11 a CAJ-14) ---
        Route::middleware(ExigirPermiso::class.':'.Permisos::CAJA_VER)->group(function () {
            Route::get('cuadres', [CuadreController::class, 'index'])->name('v1.cuadres.index');
            // Antes que {hoja}: si no, "facturado" se toma por un identificador.
            Route::get('cuadres/facturado', [CuadreController::class, 'facturado'])->name('v1.cuadres.facturado');
            Route::get('cuadres/{hoja}', [CuadreController::class, 'ver'])->name('v1.cuadres.ver');
            Route::get('cuadres/{hoja}/pdf', [CuadreController::class, 'pdf'])->name('v1.cuadres.pdf');
        });

        Route::middleware(ExigirPermiso::class.':'.Permisos::CAJA_CUADRAR)->group(function () {
            Route::post('cuadres', [CuadreController::class, 'guardar'])->name('v1.cuadres.guardar');
            Route::put('cuadres/{hoja}', [CuadreController::class, 'actualizar'])->name('v1.cuadres.actualizar');
        });

        // --- clientes (CLI-01, CLI-02) ---
        Route::get('clientes', [ClienteController::class, 'index'])
            ->middleware(ExigirPermiso::class.':'.Permisos::CLIENTES_VER)
            ->name('v1.clientes.index');

        Route::middleware(ExigirPermiso::class.':'.Permisos::CLIENTES_EDITAR)->group(function () {
            Route::post('clientes', [ClienteController::class, 'store'])->name('v1.clientes.store');
            Route::put('clientes/{cliente}', [ClienteController::class, 'update'])->name('v1.clientes.update');
        });

        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_DESACTIVAR)->group(function () {
            Route::post('clientes/{cliente}/desactivar', [ClienteController::class, 'desactivar'])->name('v1.clientes.desactivar');
            Route::post('clientes/{cliente}/reactivar', [ClienteController::class, 'reactivar'])->name('v1.clientes.reactivar');
        });

        // La lista de productos, no el catálogo entero: quien factura en el
        // mostrador tiene que poder buscar lo que vende.
        Route::get('productos', [ProductoController::class, 'index'])
            ->middleware(ExigirPermiso::class.':'.Permisos::PRODUCTOS_VER)
            ->name('v1.productos.index');

        Route::get('productos/{producto}/movimientos', [ProductoController::class, 'movimientos'])
            ->middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_VER)
            ->name('v1.productos.movimientos');

        // Importar y exportar el catálogo (PRO-07, PRO-08, PRO-09).
        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_VER)->group(function () {
            Route::get('productos/plantilla', [ImportacionProductoController::class, 'plantilla'])->name('v1.productos.plantilla');
            Route::get('productos/exportar', [ImportacionProductoController::class, 'exportar'])->name('v1.productos.exportar');
            Route::get('importaciones/{importacion}/errores', [ImportacionProductoController::class, 'errores'])->name('v1.importaciones.errores');
        });

        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_EDITAR)->group(function () {
            Route::post('productos/importar', [ImportacionProductoController::class, 'previsualizar'])->name('v1.productos.importar');
            Route::post('importaciones/{importacion}/confirmar', [ImportacionProductoController::class, 'confirmar'])->name('v1.importaciones.confirmar');
            Route::post('importaciones/{importacion}/descartar', [ImportacionProductoController::class, 'descartar'])->name('v1.importaciones.descartar');
        });

        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_EDITAR)->group(function () {
            Route::post('productos', [ProductoController::class, 'store'])->name('v1.productos.store');
            Route::put('productos/{producto}', [ProductoController::class, 'update'])->name('v1.productos.update');
            Route::post('productos/{producto}/ajustar', [ProductoController::class, 'ajustar'])->name('v1.productos.ajustar');
        });

        Route::middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_DESACTIVAR)->group(function () {
            Route::delete('categorias/{categoria}', [CategoriaController::class, 'eliminar'])->name('v1.categorias.eliminar');
            Route::post('productos/{producto}/desactivar', [ProductoController::class, 'desactivar'])->name('v1.productos.desactivar');
            Route::post('productos/{producto}/reactivar', [ProductoController::class, 'reactivar'])->name('v1.productos.reactivar');
            Route::post('suplidores/{suplidor}/desactivar', [SuplidorController::class, 'desactivar'])->name('v1.suplidores.desactivar');
            Route::post('suplidores/{suplidor}/reactivar', [SuplidorController::class, 'reactivar'])->name('v1.suplidores.reactivar');
        });
    });
});
