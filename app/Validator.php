<?php
declare(strict_types=1);

namespace App;

final class Validator
{
    /**
     * Validate an associative array against simple rules.
     * Rules supported: required, string, int, bool, array, max:N, min:N, in:a,b,c, nullable.
     *
     * Throws HttpException(422) with all errors when validation fails.
     */
    public static function check(array $data, array $rules): array
    {
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $ruleStr) {
            $parts = explode('|', $ruleStr);
            $hasField = array_key_exists($field, $data);
            $value = $hasField ? $data[$field] : null;

            $required = in_array('required', $parts, true);
            $nullable = in_array('nullable', $parts, true);

            if (!$hasField) {
                if ($required) {
                    $errors[$field][] = 'required';
                }
                continue;
            }

            if ($value === null) {
                if ($nullable) {
                    $clean[$field] = null;
                    continue;
                }
                if ($required) {
                    $errors[$field][] = 'required';
                    continue;
                }
            }

            foreach ($parts as $rule) {
                if (str_starts_with($rule, 'string')) {
                    if ($value !== null && !is_string($value)) {
                        $errors[$field][] = 'must_be_string';
                    }
                } elseif (str_starts_with($rule, 'int')) {
                    if ($value !== null && !is_int($value) && !ctype_digit((string) $value)) {
                        $errors[$field][] = 'must_be_int';
                    } elseif ($value !== null) {
                        $value = (int) $value;
                    }
                } elseif ($rule === 'bool') {
                    if (is_bool($value)) {
                        // ok
                    } elseif ($value === 0 || $value === 1 || $value === '0' || $value === '1' || $value === 'true' || $value === 'false') {
                        $value = (bool) (is_string($value) ? ($value === 'true' || $value === '1') : $value);
                    } elseif ($value !== null) {
                        $errors[$field][] = 'must_be_bool';
                    }
                } elseif ($rule === 'array') {
                    if ($value !== null && !is_array($value)) {
                        $errors[$field][] = 'must_be_array';
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && mb_strlen($value) > $max) {
                        $errors[$field][] = "max_{$max}";
                    } elseif (is_int($value) && $value > $max) {
                        $errors[$field][] = "max_{$max}";
                    } elseif (is_array($value) && count($value) > $max) {
                        $errors[$field][] = "max_{$max}";
                    }
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_string($value) && mb_strlen($value) < $min) {
                        $errors[$field][] = "min_{$min}";
                    } elseif (is_int($value) && $value < $min) {
                        $errors[$field][] = "min_{$min}";
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if ($value !== null && !in_array((string) $value, $allowed, true)) {
                        $errors[$field][] = 'invalid_value';
                    }
                }
            }

            $clean[$field] = $value;
        }

        if (!empty($errors)) {
            throw new HttpException(422, 'validation_failed', 'Validation failed', $errors);
        }

        return $clean;
    }
}
