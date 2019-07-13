<?php
namespace sergiosgc\crud;

trait JsonSerializable {
    public function jsonSerialize ( ) {
        if (!$this instanceof Describable) throw new Exception("JsonSerializable trait can only be used by Describable classes");
        $result = [];
        foreach (array_keys(static::describeFields()) as $k) $result[$k] = $this->$k;
        return $result;
    }
}
