<?php
namespace Thunder\Shortcode\Tests;

use Thunder\Shortcode\Handler\ContentHandler;
use Thunder\Shortcode\Handler\DeclareHandler;
use Thunder\Shortcode\Handler\EmailHandler;
use Thunder\Shortcode\Handler\NameHandler;
use Thunder\Shortcode\Handler\NullHandler;
use Thunder\Shortcode\Handler\PlaceholderHandler;
use Thunder\Shortcode\Handler\SerializerHandler;
use Thunder\Shortcode\Handler\UrlHandler;
use Thunder\Shortcode\Handler\WrapHandler;
use Thunder\Shortcode\HandlerContainer\HandlerContainer;
use Thunder\Shortcode\Parser\RegexParser;
use Thunder\Shortcode\Processor\Processor;
use Thunder\Shortcode\Serializer\JsonSerializer;
use Thunder\Shortcode\Serializer\TextSerializer;
use Thunder\Shortcode\Shortcode\ProcessedShortcode;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;
use Thunder\Shortcode\Tests\Fake\ReverseShortcode;

/**
 * @author Tomasz Kowalczyk <tomasz@kowalczyk.cc>
 */
final class ProcessorTest extends \PHPUnit_Framework_TestCase
{
    private function getProcessor()
    {
        $handlers = new HandlerContainer();
        $handlers
            ->add('name', function (ShortcodeInterface $s) { return $s->getName(); })
            ->add('content', function (ShortcodeInterface $s) { return $s->getContent(); })
            ->add('reverse', new ReverseShortcode())
            ->add('url', function(ShortcodeInterface $s) {
                $url = $s->getParameter('url', $s->getBbCode());

                return '<a href="'.$url.'">'.$url.'</a>';
            })
            ->addAlias('c', 'content')
            ->addAlias('n', 'name');

        return new Processor(new RegexParser(), $handlers);
    }

    public function testReplaceWithoutContentOffset()
    {
        $text = ' [x value=" [name]yyy[/name] "] [name]yyy[/name] [/x] ';
        $result = ' [x value=" [name]yyy[/name] "] name [/x] ';

        $this->assertSame($result, $this->getProcessor()->process($text));
    }

    /**
     * @dataProvider provideTexts
     */
    public function testProcessorProcess($text, $result)
    {
        $this->assertSame($result, $this->getProcessor()->process($text));
    }

    public function provideTexts()
    {
        return array(
            array('[name]', 'name'),
            array('[content]random[/content]', 'random'),
            array('[name]random[/other]', 'namerandom[/other]'),
            array('[name][other]random[/other]', 'name[other]random[/other]'),
            array('[content]random-[name]-random[/content]', 'random-name-random'),
            array('random [content]other[/content] various', 'random other various'),
            array('x [content]a-[name]-b[/content] y', 'x a-name-b y'),
            array('x [c]a-[n][/n]-b[/c] y', 'x a-n-b y'),
            array('x [content]a-[c]v[/c]-b[/content] y', 'x a-v-b y'),
            // array('x [html b]bold[/html] y [html code]code[/html] z', 'x <b>bold</b> y <code>code</code> z'),
            array('x [html]bold[/html] z', 'x [html]bold[/html] z'),
            array('x [reverse]abc xyz[/reverse] z', 'x zyx cba z'),
            array('x [i /][i]i[/i][i /][i]i[/i][i /] z', 'x [i /][i]i[/i][i /][i]i[/i][i /] z'),
            array('x [url url="http://giggle.com/search" /] z', 'x <a href="http://giggle.com/search">http://giggle.com/search</a> z'),
            array('x [url="http://giggle.com/search"] z', 'x <a href="http://giggle.com/search">http://giggle.com/search</a> z'),
            );
    }

    public function testProcessorParentContext()
    {
        $handlers = new HandlerContainer();
        $handlers->add('outer', function (ProcessedShortcode $s) {
            $name = $s->getParent() ? $s->getParent()->getName() : 'root';

            return $name.'['.$s->getContent().']';
            });
        $handlers->addAlias('inner', 'outer');
        $handlers->addAlias('level', 'outer');

        $processor = new Processor(new RegexParser(), $handlers);

        $text = 'x [outer]a [inner]c [level]x[/level] d[/inner] b[/outer] y';
        $result = 'x root[a outer[c inner[x] d] b] y';
        $this->assertSame($result, $processor->process($text));
        $this->assertSame($result.$result, $processor->process($text.$text));
    }

    public function testProcessorWithoutRecursion()
    {
        $result = $this
            ->getProcessor()
            ->withRecursionDepth(0)
            ->process('x [content]a-[name][/name]-b[/content] y');

        $this->assertSame('x a-[name][/name]-b y', $result);
    }

    public function testProcessContentIfHasChildHandlerButNotParent()
    {
        $handlers = new HandlerContainer();
        $handlers->add('valid', function (ShortcodeInterface $s) { return $s->getName(); });

        $text = 'x [invalid   ] [valid /] [/invalid] y';
        $processor = new Processor(new RegexParser(), $handlers);

        $this->assertSame('x [invalid   ] valid [/invalid] y', $processor->withAutoProcessContent(true)->process($text));
        $this->assertSame('x [invalid   ] [valid /] [/invalid] y', $processor->withAutoProcessContent(false)->process($text));
    }

    public function testProcessorWithoutContentAutoProcessing()
    {
        $result = $this
            ->getProcessor()
            ->withAutoProcessContent(false)
            ->process('x [content]a-[name][/name]-b[/content] y');

        $this->assertSame('x a-[name][/name]-b y', $result);
    }

    public function testProcessorShortcodePositions()
    {
        $handlers = new HandlerContainer();
        $handlers->add('p', function (ProcessedShortcode $s) { return $s->getPosition(); });
        $handlers->add('n', function (ProcessedShortcode $s) { return $s->getNamePosition(); });
        $processor = new Processor(new RegexParser(), $handlers);

        $this->assertSame('123', $processor->process('[n][n][n]'), '3n');
        $this->assertSame('123', $processor->process('[p][p][p]'), '3p');
        $this->assertSame('113253', $processor->process('[p][n][p][n][p][n]'), 'pnpnpn');
        $this->assertSame('1231567', $processor->process('[p][p][p][n][p][p][p]'), 'pppnppp');
    }

    /**
     * @dataProvider provideBuiltInTests
     */
    public function testBuiltInHandlers($text, $result)
    {
        $handlers = new HandlerContainer();
        $handlers
            ->add('content', new ContentHandler())
            ->add('name', new NameHandler())
            ->add('null', new NullHandler())
            ->add('json', new SerializerHandler(new JsonSerializer()))
            ->add('text', new SerializerHandler(new TextSerializer()))
            ->add('placeholder', new PlaceholderHandler())
            ->add('b', new WrapHandler('<b>', '</b>'))
            ->add('declare', new DeclareHandler($handlers))
            ->add('url', new UrlHandler())
            ->add('email', new EmailHandler());
        $processor = new Processor(new RegexParser(), $handlers);

        $this->assertSame($result, $processor->process($text));
    }

    public function provideBuiltInTests()
    {
        return array(
            array('[declare date]%year%.%month%.%day%[/declare][date year=2015 month=08 day=26]', '2015.08.26'),
            array('[declare sample]%param%[/declare][invalid param=value]', '[invalid param=value]'),
            array('[declare]%param%[/declare][invalid param=value]', '[invalid param=value]'),
            array('[url]http://kowalczyk.cc[/url]', '<a href="http://kowalczyk.cc">http://kowalczyk.cc</a>'),
            array('[url title="Visit!"]http://kowalczyk.cc[/url]', '<a href="http://kowalczyk.cc">Visit!</a>'),
            array('[email]tomasz@kowalczyk.cc[/email]', '<a href="mailto:tomasz@kowalczyk.cc">tomasz@kowalczyk.cc</a>'),
            array('[email title="Send!"]tomasz@kowalczyk.cc[/email]', '<a href="mailto:tomasz@kowalczyk.cc">Send!</a>'),
            array('[b]text[/b]', '<b>text</b>'),
            array('[b]text[/b]', '<b>text</b>'),
            array('[json arg=val]value[/json]', '{"name":"json","parameters":{"arg":"val"},"content":"value"}'),
            array('[text arg=val]value[/text]', '[text arg=val]value[/text]'),
            array('[null arg=val]value[/null]', ''),
            array('[name /]', 'name'),
            array('[content]cnt[/content]', 'cnt'),
            array('[placeholder param=val]%param%[/placeholder]', 'val'),
        );
    }

    public function testProcessorDeclare()
    {
        $handlers = new HandlerContainer();
        $handlers->add('declare', function (ProcessedShortcode $s) use ($handlers) {
            $handlers->add($s->getParameterAt(0), function (ShortcodeInterface $x) use ($s) {
                $keys = array_map(function ($item) {
                    return '%'.$item.'%';
                    }, array_keys($x->getParameters()));
                $values = array_values($x->getParameters());

                return str_replace($keys, $values, $s->getContent());
                });
            });
        $processor = new Processor(new RegexParser(), $handlers);

        $this->assertSame('You are 18 years old.', trim($processor->process('
            [declare age]You are %age% years old.[/declare]
            [age age=18]
            ')));
    }

    public function testProcessorIterative()
    {
        $handlers = new HandlerContainer();
        $handlers
            ->add('name', function (ShortcodeInterface $s) { return $s->getName(); })
            ->add('content', function (ShortcodeInterface $s) { return $s->getContent(); })
            ->addAlias('c', 'content')
            ->addAlias('n', 'name')
            ->addAlias('d', 'c')
            ->addAlias('e', 'c');
        $processor = new Processor(new RegexParser(), $handlers);

        /** @var $processor Processor */
        $processor = $processor->withRecursionDepth(0)->withMaxIterations(2);
        $this->assertSame('x a y', $processor->process('x [c]a[/c] y'));
        $this->assertSame('x abc y', $processor->process('x [c]a[d]b[/d]c[/c] y'));
        $this->assertSame('x ab[e]c[/e]de y', $processor->process('x [c]a[d]b[e]c[/e]d[/d]e[/c] y'));

        $processor = $processor->withMaxIterations(null);
        $this->assertSame('x abcde y', $processor->process('x [c]a[d]b[e]c[/e]d[/d]e[/c] y'));
    }

    public function testExceptionOnInvalidRecursionDepth()
    {
        $processor = $this->getProcessor();
        $this->setExpectedException('InvalidArgumentException');
        $processor->withRecursionDepth(new \stdClass());
    }

    public function testExceptionOnInvalidMaxIterations()
    {
        $processor = $this->getProcessor();
        $this->setExpectedException('InvalidArgumentException');
        $processor->withMaxIterations(new \stdClass());
    }

    public function testExceptionOnInvalidAutoProcessFlag()
    {
        $processor = $this->getProcessor();
        $this->setExpectedException('InvalidArgumentException');
        $processor->withAutoProcessContent(new \stdClass());
    }

    public function testDefaultHandler()
    {
        $handlers = new HandlerContainer();
        $handlers->setDefault(function (ShortcodeInterface $s) { return $s->getName(); });
        $processor = new Processor(new RegexParser(), $handlers);

        $this->assertSame('namerandom', $processor->process('[name][other][/name][random]'));
    }

    public function testPreventInfiniteLoop()
    {
        $handlers = new HandlerContainer();
        $handlers
            ->add('name', function (ShortcodeInterface $s) { return $s->getName(); })
            ->add('content', function (ShortcodeInterface $s) { return $s->getContent(); })
            ->add('reverse', new ReverseShortcode())
            ->addAlias('c', 'content')
            ->addAlias('n', 'name')
            ->add('self', function () { return '[self]'; })
            ->add('other', function () { return '[self]'; })
            ->add('random', function () { return '[various]'; });
        $processor = new Processor(new RegexParser(), $handlers);
        $processor->withMaxIterations(null);

        $processor->process('[self]');
        $processor->process('[other]');
        $processor->process('[random]');
    }
}
