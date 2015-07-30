<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Session\Handlers;

use Spiral\Files\FilesInterface;

class FileHandler implements \SessionHandlerInterface
{
    /**
     * Session data directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * FileManager component.
     *
     * @var FilesInterface
     */
    protected $files = null;

    /**
     * New session handler instance.
     * PHP >= 5.4.0
     *
     * @param array          $options    Session handler options.
     * @param int            $lifetime   Default session lifetime.
     * @param FilesInterface $fileFacade FileManager component.
     */
    public function __construct(array $options, $lifetime = 0, FilesInterface $fileFacade = null)
    {
        $this->directory = $options['directory'];
        $this->files = $fileFacade;
    }

    /**
     * Close the session, the return value (usually TRUE on success, FALSE on failure). Note this
     * value is returned internally to PHP for processing.
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     * @return bool
     */
    public function close()
    {
        return true;
    }

    /**
     * Destroy a session, The return value (usually TRUE on success, FALSE on failure). Note this
     * value is returned internally to PHP for processing.
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.destroy.php
     * @param int $session_id The session ID being destroyed.
     * @return bool
     */
    public function destroy($session_id)
    {
        return $this->files->delete($this->directory . '/' . $session_id);
    }

    /**
     * Cleanup old sessions. The return value (usually TRUE on success, FALSE on failure). Note this
     * value is returned internally to PHP for processing.
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime Sessions that have not updated for the last maxlifetime seconds will
     *                         be removed.
     * @return bool
     */
    public function gc($maxlifetime)
    {
        foreach ($this->files->getFiles($this->directory) as $filename)
        {
            if ($this->files->time($filename) < time() - $maxlifetime)
            {
                $this->files->delete($filename);
            }
        }
    }

    /**
     * Initialize session. The return value (usually TRUE on success, FALSE on failure). Note this
     * value is returned internally to PHP for processing.
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.open.php
     * @param string $save_path  The path where to store/retrieve the session.
     * @param string $session_id The session id.
     * @return bool
     */
    public function open($save_path, $session_id)
    {
        return true;
    }

    /**
     * Read session data. Returns an encoded string of the read data. If nothing was read, it must
     * return an empty string. Note this value is returned internally to PHP for processing.
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $session_id The session id to read data for.
     * @return string
     */
    public function read($session_id)
    {
        return $this->files->exists($this->directory . '/' . $session_id)
            ? $this->files->read($this->directory . '/' . $session_id)
            : false;
    }

    /**
     * Write session data. The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $session_id   The session id.
     * @param string $session_data The encoded session data. This data is the result of the PHP
     *                             internally encoding the $_SESSION superglobal to a serialized string
     *                             and passing it as this parameter. Please note sessions use an
     *                             alternative serialization method.
     * @return bool
     */
    public function write($session_id, $session_data)
    {
        try
        {
            return $this->files->write($this->directory . '/' . $session_id, $session_data);
        }
        catch (\ErrorException $exception)
        {
            //Possibly that directory doesn't exists, we don't want to force directory by default,
            //but we can try now.
            return $this->files->write(
                $this->directory . '/' . $session_id,
                $session_data,
                FilesInterface::RUNTIME,
                true
            );
        }
    }
}