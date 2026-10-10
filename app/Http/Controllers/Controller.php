<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Logs an unexpected exception and answers with a generic message.
     *
     * The exception text can carry SQL, table names or file paths, so it goes
     * to the log, never to the client. `$key` keeps each endpoint's existing
     * response shape ('message' or 'error') so the app parses it as before.
     * Validation messages are written for the user, so those still go through.
     */
    protected function serverError(\Throwable $e, string $key = 'message', int $status = 500): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([$key => $e->getMessage()], $status);
        }

        report($e);

        return response()->json([$key => 'Something went wrong. Please try again.'], $status);
    }
}
