<?php

namespace App\Http\Controllers\Api\v1;

trait ApiResponse
{

    protected function success($data = null, string $message = null, int $code = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'error' => $message,
            'created_at' => now()->toISOString(),
        ], $code);
    }

    protected function error(string $message, int $code = 400, ?array $errors = null): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'errors' => $errors,
            ],
            'created_at' => now()->toISOString(),
        ], $code);
    }
}
