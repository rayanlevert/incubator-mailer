<?php

/**
 * This file is part of the Phalcon Framework.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phalcon\Incubator\Mailer\Tests;

use InvalidArgumentException;
use Phalcon\Di\Injectable;
use Phalcon\Events\Event;
use Phalcon\Events\EventsAwareInterface;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Incubator\Mailer\Manager;
use Phalcon\Incubator\Mailer\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(Manager::class)]
class ManagerTest extends TestCase
{
    #[Test]
    #[TestDox('Test class inheritance from Injectable and implementing EventsAwareInterface')]
    public function inheritance(): void
    {
        $class = $this->createMock(Manager::class);

        $this->assertInstanceOf(Injectable::class, $class);
        $this->assertInstanceOf(EventsAwareInterface::class, $class);
    }

    #[Test]
    #[TestDox('Test instantiating the manager with an empty array -> exception with not a string driver')]
    public function constructArrayEmpty(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Driver must be a string value set from the config'));

        new Manager([]);
    }

    #[Test]
    #[TestDox('Test instantiating the manager with not a string driver -> InvalidArgumentException')]
    public function constructDriverNotAString(): void
    {
        foreach ([true, false, [], 3.14, fopen(__FILE__, 'r'), new \stdClass(), null] as $incorrectType) {
            try {
                new Manager(['driver' => $incorrectType]);

                $this->fail("incorrect type " . gettype($incorrectType) . ' has not triggered an exception');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Driver must be a string value set from the config', $e->getMessage());
            }
        }

        $this->assertSame(7, $this->getCount());
    }

    #[Test]
    #[TestDox('Test instantiating the manager with a driver not available by the manager -> exception')]
    public function constructDriverNotAvailable(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Driver-mail \'not-driver\' is not supported'));

        new Manager(['driver' => 'not-driver']);
    }

    #[Test]
    #[TestDox('Test instantiating the manager with smtp driver -> try to access methods')]
    public function smtpCorrectImplementation(): void
    {
        $manager = new Manager([
            'driver'        => 'smtp',
            'host'          => 'testhost',
            'port'          => 25,
            'encryption'    => 'ssl',
            'username'      => 'username',
            'password'      => 'password'
        ]);

        $this->assertNull($manager->getEventsManager());
        $this->assertSame('smtp', $manager->getMailer()->Mailer);
    }

    #[Test]
    #[TestDox('Test instantiating the manager with sendmail driver -> try to access methods')]
    public function sendmailCorrectImplementation(): void
    {
        $manager = new Manager(['driver' => 'sendmail']);

        $this->assertNull($manager->getEventsManager());
        $this->assertSame('sendmail', $manager->getMailer()->Mailer);
    }

    #[Test]
    #[TestDox('Test creating a message with the eventsManager set -> beforeCreateMessage and afterCreateMessage fired')]
    public function createMessageWithEventsManager(): void
    {
        $mailerManager = new Manager(['driver' => 'smtp']);

        $eventsManager = new EventsManager();
        $eventsManager->attach('mailer:beforeCreateMessage', function () {
            $this->assertSame(3, func_num_args());

            $this->assertInstanceOf(Event::class, func_get_arg(0)); // the event
            $this->assertInstanceOf(Manager::class, func_get_arg(1)); // the mailer manager
            $this->assertNull(func_get_arg(2)); // no params
        });

        $eventsManager->attach('mailer:afterCreateMessage', function () {
            $this->assertSame(3, func_num_args());

            $this->assertInstanceOf(Event::class, func_get_arg(0)); // the event
            $this->assertInstanceOf(Manager::class, func_get_arg(1)); // the mailer manager
            $this->assertInstanceOf(Message::class, func_get_arg(2)); // the message created
        });

        $mailerManager->setEventsManager($eventsManager);
        $mailerManager->createMessage();

        $this->assertSame(8, $this->getCount(), 'events were not all fired by the event manager');
        $this->assertNotNull($mailerManager->getEventsManager());
    }

    #[Test]
    #[TestDox('Test creating a message with from a string value -> from has only the email')]
    public function createMessageWithFromString(): void
    {
        $manager = new Manager([
            'driver' => 'smtp',
            'from'   => 'test@mail.com'
        ]);

        $message = $manager->createMessage();

        $this->assertSame('test@mail.com', $message->getFrom());
        $this->assertSame('', $message->getFromName());
    }

    #[Test]
    #[TestDox('Test creating a message with from an array value -> from has the email and the name')]
    public function createMessageWithFromArray(): void
    {
        $manager = new Manager([
            'driver' => 'smtp',
            'from'   => [
                'email' => 'test@mail.com',
                'name'  => 'John Doe'
            ]
        ]);

        $message = $manager->createMessage();

        $this->assertSame('test@mail.com', $message->getFrom());
        $this->assertSame('John Doe', $message->getFromName());
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with no view service from the Di set -> exception from Di\\Exception')]
    public function createMessageFromViewWithNoViewServiceSet(): void
    {
        $this->di->remove('view');

        $manager = new Manager(['driver' => 'smtp']);

        $this->expectException('Phalcon\Di\Exception');

        $manager->createMessageFromView('test');
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with a viewPath pointing on a non file -> exception from Mvc\\View\\Exception')]
    public function createMessageFromViewViewDoesNotExist(): void
    {
        $manager = new Manager(['driver' => 'smtp']);

        try {
            $manager->createMessageFromView('test');

            $this->fail('Exception from Phalcon\Mvc\View\Exception should have been thrown for view not existing');
        } catch (\Phalcon\Mvc\View\Exception $e) {
            $this->assertSame(
                'View \'' . self::VIEWS_DIR . '/test\' was not found in the views directory',
                $e->getMessage()
            );

            ob_get_clean();
        }
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with viewsDir not set from config -> picks the dir from the view service')]
    public function createMessageFromViewFromConfigWithNoViewsDir(): void
    {
        $manager = new Manager(['driver' => 'smtp']);

        // picks the view from the viewsDir of the view service from the Di
        $message = $manager->createMessageFromView(
            'mail/signup',
            ['var1' => 'first', 'var2' => 'second']
        );

        $this->assertSame('<b>first</b><b>second</b>', $message->getContent());
        $this->assertSame(self::VIEWS_DIR . '/', $this->di->get('view')->getViewsDir());
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with viewsDir from the third argument -> picks this directory')]
    public function createMessageFromViewViewsDirArgument(): void
    {
        $this->di->set('view', (new \Phalcon\Mvc\View())->setViewsDir('/some/directory'));

        $manager = new Manager(['driver' => 'smtp']);

        // picks the view from the viewsDir of the view service from the Di
        $message = $manager->createMessageFromView(
            'mail/signup',
            ['var1' => 'first', 'var2' => 'second'],
            self::VIEWS_DIR
        );

        // viewsDir from the Di must not be changed
        $this->assertSame('<b>first</b><b>second</b>', $message->getContent());
        $this->assertSame('/some/directory/', $this->di->get('view')->getViewsDir());
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with viewsDir set from config with no engines -> picks a .phtml view')]
    public function createMessageFromViewViewsDirSetFromConfigPhtml(): void
    {
        $manager = new Manager([
            'driver'   => 'smtp',
            'viewsDir' =>self::VIEWS_DIR
        ]);

        // gets the signup.phtml view
        $message = $manager->createMessageFromView('mail/signup', ['var1' => 'first', 'var2' => 'second']);
        $this->assertSame('<b>first</b><b>second</b>', $message->getContent());
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with viewsDir set from config with volt engine set -> picks a .volt view')]
    public function createMessageFromViewViewsDirSetFromConfigVolt(): void
    {
        $manager = new Manager([
            'driver'   => 'smtp',
            'viewsDir' => self::VIEWS_DIR
        ]);

        $manager->setViewEngines([
            '.volt' => fn(\Phalcon\Mvc\ViewBaseInterface $view) => new \Phalcon\Mvc\View\Engine\Volt($view)
        ]);

        // gets the signup.volt view
        $message = $manager->createMessageFromView('mail/signup', ['var1' => 'first', 'var2' => 'second']);
        $this->assertSame('<b>FIRST</b><b>SECOND</b>', $message->getContent());
    }

    #[Test]
    #[TestDox('Test ::createMessageFromView() with viewsDir set from config with volt engine set -> picks a .volt view')]
    public function createMessageFromViewTwoRendersDifferentViews(): void
    {
        $manager = new Manager([
            'driver'   => 'smtp',
            'viewsDir' => self::VIEWS_DIR
        ]);

        $message = $manager->createMessageFromView('mail/signup', ['var1' => 'first', 'var2' => 'second']);
        $this->assertSame('<b>first</b><b>second</b>', $message->getContent());

        $message = $manager->createMessageFromView('mail/signin', ['var1' => 'first', 'var2' => 'second']);
        $this->assertSame('<b>second</b><b>first</b>', $message->getContent());
    }
}
