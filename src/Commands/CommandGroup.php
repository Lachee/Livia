<?php
/**
 * Livia
 * Copyright 2017-2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Livia/blob/master/LICENSE
*/

namespace CharlotteDunois\Livia\Commands;

/**
 * A group for commands.
 *
 * @property \CharlotteDunois\Livia\LiviaClient        $client         The client which initiated the instance.
 * @property string                                    $id             The ID of the group.
 * @property string                                    $name           The name of the group.
 * @property bool                                      $guarded        Whether this group is guarded against disabling.
 * @property \CharlotteDunois\Yasmin\Utils\Collection  $commands       The commands that the group contains.
 */
class CommandGroup implements \Serializable {
    protected $client;
    
    protected $id;
    protected $name;
    protected $guarded;
    protected $commands;
    
    protected $globalEnabled = true;
    protected $guildEnabled = array();
    
    /**
     * Constructs a new Command Group.
     * @param \CharlotteDunois\Livia\LiviaClient    $client
     * @param string                                $id
     * @param string                                $name
     * @param bool                                  $guarded
     * @param array|null                            $commands
     */
    function __construct(\CharlotteDunois\Livia\LiviaClient $client, string $id, string $name, bool $guarded = false, array $commands = null) {
        $this->client = $client;
        
        $this->id = $id;
        $this->name = $name;
        $this->guarded = $guarded;
        
        $this->commands = new \CharlotteDunois\Yasmin\Utils\Collection();
        if(!empty($commands)) {
            foreach($commands as $command) {
                $this->commands->set($command->name, $command);
            }
        }
    }
    
    /**
     * @throws \RuntimeException
     * @internal
     */
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        }
        
        throw new \RuntimeException('Unknown property \CharlotteDunois\Livia\Commands\CommandGroup::'.$name);
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
     * Enables or disables the group in a guild.
     * @param string|\CharlotteDunois\Yasmin\Models\Guild|null  $guild  The guild instance or the guild ID.
     * @param bool                                              $enabled
     * @return bool
     * @throws \BadMethodCallException|\InvalidArgumentException
     */
    function setEnabledIn($guild, bool $enabled) {
        if($guild !== null) {
            $guild = $this->client->guilds->resolve($guild);
        }
        
        if($this->guarded) {
            throw new \BadMethodCallException('The group is guarded');
        }
        
        if($guild !== null) {
            $this->guildEnabled[$guild->id] = $enabled;
        } else {
            $this->globalEnabled = $enabled;
        }
        
        $this->client->emit('groupStatusChange', $guild, $this, $enabled);
        return ($guild !== null ? $this->guildEnabled[$guild->id] : $this->globalEnabled);
    }
    
    /**
     * Checks if the group is enabled in a guild.
     * @param string|\CharlotteDunois\Yasmin\Models\Guild|null  $guild  The guild instance or the guild ID.
     * @return bool
     * @throws \InvalidArgumentException
     */
    function isEnabledIn($guild) {
        if($guild !== null) {
            $guild = $this->client->guilds->resolve($guild);
            return (!\array_key_exists($guild->id, $this->guildEnabled) || $this->guildEnabled[$guild->id]);
        }
        
        return $this->globalEnabled;
    }
    
    /**
     * Reloads all of the group's commands.
     */
    function reload() {
        foreach($this->commands as $command) {
            $command->reload();
        }
    }
}
