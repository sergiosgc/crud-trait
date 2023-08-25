<?php
namespace sergiosgc\crud;

class Validator {
    public static $validationFunctionMap = [
        'required' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'required' ]
        ],
        'nonempty' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'nonempty' ],
        ],
        'regexp' => [ 
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'regexp' ]
        ],
        'db:unique' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'dbUnique' ]
        ],
        'email' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'email' ]
        ]
    ];
    public static function registerValidationFunction($validation, $function) {
        if (is_callable($function)) return static::registerValidationFunction($validation, [ 'callable' => $function ]);
        if (!is_array($function) || !\array_key_exists('callable', $function)) throw new Exception('Invalid argument: $function');
        if (!is_callable($function['callable'])) throw new Exception('function callable is not is_callable()');
        static::$validationFunctionMap[$validation] = $function;
    }
    public static function validateValues($describedFields, $values, $class = null) {
        $errors = [];
        foreach ($describedFields as $field => $description) {
            if (!isset($description['validation'])) continue;
            $fieldErrors = [];
            foreach ($description['validation'] as $validation) {
                $args = [$field, $describedFields, $values, $class];
                $validationFunction = null;
                if (
                    (
                     is_string(is_array($validation) ? $validation[0] : $validation)
                     || is_int(is_array($validation) ? $validation[0] : $validation)
                    )
                    && isset(static::$validationFunctionMap[is_array($validation) ? $validation[0] : $validation])) {

                    $validationFunction = static::$validationFunctionMap[is_array($validation) ? $validation[0] : $validation]['callable'];
                    if (\array_key_exists('args', static::$validationFunctionMap[is_array($validation) ? $validation[0] : $validation])) $args = array_merge($args, static::$validationFunctionMap[is_array($validation) ? $validation[0] : $validation]['args']);
                } else {
                    if (is_callable( is_array($validation) ? $validation[0] : $validation )) $validationFunction = is_array($validation) ? $validation[0] : $validation;
                }
                if (is_array($validation)) {
                    $args = array_merge($args, array_slice($validation, 1));
                }
                $fieldErrors = array_merge($fieldErrors, call_user_func_array($validationFunction, $args));
            }
            if (count($fieldErrors)) $errors[$field] = $fieldErrors;
        }
        return $errors;
    }
}