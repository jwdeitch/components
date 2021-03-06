<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Tests\Cases\Encrypter;

use Spiral\Core\Configurator;
use Spiral\Encrypter\Encrypter;

class RandomTest extends \PHPUnit_Framework_TestCase
{
    public function testRandom()
    {
        $encrypter = $this->encrypter();

        $previousRandoms = [];
        for ($try = 0; $try < 100; $try++) {
            $random = $encrypter->random(32);
            $this->assertTrue(strlen($random) == 32);
            $this->assertNotContains($random, $previousRandoms);
            $previousRandoms[] = $random;
        }
    }

    /**
     * @param array $config
     * @return Encrypter
     */
    protected function encrypter($config = ['key' => '1234567890123456'])
    {
        return new Encrypter(new Configurator($config));
    }
}