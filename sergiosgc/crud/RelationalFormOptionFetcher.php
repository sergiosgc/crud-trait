<?php
namespace sergiosgc\crud;

class RelationalFormOptionFetcher {
    public static function register() {
        \sergiosgc\form\Form::addPropertyDefaultHandler(['\sergiosgc\crud\RelationalFormOptionFetcher', 'setWidgetDefaults']);
    }
    public static function setWidgetDefaults($properties) {
        foreach ($properties as $name => $property) {
            if (isset($property['db:many_to_many'])) {
                $properties[$name] = static::setManyToManyOptions($property, $name, $properties);
                continue;
            } elseif (isset($property['db:many_to_one'])) {
                $properties[$name] = static::setManyToOneOptions($property, $name, $properties);
                if (!\array_key_exists('ui:widget', $property)) $properties[$name]['ui:widget'] = 'select';
                continue;
            }
        }
        return $properties;
    }
    public static function setManyToOneOptions($property, $name) {
        $manyToOne = $property['db:many_to_one'];
        foreach (['type', 'keymap', 'label'] as $required) if (!isset($manyToOne[$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $name, $required));
        $class = $manyToOne['type'];
        if (!class_exists($class)) throw new Exception(sprintf("%s class does not exist", $class));
        if (isset($manyToOne['optionFetcher'])) {
            list($options, $optionCount) = call_user_func($manyToOne['optionFetcher']);
        } else {
            $readAllArgs = [
                $manyToOne['label'], 
                'ASC', 
                isset($manyToOne['optionsFilter']) ? $manyToOne['optionsFilter'] : null,
                null, 
                null];
            if (isset($manyToOne['optionsFilterArgs'])) $readAllArgs = array_merge($readAllArgs, $manyToOne['optionsFilterArgs']);
            list($options, $optionCount) = call_user_func_array( [$class, 'dbReadPaged'], $readAllArgs);
        }
        $property['options'] = array_map(
            function($option) use ($manyToOne) {
                $idFields = array_values($manyToOne['keymap']);
                if (count($idFields) == 1) {
                    $id = $option[$idFields[0]];
                } else {
                    $id = array_implode('?', array_map(
                        function($idField) use ($option) {
                            return sprintf("%s=%s", urlencode($idField), urlencode($option[$idField]));
                        },
                        $idFields,
                    ));
                }
                return [
                    'value' => $id,
                    'label' => is_callable([$option, $manyToOne['label']]) ? call_user_func([$option, $manyToOne['label']]) : $option[$manyToOne['label']],
                    'selected' => false
                ];
            },
            $options);
        return $property;
    }
    public static function setManyToManyOptions($property, $name) {
        $manyToMany = $property['db:many_to_many'];
        foreach (['type', 'keymap', 'label'] as $required) if (!isset($manyToMany[$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor", $name, $required));
        foreach (['middle_table', 'left', 'right'] as $required) if (!isset($manyToMany['keymap'][$required])) throw new Exception(sprintf("%s field is declared db:many_to_many but has no %s descriptor in keymap", $name, $required));
        $class = $manyToMany['type'];
        if (!class_exists($class)) throw new Exception(sprintf("%s class does not exist", $class));
        if (isset($manyToMany['optionFetcher'])) {
            list($options, $optionCount) = call_user_func($manyToMany['optionFetcher']);
        } else {
            $readAllArgs = [
                $manyToMany['label'], 
                'ASC', 
                isset($manyToMany['optionsFilter']) ? $manyToMany['optionsFilter'] : null,
                null, 
                null];
            if (isset($manyToMany['optionsFilterArgs'])) $readAllArgs = array_merge($readAllArgs, $manyToMany['optionsFilterArgs']);
            list($options, $optionCount) = call_user_func_array( [$class, 'dbReadPaged'], $readAllArgs);
        }
        $property['options'] = array_map(
            function($option) use ($manyToMany) {
                $idFields = array_values($manyToMany['keymap']['right']);
                if (count($idFields) == 1) {
                    $id = $option[$idFields[0]];
                } else {
                    $id = array_implode('?', array_map(
                        function($idField) use ($option) {
                            return sprintf("%s=%s", urlencode($idField), urlencode($option[$idField]));
                        },
                        $idFields,
                    ));
                }
                return [
                    'value' => $id,
                    'label' => is_callable([$option, $manyToMany['label']]) ? call_user_func([$option, $manyToMany['label']]) : $option[$manyToMany['label']],
                    'selected' => false
                ];
            },
            $options
        );
        return $property;
    }
}
