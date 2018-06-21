<?php
/**
 * Livia
 * Copyright 2017-2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Livia/blob/master/LICENSE
*/

namespace CharlotteDunois\Livia\Providers;

/**
 * Loads and stores settings associated with guilds.
 */
abstract class SettingProvider {
    protected $client;
    
    /**
     * Removes all settings in a guild.
     * @param string|\CharlotteDunois\Yasmin\Models\Guild  $guild
     * @throws \InvalidArgumentException
     */
    abstract function clear($guild);
    
    /**
     * Destroys the provider, removing any event listeners.
     */
    abstract function destroy();
    
    /**
     * Initializes the provider by connecting to databases and/or caching all data in memory. LiviaClient::setProvider will automatically call this once the client is ready.
     * @param \CharlotteDunois\Livia\LiviaClient    $client
     * @return \React\Promise\ExtendedPromiseInterface
     */
    abstract function init(\CharlotteDunois\Livia\LiviaClient   $client);
    
    /**
     * Gets a setting from a guild.
     * @param string|\CharlotteDunois\Yasmin\Models\Guild  $guild
     * @param string                                       $key
     * @param mixed                                        $defaultValue
     * @return mixed
     * @throws \BadMethodCallException
     * @throws \InvalidArgumentException
     */
    abstract function get($guild, string $key, $defaultValue = null);
    
    /**
     * Sets a setting for a guild.
     * @param string|\CharlotteDunois\Yasmin\Models\Guild  $guild
     * @param string                                       $key
     * @param mixed                                        $value
     * @throws \BadMethodCallException
     * @throws \InvalidArgumentException
     */
    abstract function set($guild, string $key, $value);
    
    /**
     * Removes a setting from a guild.
     * @param string|\CharlotteDunois\Yasmin\Models\Guild  $guild
     * @param string                                       $key
     * @throws \BadMethodCallException
     * @throws \InvalidArgumentException
     */
    abstract function remove($guild, string $key);
    
    /**
     * Obtains the ID of the provided guild.
     * @param \CharlotteDunois\Yasmin\Models\Guild|string|int|null  $guild
     * @return string
     * @throws \InvalidArgumentException
     */
    function getGuildID($guild) {
        if($guild === null || $guild === 'global') {
            return 'global';
        }
        
        return $this->client->guilds->resolve($guild)->id;
    }
}
