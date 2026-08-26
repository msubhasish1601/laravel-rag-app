<?php

use App\Http\Controllers\RagDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RagDocumentController::class, 'index'])->name('rag.index');
Route::post('/documents', [RagDocumentController::class, 'store'])->name('documents.store');
Route::post('/documents/url', [App\Http\Controllers\RagDocumentController::class, 'storeUrl'])->name('documents.storeUrl');
Route::delete('/documents/{id}', [RagDocumentController::class, 'destroy'])->name('documents.destroy');
Route::post('/rag/chat', [RagDocumentController::class, 'chat'])->name('rag.chat');
Route::post('/rag/clear', [RagDocumentController::class, 'clearChat'])->name('rag.clear');
