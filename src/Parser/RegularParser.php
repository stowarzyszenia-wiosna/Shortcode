<?php
namespace Thunder\Shortcode\Parser;

use Thunder\Shortcode\Shortcode\ParsedShortcode;
use Thunder\Shortcode\Shortcode\Shortcode;
use Thunder\Shortcode\Syntax\CommonSyntax;
use Thunder\Shortcode\Syntax\SyntaxInterface;

/**
 * @author Tomasz Kowalczyk <tomasz@kowalczyk.cc>
 */
final class RegularParser implements ParserInterface
{
    private $syntax;
    private $lexerRules;
    /** @var \SplStack */
    private $tokens;
    private $backtracks;

    const TOKEN_OPEN = 1;
    const TOKEN_CLOSE = 2;
    const TOKEN_MARKER = 3;
    const TOKEN_SEPARATOR = 4;
    const TOKEN_DELIMITER = 5;
    const TOKEN_STRING = 6;
    const TOKEN_WS = 7;

    public function __construct(SyntaxInterface $syntax = null)
    {
        $this->syntax = $syntax ?: new CommonSyntax();

        $quote = function($text) { return preg_replace('/(.)/us', '\\\\$0', $text); };

        $this->lexerRules = array(
            self::TOKEN_OPEN => $quote($this->syntax->getOpeningTag()),
            self::TOKEN_CLOSE => $quote($this->syntax->getClosingTag()),
            self::TOKEN_MARKER => $quote($this->syntax->getClosingTagMarker()),
            self::TOKEN_SEPARATOR => $quote($this->syntax->getParameterValueSeparator()),
            self::TOKEN_DELIMITER => $quote($this->syntax->getParameterValueDelimiter()),
            self::TOKEN_WS => '\s+',
            self::TOKEN_STRING => '[\w-]+|\\\\.|.',
        );
    }

    /**
     * @param string $text
     *
     * @return ParsedShortcode[]
     */
    public function parse($text)
    {
        $this->tokens = $this->tokenize($text);
        $this->backtracks = array();

        $shortcodes = array();
        while(false === $this->isEof()) {
            while(!$this->isEof() && !$this->lookahead(self::TOKEN_OPEN)) {
                $this->tokens->pop();
            }
            if($shortcode = $this->shortcode(true)) {
                $shortcodes[] = $shortcode;
            }
        }

        return $shortcodes;
    }

    private function addShortcode($name, $parameters, $content, $text, $offset, $positions)
    {
        return new ParsedShortcode(new Shortcode($name, $parameters, $content), $text, $offset, $positions);
    }

    /* --- RULES ----------------------------------------------------------- */

    private function shortcode($isRoot)
    {
        $baseOffset = $this->getPosition();

        $name = null;
        $bbCode = '';
        $content = null;
        $nameClose = null;
        $offset = null;
        $positions = array();

        $setName = function(array $token) use(&$name) { $name = $token[1]; };
        $setOffset = function(array $token) use(&$offset) { $offset = $token[2]; };
        $setClosingName = function(array $token) use(&$nameClose) { $nameClose = $token[1]; };
        $setNamePosition = function(array $token) use(&$positions, $baseOffset) { $positions['name'] = $token[2] - $baseOffset; };
        $setMarkerPosition = function(array $token) use(&$positions, $baseOffset) { $positions['marker'] = $token[2] - $baseOffset; };

        !$isRoot ?: $this->beginBacktrack();
        if(!$this->match(self::TOKEN_OPEN, $setOffset, true)) { return false; }
        if(!$this->match(self::TOKEN_STRING, array($setName, $setNamePosition), true)) { return false; }
        if(false === ($bbCode = $this->bbCode($bbCode))) { return false; }
        $positions['parameters'] = $this->getPosition() - $baseOffset;
        if(false === ($arguments = $this->arguments())) { return false; }

        // self-closing
        if($this->match(self::TOKEN_MARKER, $setMarkerPosition, true)) {
            if(!$this->match(self::TOKEN_CLOSE)) { return false; }

            return $isRoot ? $this->addShortcode($name, $arguments, null, $this->getBacktrack(), $offset, $positions) : null;

        // just-closed or with-content
        } elseif($this->match(self::TOKEN_CLOSE)) {
            $this->beginBacktrack();
            $positions['content'] = $this->getPosition() - $baseOffset;
            if(false === ($content = $this->content($name))) {
                $this->backtrack();
                $positions['content'] = null;

                return $isRoot ? $this->addShortcode($name, $arguments, null, $this->getBacktrack(), $offset, $positions) : null;
            }
            $this->discardBacktrack();
            if(!$this->match(self::TOKEN_OPEN, null, true)) { return false; }
            if(!$this->match(self::TOKEN_MARKER, $setMarkerPosition, true)) { return false; }
            if(!$this->match(self::TOKEN_STRING, $setClosingName, true)) { return false; }
            if(!$this->match(self::TOKEN_CLOSE)) { return false; }
            if($name !== $nameClose) { return false; }

        // u wot m8?
        } else { return false; }

        return $isRoot ? $this->addShortcode($name, $arguments, $content, $this->getBacktrack(), $offset, $positions) : null;
    }

    private function content($name)
    {
        $content = null;
        $appendContent = function(array $token) use(&$content) { $content .= $token[1]; };

        while(true) {
            while($this->match(array(self::TOKEN_STRING, self::TOKEN_WS), $appendContent));

            $this->beginBacktrack();
            if(false !== $this->shortcode(false)) {
                $content .= $this->getBacktrack();
                continue;
            }
            $this->backtrack();

            $this->beginBacktrack();
            if(false !== $this->close($name)) {
                if(null === $content) { $content = ''; }
                $this->backtrack();
                break;
            }
            $this->backtrack();

            $this->match(null, $appendContent);

            if($this->isEof()) {
                return false;
            }
        }

        return $content;
    }

    private function close($openingName)
    {
        $closingName = null;
        $setName = function(array $token) use(&$closingName) { $closingName = $token[1]; };

        if(!$this->match(self::TOKEN_OPEN, null, true)) { return false; }
        if(!$this->match(self::TOKEN_MARKER, null, true)) { return false; }
        if(!$this->match(self::TOKEN_STRING, $setName, true)) { return false; }
        if(!$this->match(self::TOKEN_CLOSE)) { return false; }

        return $openingName === $closingName;
    }

    private function bbCode()
    {
        $value = '';
        $appendValue = function(array $token) use(&$value) { $value = $token[1]; };

        if(!$this->match(self::TOKEN_SEPARATOR)) { return null; }
        if(!$this->value($appendValue)) { return false; }

        return $value;
    }

    private function arguments()
    {
        $setName = function(array $token) use(&$name) { $name = $token[1]; };
        $appendValue = function(array $token) use(&$value) { $value .= $token[1]; };

        $arguments = array();

        while(true) {
            $name = null;
            $value = '';

            $this->match(self::TOKEN_WS);
            if($this->lookahead(array(self::TOKEN_MARKER, self::TOKEN_CLOSE))) { break; }
            if(!$this->match(self::TOKEN_STRING, $setName, true)) { return false; }
            if(!$this->match(self::TOKEN_SEPARATOR, null, true)) { $arguments[$name] = null; continue; }
            if(!$this->value($appendValue)) { return false; }
            $this->match(self::TOKEN_WS);

            $arguments[$name] = $value;
        }

        return $arguments;
    }

    private function value($callback)
    {
        if($this->match(self::TOKEN_DELIMITER)) {
            while(!$this->lookahead(self::TOKEN_DELIMITER)) {
                $this->match(null, $callback);
            }

            return $this->match(self::TOKEN_DELIMITER);
        }

        return $this->match(self::TOKEN_STRING, $callback);
    }

    /* --- PARSER ---------------------------------------------------------- */

    private function discardBacktrack()
    {
        return array_pop($this->backtracks);
    }

    private function beginBacktrack()
    {
        array_push($this->backtracks, array());
    }

    private function getBacktrack()
    {
        $tokenToString = function(array $token) { return $token[1]; };

        return implode('', array_map($tokenToString, $this->discardBacktrack()));
    }

    private function backtrack()
    {
        foreach(array_reverse($this->discardBacktrack()) as $token) {
            $this->tokens->push($token);

            foreach($this->backtracks as &$backtrack) {
                array_pop($backtrack);
            }
        }
    }

    private function getPosition()
    {
        if($this->isEof()) {
            return null;
        }

        $token = $this->tokens->top();

        return $token[2];
    }

    private function isEof()
    {
        return $this->tokens->isEmpty();
    }

    private function lookahead($type, $callback = null)
    {
        if($this->isEof()) {
            return false;
        }
        if(!is_array($type)) {
            $type = array($type);
        }

        $token = $this->tokens->top();
        if(!empty($type) && !in_array($token[0], $type)) {
            return false;
        }

        /** @var $callback callable */
        !$callback ?: $callback($token);

        return true;
    }

    private function match($type, $callbacks = null, $ws = false)
    {
        if($this->isEof()) {
            return false;
        }
        if(!is_array($type)) {
            $type = (array)$type;
        }

        $token = $this->tokens->top();
        if(!empty($type) && !in_array($token[0], $type)) {
            return false;
        }
        foreach($this->backtracks as &$backtrack) {
            array_push($backtrack, $token);
        }

        $this->tokens->pop();
        foreach((array)$callbacks as $callback) {
            $callback($token);
        }

        !$ws ?: $this->match(self::TOKEN_WS);

        return true;
    }

    /* --- LEXER ----------------------------------------------------------- */

    private function tokenize($text)
    {
        $tokens = new \SplStack();
        $position = 0;

        while(mb_strlen($text) > 0) {
            foreach($this->lexerRules as $token => $regex) {
                if(preg_match('~^('.$regex.')~us', $text, $matches)) {
                    $tokens->unshift(array($token, $matches[0], $position));
                    $text = mb_substr($text, mb_strlen($matches[0]));
                    $position += mb_strlen($matches[0]);
                    break;
                }
            }
        }

        return $tokens;
    }
}
