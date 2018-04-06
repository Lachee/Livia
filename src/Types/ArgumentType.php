<?php
/**
 * Livia
 * Copyright 2017-2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Livia/blob/master/LICENSE
*/

namespace CharlotteDunois\Livia\Types;

/**
 * An argument type that can be used for argument collecting.
 *
 * @property \CharlotteDunois\Livia\LiviaClient        $client    The client which initiated the instance.
 * @property string                                    $id        The argument type ID.
 */
abstract class ArgumentType implements \Serializable {
    protected $client;
    protected $id;
    
    /**
     * @internal
     */
    function __construct(\CharlotteDunois\Livia\LiviaClient $client, string $id) {
        $this->client = $client;
        $this->id = $id;
    }
    
    /**
     * @throws \RuntimeException
     * @internal
     */
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        }
        
        throw new \RuntimeException('Unknown property \CharlotteDunois\Livia\Types\ArgumentType::'.$name);
    }
    
    /**
     * @internal
     */
    function serialize() {
        $vars = \get_object_vars($this);
        
        unset($vars['client']);
        
        return \serialize($vars);
    }
    
    /**
     * @internal
     */
    function unserialize($vars) {
        if(\CharlotteDunois\Yasmin\Models\ClientBase::$serializeClient === null) {
            throw new \Exception('Unable to unserialize a class without ClientBase::$serializeClient being set');
        }
        
        $vars = \unserialize($vars);
        
        foreach($vars as $name => $val) {
            $this->$name = $val;
        }
        
        $this->client = \CharlotteDunois\Yasmin\Models\ClientBase::$serializeClient;
    }
    
    /**
     * Validates a value against the type.
     * @param string                                     $value  Value to validate.
     * @param \CharlotteDunois\Livia\CommandMessage      $msg    Message the value was obtained from.
     * @param \CharlotteDUnois\Livia\Arguments\Argument|null  $arg    Argument the value obtained from.
     * @return bool|string|\React\Promise\Promise
     */
    abstract function validate(string $value, \CharlotteDunois\Livia\CommandMessage $message, ?\CharlotteDunois\Livia\Arguments\Argument $arg = null);
    
    /**
     * Parses a value into an usable value.
     * @param string                                          $value  Value to parse.
     * @param \CharlotteDunois\Livia\CommandMessage           $msg    Message the value was obtained from.
     * @param \CharlotteDUnois\Livia\Arguments\Argument|null  $arg    Argument the value obtained from.
     * @return mixed|null
     * @throws \RangeException
     */
    abstract function parse(string $value, \CharlotteDunois\Livia\CommandMessage $message, ?\CharlotteDunois\Livia\Arguments\Argument $arg = null);
    
    /**
     * Checks whether a value is considered to be empty. This determines whether the default value for an argument should be used and changes the response to the user under certain circumstances.
     * @param mixed                                           $value  Value to check.
     * @param \CharlotteDunois\Livia\CommandMessage           $msg    Message the value was obtained from.
     * @param \CharlotteDUnois\Livia\Arguments\Argument|null  $arg    Argument the value obtained from.
     * @return bool
     */
    function isEmpty($value, \CharlotteDunois\Livia\CommandMessage $msg, ?\CharlotteDunois\Livia\Arguments\Argument $arg = null) {
        if(\is_array($value) || \is_object($value)) {
            return (empty($value));
        }
        
        return (\mb_strlen(\trim(((string) $value))) === 0);
    }
}
