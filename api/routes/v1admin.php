<?php

use App\Http\V1Admin\Controllers\SesionConsolaController;
use Illuminate\Support\Facades\Route;

/*
 * Superficie de la consola de plataforma (ARQ-07).
 * Exige rol de plataforma; ninguna ruta de negocio vive aquí.
 */

Route::get('sesion/csrf', fn () => response()->noContent());

Route::get('sesion', [SesionConsolaController::class, 'estado'])->name('admin.sesion.estado');
Route::post('sesion', [SesionConsolaController::class, 'iniciar'])->name('admin.sesion.iniciar');

Route::middleware('auth:plataforma')->group(function () {
    Route::get('yo', [SesionConsolaController::class, 'yo'])->name('admin.yo');
    Route::delete('sesion', [SesionConsolaController::class, 'cerrar'])->name('admin.sesion.cerrar');
});
