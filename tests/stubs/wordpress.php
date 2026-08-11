<?php

declare(strict_types=1);

/**
 * Minimal WordPress class stubs for unit testing.
 * These are loaded before any test runs so that type declarations resolve.
 */

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private array $errors    = [];
        private array $errorData = [];

        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
                if ($data !== '') {
                    $this->errorData[$code] = $data;
                }
            }
        }

        public function get_error_code(): string|int
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message(string $code = ''): string
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data(string $code = ''): mixed
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->errorData[$code] ?? null;
        }

        public function has_errors(): bool
        {
            return ! empty($this->errors);
        }
    }
}
