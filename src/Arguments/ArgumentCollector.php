<?php
/**
 * Livia
 * Copyright 2017-2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Livia/blob/master/LICENSE
*/

namespace CharlotteDunois\Livia\Arguments;

/**
 * Obtains, validates, and prompts for argument values.
 *
 * @property \CharlotteDunois\Livia\LiviaClient           $client       The client which initiated the instance.
 * @property \CharlotteDunois\Livia\Arguments\Argument[]  $args         Arguments for the collector.
 * @property int|double                                   $promptLimit  Maximum number of times to prompt for a single argument.
 */
class ArgumentCollector implements \Serializable {
    protected $client;
    protected $message;
    
    protected $args = array();
    protected $promptLimit;
    
    /**
     * Constructs a new Argument Collector.
     * @param \CharlotteDunois\Livia\LiviaClient    $client
     * @param array                                 $args
     * @param int|double                            $promptLimit
     * @throws \InvalidArgumentException
     */
    function __construct(\CharlotteDunois\Livia\LiviaClient $client, array $args, $promptLimit = \INF) {
        $this->client = $client;
        
        $hasInfinite = false;
        $hasOptional = false;
        foreach($args as $key => $arg) {
            if(!empty($arg['infinite'])) {
                $hasInfinite = true;
            } elseif($hasInfinite) {
                throw new \InvalidArgumentException('No other argument may come after an infinite argument');
            }
            
            if(($arg['default'] ?? null) !== null) {
                $hasOptional = true;
            } elseif($hasOptional) {
                throw new \InvalidArgumentException('Required arguments may not come after optional arguments');
            }
            
            if(empty($arg['key']) && !empty($key)) {
                $arg['key'] = $key;
            }
            
            $this->args[] = new \CharlotteDunois\Livia\Arguments\Argument($this->client, $arg);
        }
        
        $this->promptLimit = $promptLimit;
    }
    
    /**
     * @throws \RuntimeException
     * @internal
     */
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        }
        
        throw new \RuntimeException('Unknown property \CharlotteDunois\Livia\Arguments\ArgumentCollector::'.$name);
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
     * Obtains values for the arguments, prompting if necessary.
     * @param \CharlotteDunois\Livia\CommandMessage  $message
     * @param array                                  $provided
     * @param int|double                             $promptLimit
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function obtain(\CharlotteDunois\Livia\CommandMessage $message, $provided = array(), $promptLimit = null) {
        if($promptLimit === null) {
            $promptLimit = $this->promptLimit;
        }
        
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($message, $provided, $promptLimit) {
            $this->client->dispatcher->awaiting[] = $message->message->author->id.$message->message->channel->id;
            
            $values = array();
            $results = array();
            
            try {
                $this->obtainNext($message, $provided, $promptLimit, $values, $results, 0)->then(function ($result = null) use ($message, &$values, &$results) {
                    $key = \array_search($message->message->author->id.$message->message->channel->id, $this->client->dispatcher->awaiting);
                    if($key !== false) {
                        unset($this->client->dispatcher->awaiting[$key]);
                    }
                    
                    if($result !== null) {
                        return $result;
                    }
                    
                    return array(
                        'values' => $values,
                        'cancelled' => null,
                        'prompts' => \array_merge(array(), ...\array_map(function ($res) {
                            return $res['prompts'];
                        }, $results)),
                        'answers' => \array_merge(array(), ...\array_map(function ($res) {
                            return $res['answers'];
                        }, $results))
                    );
                }, function ($error) use ($message) {
                    $key = \array_search($message->message->author->id.$message->message->channel->id, $this->client->dispatcher->awaiting);
                    if($key !== false) {
                        unset($this->client->dispatcher->awaiting[$key]);
                    }
                    
                    throw $error;
                })->done($resolve, $reject);
            } catch (\Throwable | \Exception | \Error $error) {
                $key = \array_search($message->message->author->id.$message->message->channel->id, $this->client->dispatcher->awaiting);
                if($key !== false) {
                    unset($this->client->dispatcher->awaiting[$key]);
                }
                
                throw $error;
            }
        }));
    }
    
    /**
     * Obtains and collects the next argument.
     * @param \CharlotteDunois\Livia\CommandMessage $message
     * @param array                                 $provided
     * @param int|double                            $promptLimit
     * @param array                                 $values
     * @param array                                 $results
     * @param int                                   $current
     * @return \React\Promise\ExtendedPromiseInterface
     */
    protected function obtainNext(\CharlotteDunois\Livia\CommandMessage $message, array &$provided, $promptLimit, array &$values, array &$results, int $current) {
        if(empty($this->args[$current])) {
            return \React\Promise\resolve();
        }
        
        return $this->args[$current]->obtain($message, (isset($provided[$current]) ? ($this->args[$current]->infinite ? \array_slice($provided, $current) : $provided[$current]) : null), $promptLimit)->then(function ($result) use ($message, &$provided, $promptLimit, &$values, &$results, $current)  {
            $results[] = $result;
            
            if($result['cancelled']) {
                return array(
                    'values' => null,
                    'cancelled' => $result['cancelled'],
                    'prompts' => \array_merge(array(), ...\array_map(function ($res) {
                        return $res['prompts'];
                    }, $results)),
                    'answers' => \array_merge(array(), ...\array_map(function ($res) {
                        return $res['answers'];
                    }, $results))
                );
            }
            
            $values[$this->args[$current]->key] = $result['value'];
            $current++;
            
            return $this->obtainNext($message, $provided, $promptLimit, $values, $results, $current);
        });
    }
}
