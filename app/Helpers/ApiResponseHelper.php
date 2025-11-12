<?php

namespace App\Helpers;

class ApiResponseHelper
{
    // Define constants for error types
    public const VALIDATION_ERROR = 'validation_error';
    public const INVALID_REQUEST = 'request_error';
    public const SYSTEM_ERROR = 'system_error';
    public const DUPLICATE_ENTRY = 'duplicate_entry';
    public const NOT_FOUND = 'not_found';
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    public static function formatErrors($errorType, $errors)
    {
        $formattedErrors = [];

        if (!is_array($errors)) {
            // If $errors is not an array, treat it as a single error message
            $errors = [$errors];
        }

        foreach ($errors as $field => $messages) {
            foreach ((array)$messages as $message) {
                if ($errorType === self::SYSTEM_ERROR) {
                    // For system errors, don't include the 'field' key
                    $formattedErrors[$errorType][] = ['message' => $message];
                } else {
                    // For validation errors, include the 'field' key
                    $formattedErrors[$errorType][] = [
                        'field' => $field,
                        'message' => $message,
                    ];
                }
            }
        }

        return $formattedErrors;
    }
}
