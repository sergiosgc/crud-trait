<?php
namespace sergiosgc\crud;

class Validator {
    public static $validationFunctionMap = [
        'required' => [ '\sergiosgc\crud\BaseValidations', 'required' ],
        'nonempty' => [ '\sergiosgc\crud\BaseValidations', 'nonempty' ],
        'regexp' => [ '\sergiosgc\crud\BaseValidations', 'regexp' ]
    ];
    public static function registerValidationFunction($validation, $callable) {
        if (!is_callable($callable)) throw new Exception('$callable must be a PHP is_callable()');
        static::$validationFunctionMap[$callable] = $callable;
    }
    public static function validateValues($describedFields, $values) {
        $errors = [];
        foreach ($describedFields as $field => $description) {
            if (!isset($description['validation'])) continue;
            $fieldErrors = [];
            foreach ($description['validation'] as $validation) {
                if (is_array($validation)) {
                    $args = array_merge([$field, $values, $describedFields], array_slice($validation, 1));
                    $validation = $validation[0];
                } else {
                    $args = [$field, $values, $describedFields];
                }
                if (!isset(static::$validationFunctionMap[$validation])) continue;
                if (!is_callable(static::$validationFunctionMap[$validation])) throw new Exception(sprintf('Validation for %s is not callable', $validation));
                $fieldErrors = array_merge($fieldErrors, call_user_func_array(static::$validationFunctionMap[$validation], $args));
            }
            if (count($fieldErrors)) $errors[$field] = $fieldErrors;
        }
        return $errors;
    }
}