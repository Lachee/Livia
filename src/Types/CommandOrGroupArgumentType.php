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
 * {@inheritdoc}
 * @internal
 */
class CommandOrGroupArgumentType extends ArgumentType {
    /**
     * @internal
     */
    function __construct(\CharlotteDunois\Livia\LiviaClient $client) {
        parent::__construct($client, 'command-or-group');
    }
    
    /**
     * {@inheritdoc}
     * @return bool|string|\React\Promise\ExtendedPromiseInterface
     */
    function validate(string $value, \CharlotteDunois\Livia\CommandMessage $message, ?\CharlotteDunois\Livia\Arguments\Argument $arg = null) {
        $groups = $this->client->registry->findGroups($value);
        if(\count($groups) === 1) {
            return true;
        }
        
        $commands = $this->client->registry->findCommands($value);
        if(\count($commands) === 1) {
            return true;
        }
        
        if(\count($groups) === 0 && \count($commands) === 0) {
            return false;
        }
        
        return \CharlotteDunois\Livia\Utils\DataHelpers::disambiguation($commands, 'commands', 'name').\PHP_EOL.\CharlotteDunois\Livia\Utils\DataHelpers::disambiguation($groups, 'groups', 'name');
    }
    
    /**
     * {@inheritdoc}
     * @return mixed|null|\React\Promise\ExtendedPromiseInterface
     */
    function parse(string $value, \CharlotteDunois\Livia\CommandMessage $message, ?\CharlotteDunois\Livia\Arguments\Argument $arg = null) {
        $groups = $this->client->registry->findGroups($value);
        if(\count($groups) > 0) {
            return $groups[0];
        }
        
        $commands = $this->client->registry->findCommands($value);
        if(\count($commands) > 0) {
            return $commands[0];
        }
        
        return null;
    }
}
