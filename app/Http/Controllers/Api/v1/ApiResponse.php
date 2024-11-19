<?php

namespace App\Http\Controllers\Api\v1;

trait ApiResponse
{
    /**
     * Return success response
     *
     * @param mixed|null $data
     * @param string|null $message
     * @param int $code
     * @return \Illuminate\Http\JsonResponse
     */
    protected function success($data = null, string $message = null, int $code = 200)
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'created_at' => now()->toISOString(),
        ], $code);
    }

    /**
     * Return error response
     *
     * @param string $message
     * @param int $code
     * @param array|null $errors
     * @return \Illuminate\Http\JsonResponse
     */
    protected function error(string $message, int $code = 400, ?array $errors = null)
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
