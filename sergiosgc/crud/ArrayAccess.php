<?php
namespace sergiosgc\crud;

trait ArrayAccess {
    public function offsetExists ( $offset ) {
        if (!$this instanceof Describable) throw new Exception("ArrayAccess trait can only be used by Describable classes");
        return in_array($offset, array_keys(static::describeFields()));
    }
    public function offsetGet ( $offset ) {
        if (!$this->offsetExists($offset)) return null;
        return $this->$offset;
    }
    public function offsetSet ( $offset , $value ) {
        if (!$this->offsetExists($offset)) return null;
        $this->$offset = $value;
    }
    public function offsetUnset ( $offset ) {
        if (!$this->offsetExists($offset)) return null;
        unset($this->$offset);
    }
}
