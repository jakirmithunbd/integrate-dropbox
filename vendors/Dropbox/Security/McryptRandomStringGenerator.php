<?php

namespace CodeConfig\IDB\Dropbox\Security;

use CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException;

/**
 * @inheritdoc
 */
class McryptRandomStringGenerator implements RandomStringGeneratorInterface
{
    use RandomStringGeneratorTrait;

    /**
     * The error message when generating the string fails.
     *
     * @const string
     */
    public const ERROR_MESSAGE = 'Unable to generate a cryptographically secure pseudo-random string from mcrypt_create_iv(). ';

    /**
     * Create a new McryptRandomStringGenerator instance
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     */
    public function __construct()
    {
        if (! function_exists('mcrypt_create_iv')) {
            throw new DropboxClientException(
                esc_html(static::ERROR_MESSAGE) .
                'The function mcrypt_create_iv() does not exist.'
            );
        }
    }

    /**
     * Get a randomly generated secure token
     *
     * @param int $length Length of the string to return
     *
     * @throws \CodeConfig\IDB\Dropbox\Exceptions\DropboxClientException
     *
     * @return string
     */
    public function generateString($length)
    {

        if (!function_exists('mcrypt_create_iv')) {
            throw new DropboxClientException(
                esc_html(static::ERROR_MESSAGE) .
                'The function mcrypt_create_iv() does not exist.'
            );
        }

        if (!defined('MCRYPT_DEV_URANDOM')) {
            throw new DropboxClientException(
                esc_html(static::ERROR_MESSAGE) .
                'The constant MCRYPT_DEV_URANDOM is not defined.'
            );
        }

        //Create Binary String
        if (function_exists('mcrypt_create_iv') && defined('MCRYPT_DEV_URANDOM')) {
            $binaryString = mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
        } else {
            throw new DropboxClientException(
                esc_html(static::ERROR_MESSAGE) .
                'The function mcrypt_create_iv() or the constant MCRYPT_DEV_URANDOM is not available.'
            );
        }

        //Unable to create binary string
        if ($binaryString === false) {
            throw new DropboxClientException(
                esc_html(static::ERROR_MESSAGE) .
                'mcrypt_create_iv() returned an error.'
            );
        }

        //Convert binary to hex
        return $this->binToHex($binaryString, $length);
    }
}
