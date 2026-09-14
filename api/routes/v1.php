<?php

use App\Http\Middleware\ExigirEmpresa;
use App\Http\Middleware\ExigirPermiso;
use App\Http\V1\Controllers\CategoriaController;
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

        Route::get('productos', [ProductoController::class, 'index'])
            ->middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_VER)
            ->name('v1.productos.index');

        Route::get('productos/{producto}/movimientos', [ProductoController::class, 'movimientos'])
            ->middleware(ExigirPermiso::class.':'.Permisos::CATALOGO_VER)
            ->name('v1.productos.movimientos');

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
