<?php
/**
 * Livia
 * Copyright 2017-2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Livia/blob/master/LICENSE
*/

namespace CharlotteDunois\Livia;

/**
 * A command message.
 *
 * @property \CharlotteDunois\Livia\LiviaClient            $client          The client which initiated the instance.
 * @property \CharlotteDunois\Yasmin\Models\Message        $message         The message that triggers the command.
 * @property \CharlotteDunois\Livia\Commands\Command|null  $command         The command that got triggered, if any.
 *
 * @property string|null                                   $argString       Argument string for the command.
 * @property string[]|null                                 $patternMatches  Pattern matches (if from a pattern trigger).
 */
class CommandMessage extends \CharlotteDunois\Yasmin\Models\ClientBase {
    protected $client;
    protected $message;
    protected $command;
    
    protected $argString;
    protected $patternMatches;
    
    protected $responses;
    protected $responsePositions;
    
    /**
     * @internal
     */
    function __construct(\CharlotteDunois\Livia\LiviaClient $client, \CharlotteDunois\Yasmin\Models\Message $message, \CharlotteDunois\Livia\Commands\Command $command = null, string $argString = null, array $patternMatches = null) {
        $this->client = $client;
        $this->message = $message;
        $this->command = $command;
        
        $this->argString = ($argString !== null ? \trim($argString) : null);
        $this->patternMatches = $patternMatches;
    }
    
    /**
     * @throws \RuntimeException
     * @internal
     */
    function __get($name) {
        if(\property_exists($this, $name)) {
            return $this->$name;
        } elseif(\property_exists($this->message, $name)) {
            return $this->message->$name;
        }
        
        try {
            return $this->message->__get($name);
        } catch(\Exception $e) {
            /* Continue regardless of error */
        }
        
        throw new \RuntimeException('Unknown property \CharlotteDunois\Livia\CommandMessage::'.$name);
    }
    
    /**
     * @throws \RuntimeException
     * @internal
     */
    function __call($name, $args) {
        if(\method_exists($this->message, $name)) {
            return $this->message->$name(...$args);
        }
        
        throw new \RuntimeException('Unknown method \CharlotteDunois\Livia\CommandMessage::'.$name);
    }
    
    /**
     * Parses the argString into usable arguments, based on the argsType and argsCount of the command.
     * @return string|string[]
     * @throws \RangeException
     */
    function parseCommandArgs() {
        switch($this->command->argsType) {
            case 'single':
                $args = $this->argString;
                return \preg_replace(($this->command->argsSingleQuotes ? '/^("|\')(.*)\1$/u' : '/^(")(.*)"$/u'), '$2', $args);
            case 'multiple':
                return self::parseArgs($this->argString, $this->command->argsCount, $this->command->argsSingleQuotes);
            default:
                throw new \RangeException('Unknown argsType "'.$this->command->argsType.'".');
        }
    }
    
    /**
     * Runs the command. Resolves with an instance of Message or an array of Message instances.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function run() {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) {
            $promises = array();
            
            // Obtain the member if we don't have it
            if($this->message->channel->type === 'text' && $this->message->guild->members->has($this->message->author->id) === false && $this->message->webhookID === null) {
                $promises[] = $this->message->guild->fetchMember($this->message->author->id);
            }
            
            // Obtain the member for the client user if we don't have it
            if($this->message->channel->type === 'text' && $this->message->guild->me === null) {
                $promises[] = $this->message->guild->fetchMember($this->client->user->id);
            }
            
            if($this->command->guildOnly && $this->message->guild === null) {
                $this->client->emit('commandBlocked', $this, 'guildOnly');
                $this->message->reply('The `'.$this->command->name.'` command must be used in a server channel.')->done($resolve, $reject);
                return;
            }
            
            if($this->command->nsfw && !$this->message->nsfw) {
                $this->client->emit('commandBlocked', $this, 'nsfw');
                $this->message->reply('The `'.$this->command->name.'` command must be used in NSFW channels.')->done($resolve, $reject);
                return;
            }
            
            $perms = $this->command->hasPermission($this);
            if($perms === false || \is_string($perms)) {
                $this->client->emit('commandBlocked', $this, 'permission');
                
                if($this->patternMatches !== null && !((bool) $this->client->getOption('commandBlockedMessagePattern', true))) {
                    return $resolve();
                }
                
                if($perms === false) {
                    $perms = 'You do not have permission to use the `'.$this->command->name.'` command.';
                }
                
                $this->message->reply($perms)->done($resolve, $reject);
                return;
            }
            
            // Ensure the client user has the required permissions
            if($this->message->channel->type === 'text' && !empty($this->command->clientPermissions)) {
                $perms = $this->message->channel->permissionsFor($this->message->guild->me);
                
                $missing = array();
                foreach($this->command->clientPermissions as $perm) {
                    if($perms->missing($perm)) {
                        $missing[] = $perm;
                    }
                }
                
                if(\count($missing) > 0) {
                    $this->client->emit('commandBlocked', $this, 'clientPermissions');
                    
                    if($this->patternMatches !== null && !((bool) $this->client->getOption('commandBlockedMessagePattern', true))) {
                        return $resolve();
                    }
                    
                    if(\count($missing) === 1) {
                        $msg = 'I need the permissions `'.$missing[0].'` permission for the `'.$this->command->name.'` command to work.';
                    } else {
                        $missing = \implode(', ', \array_map(function ($perm) {
                            return '`'.\CharlotteDunois\Yasmin\Models\Permissions::resolveToName($perm).'`';
                        }, $missing));
                        $msg = 'I need the following permissions for the `'.$this->command->name.'` command to work:'.\PHP_EOL.$missing;
                    }
                    
                    $this->message->reply($msg)->done($resolve, $reject);
                    return;
                }
            }
            
            // Throttle the command
            $throttle = $this->command->throttle($this->message->author->id);
            if($throttle && ($throttle['usages'] + 1) > ($this->command->throttling['usages'])) {
                $remaining = $throttle['start'] + $this->command->throttling['duration'] - \time();
                $this->client->emit('commandBlocked', $this, 'throttling');
                
                if($this->patternMatches !== null && !((bool) $this->client->getOption('commandThrottlingMessagePattern', true))) {
                    return $resolve();
                }
                
                $this->message->reply('You may not use the `'.$this->command->name.'` command again for another '.$remaining.' seconds.')->done($resolve, $reject);
                return;
            }
            
            // Figure out the command arguments
            $args = $this->patternMatches;
            $countArgs = \count($this->command->args);
            
            if(!$args && $countArgs > 0) {
                $count = (!empty($this->command->args[($countArgs - 1)]['infinite']) ? \INF : $countArgs);
                $provided = self::parseArgs($this->argString, $count, $this->command->argsSingleQuotes);
                
                $promises[] = $this->command->argsCollector->obtain($this, $provided)->then(function ($result) use (&$args) {
                    if($result['cancelled']) {
                        if(\count($result['prompts']) === 0) {
                            throw new \CharlotteDunois\Livia\Exceptions\CommandFormatException($this);
                        }
                        
                        throw new \CharlotteDunois\Livia\Exceptions\FriendlyException('Cancelled Command.');
                    }
                    
                    $args = $result['values'];
                    
                    if(!$args) {
                        $args = $this->parseCommandArgs();
                    }
                });
            }
            
            // Run the command
            if($throttle) {
                $this->command->updateThrottle($this->message->author->id);
            }
            
            $typingCount = $this->message->channel->typingCount();
            
            \React\Promise\all($promises)->then(function () use (&$args) {
                $args = new \ArrayObject((array) $args, \ArrayObject::ARRAY_AS_PROPS);
            }, function ($error) use (&$args) {
                $args = new \ArrayObject((array) $args, \ArrayObject::ARRAY_AS_PROPS);
                throw $error;
            })->then(function () use (&$args) {
                $promise = $this->command->run($this, $args, ($this->patternMatches !== null));
                
                if($promise instanceof \GuzzleHttp\Promise\PromiseInterface) {
                    $promise = new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($promise) {
                        $promise->then($resolve, $reject);
                    });
                } elseif(!($promise instanceof \React\Promise\PromiseInterface)) {
                    $promise = \React\Promise\resolve($promise);
                }
                
                $this->client->emit('commandRun', $this->command, $promise, $this, $args, ($this->patternMatches !== null));
                
                return $promise->then(function($response) {
                    if(!($response instanceof \CharlotteDunois\Yasmin\Models\Message || $response instanceof \CharlotteDunois\Yasmin\Utils\Collection || \is_array($response) || $response === null)) {
                        throw new \RuntimeException('Command '.$this->command->name.'\'s run() resolved with an unknown type ('.\gettype($response).'). Command run methods must return a Promise that resolve with a Message, an array of Messages, a Collection of Messages, or null.');
                    }
                    
                    if(!\is_array($response) && !($response instanceof \CharlotteDunois\Yasmin\Utils\Collection)) {
                        return $response;
                    }
                    
                    foreach($response as &$val) {
                        if(!($val instanceof \React\Promise\PromiseInterface)) {
                            $val = \React\Promise\resolve($val);
                        }
                    }
                    
                    return \React\Promise\all($response);
                });
            })->otherwise(function ($error) use (&$args, $typingCount) {
                if($this->message->channel->typingCount() > $typingCount) {
                    $this->message->channel->stopTyping();
                }
                
                if($error instanceof \CharlotteDunois\Livia\Exceptions\FriendlyException) {
                    return $this->reply($error->getMessage());
                }
                
                $this->client->emit('commandError', $this->command, $error, $this, $args, ($this->patternMatches !== null));
                
                $owners = $this->client->owners;
                $ownersLength = \count($owners);
                
                if($ownersLength > 0) {
                    $index = 0;
                    $owners = \array_map(function ($user) use ($index, $ownersLength) {
                        $or = ($ownersLength > 1 && $index === ($ownersLength - 1) ? 'or ' : '');
                        $index++;
                        
                        return $or.\CharlotteDunois\Yasmin\Utils\DataHelpers::escapeMarkdown($user->tag);
                    }, $owners);
                    
                    $owners = \implode((\count($owners) > 2 ? ', ' : ' '), $owners);
                } else {
                    $owners = 'the bot owner';
                }
                
                return $this->reply('An error occurred while running the command: `'.\get_class($error).': '.\str_replace('`', '', $error->getMessage()).'`'.\PHP_EOL.
                        'You shouldn\'t ever receive an error like this.'.\PHP_EOL.
                        'Please contact '.$owners.($this->client->getOption('invite') ? ' in this server: '.$this->client->getOption('invite') : '.'));
            })->done($resolve, $reject);
        }));
    }
    
    /**
     * Responds to the command message
     * @param string  $type      One of plain, reply or direct.
     * @param string  $content
     * @param array   $options
     * @param bool    $fromEdit
     * @return \React\Promise\ExtendedPromiseInterface
     * @throws \RangeException|\InvalidArgumentException
     */
    protected function respond(string $type, string $content, array $options = array(), bool $fromEdit = false) {
        if($type === 'reply' && $this->message->channel->type === 'dm') {
            $type = 'plain';
        }
        
        if($type !== 'direct' && $this->message->guild && $this->message->channel->permissionsFor($this->client->user)->has('SEND_MESSAGES') === false) {
            $type = 'direct';
        }
        
        if(!empty($options['split']) && !\is_array($options['split'])) {
            $options['split'] = array();
        }
        
        $channelID = $this->getChannelIDOrDM($this->message->channel);
        $shouldEdit = (!empty($this->responses) && (($type === 'direct' && !empty($this->responses['dm'])) || ($type !== 'direct' && !empty($this->responses[$channelID]))) && $fromEdit === false && empty($options['files']));
        
        switch($type) {
            case 'plain':
                if($shouldEdit) {
                    return $this->editCurrentResponse($channelID, $type, $content, $options);
                } else {
                    return $this->message->channel->send($content, $options);
                }
            break;
            case 'reply':
                if($shouldEdit) {
                    return $this->editCurrentResponse($channelID, $type, $content, $options);
                } else {
                    if(!empty($options['split']) && empty($options['split']['prepend'])) {
                        $options['split']['prepend'] = $this->message->author->__toString().\CharlotteDunois\Yasmin\Models\Message::$replySeparator;
                    }
                    
                    return $this->message->reply($content, $options);
                }
            break;
            case 'direct':
                if($shouldEdit) {
                    return $this->editCurrentResponse($channelID, $type, $content, $options);
                } else {
                    return $this->message->author->createDM()->then(function ($channel) use ($content, $options) {
                        return $channel->send($content, $options);
                    });
                }
            break;
            default:
                throw new \RangeException('Unknown response type "'.$type.'"');
            break;
        }
    }
    
    /**
     * Edits a response to the command message. Resolves with an instance of Message or an array of Message instances.
     * @param \CharlotteDunois\Yasmin\Models\Message|\CharlotteDunois\Yasmin\Models\Message[]  $response
     * @param string                                                                           $type
     * @param string                                                                           $content
     * @param array                                                                            $options
     * @return \React\Promise\ExtendedPromiseInterface
     */
    protected function editResponse($response, string $type, string $content, array $options = array()) {
        if(!$response) {
            return $this->respond($type, $content, $options);
        }
        
        if(!empty($options['split'])) {
            $content = \CharlotteDunois\Yasmin\Utils\DataHelpers::splitMessage($content, (\is_array($options['split']) ? $options['split'] : array()));
            if(\count($content) === 1) {
                $content = $content[0];
            }
        }
        
        $prepend = '';
        if($type === 'reply') {
            $prepend = $this->message->author->__toString().\CharlotteDunois\Yasmin\Models\Message::$replySeparator;
        }
        
        if(\is_array($content)) {
            $promises = array();
            $clength = \count($content);
            
            if(\is_array($response)) {
                for($i = 0;  $i < $clength; $i++) {
                    if(!empty($response[$i])) {
                        $promises[] = $response[$i]->edit($prepend.$content[$i], $options);
                    } else {
                        $promises[] = $this->message->channel->send($prepend.$content[$i], $options);
                    }
                }
            } else {
                $promises[] = $response->edit($prepend.$content, $options);
                for($i = 1; $i < $clength; $i++) {
                    $promises[] = $this->message->channel->send($prepend.$content[$i], $options);
                }
            }
            
            return \React\Promise\all($promises);
        } else {
            if(\is_array($response)) {
                for($i = \count($response) - 1;  $i > 0; $i--) {
                    $response[$i]->delete()->done();
                }
                
                return $response[0]->edit($prepend.$content, $options);
            } else {
                return $response->edit($prepend.$content, $options);
            }
        }
    }
    
    /**
     * Edits the current response.
     * @param string  $id       The ID of the channel the response is in ("DM" for direct messages).
     * @param string  $type
     * @param string  $content
     * @param array   $options
     * @return \React\Promise\ExtendedPromiseInterface
     */
    protected function editCurrentResponse(string $id, string $type, string $content, array $options = array()) {
        if(empty($this->responses[$id])) {
            $this->responses[$id] = array();
        }
        
        if(empty($this->responsePositions[$id])) {
            $this->responsePositions[$id] = -1;
        }
        $this->responsePositions[$id]++;
        
        return $this->editResponse(($this->responses[$id][$this->responsePositions[$id]] ?? null), $type, $content, $options);
    }
    
    /**
     * Responds with a plain message. Resolves with an instance of Message or an array of Message instances.
     * @param string  $content
     * @param array   $options  Message Options.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function say(string $content, array $options = array()) {
        return $this->respond('plain', $content, $options);
    }
    
    /**
     * Responds with a reply message. Resolves with an instance of Message or an array of Message instances.
     * @param string  $content
     * @param array   $options  Message Options.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function reply(string $content, array $options = array()) {
        return $this->respond('reply', $content, $options);
    }
    
    /**
     * Responds with a direct message. Resolves with an instance of Message or an array of Message instances.
     * @param string  $content
     * @param array   $options  Message Options.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function direct(string $content, array $options = array()) {
        return $this->respond('direct', $content, $options);
    }
    
    /**
     * Shortcut to $this->message->edit.
     * @param string  $content
     * @param array   $options  Message Options.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function edit(string $content, array $options = array()) {
        return $this->message->edit($content, $options);
    }
    
    /**
     * Finalizes the command message by setting the responses and deleting any remaining prior ones.
     * @param \CharlotteDunois\Yasmin\Models\Message|\CharlotteDunois\Yasmin\Models\Message[]|null  $responses
     * @internal
     */
    function finalize($responses) {
        if(!empty($this->responses)) {
            $this->deleteRemainingResponses();
        }
        
        $this->responses = array();
        $this->responsePositions = array();
        
        if(\is_array($responses)) {
            foreach($responses as $response) {
                $channel = (\is_array($response) ? $response[0] : $response)->channel;
                $id = $this->getChannelIDOrDM($channel);
                
                if(empty($this->responses[$id])) {
                    $this->responses[$id] = array();
                    $this->responsePositions[$id] = -1;
                }
                
                $this->responses[$id][] = $response;
            }
        } elseif($responses !== null) {
            $id = $this->getChannelIDOrDM($responses->channel);
            $this->responses[$id] = array($responses);
            $this->responsePositions[$id] = -1;
        }
    }
    
    /**
     * Deletes any prior responses that haven't been updated.
     * @internal
     */
    function deleteRemainingResponses() {
        foreach($this->responses as $id => $msg) {
            $cmsg = \count($msg);
            for($i = $this->responsePositions[$id] + 1; $i < $cmsg; $i++) {
                $response = $msg[$i];
                if(\is_array($response)) {
                    foreach($response as $resp) {
                        $resp->delete()->done();
                    }
                } else {
                    $response->delete()->done();
                }
            }
        }
    }
    
    protected function getChannelIDOrDM(\CharlotteDunois\Yasmin\Interfaces\TextChannelInterface $channel) {
        if($channel->type !== 'dm') {
            return $channel->id;
        }
        
        return 'dm';
    }
    
    /**
     * Parses an argument string into an array of arguments.
     * @param string          $argString
     * @param int|float|null  $argCount           float = \INF
     * @param bool            $allowSingleQuotes
     * @return string[]
     */
    static function parseArgs(string $argString, $argCount = null, bool $allowSingleQuotes = true) {
        if(\mb_strlen($argString) === 0) {
            return array();
        }
        
        $regex = ($allowSingleQuotes ? '/\s*(?:("|\')(.*?)\1|(\S+))\s*/u' : '/\s*(?:(")(.*?)"|(\S+))\s*/u');
        $results = array();
        
        $argString = \trim($argString);
        
        if($argCount === null) {
            $argCount = \mb_strlen($argString); // Large enough to get all items
        }
        
        $content = $argString;
        \preg_match_all($regex, $argString, $matches);
        foreach($matches[0] as $key => $val) {
            $argCount--;
            if($argCount === 0) {
                break;
            }
            
            $val = \trim((!empty($matches[2][$key]) ? $matches[2][$key] : $matches[3][$key]));
            $results[] = $val;
            
            $content = \trim(\preg_replace('/'.\preg_quote($val, '/').'/u', '', $content, 1));
        }
        
        // If text remains, push it to the array as-is (except for wrapping quotes, which are removed)
        if(\mb_strlen($content) > 0) {
            $results[] = \preg_replace(($allowSingleQuotes ? '/^("|\')(.*)\1$/u' : '/^(")(.*)"$/u'), '$2', $content);
        }
        
        if(\count($results) > 0) {
            $results = \array_filter($results, function ($val) {
                return (\mb_strlen(\trim($val)) > 0);
            });
        }
        
        return $results;
    }
    
    /**
     * @internal
     */
    function setResponses($responses, $responsePositions) {
        $this->responses = $responses;
        $this->responsePositions = $responsePositions;
    }
}
