<?php

/**
 * Widget to share the product in Facebook and get a personalized discount
 *
 * @package   SWE_Optipricer
 * @author    Ubiprism Lda. / be.ubi <contact@beubi.com>
 * @copyright 2015 be.ubi
 * @license   GNU Lesser General Public License (LGPL)
 * @version   v.0.1.2
 */
class SWE_Optipricer_Helper_Securedata extends Mage_Core_Helper_Abstract
{
    const KEY_FILENAME = 'swe_public_key.pem';

    const CIPHER_ALG      = MCRYPT_RIJNDAEL_128;
    const CIPHER_MODE     = MCRYPT_MODE_CBC;
    const CIPHER_ALG_MODE = "AES-128-CBC";

    const SIGN_ALG = OPENSSL_ALGO_SHA256;

    const SECURE_CIPHER      = 1;
    const SECURE_SIGN        = 2;
    const SECURE_CIPHER_SIGN = 3;

    private static $cipherArray = array(
        "AES-128-CBC"  => array("alg" => MCRYPT_RIJNDAEL_128, "mode" => MCRYPT_MODE_CBC),
        "AES-128-CFB"  => array("alg" => MCRYPT_RIJNDAEL_128, "mode" => MCRYPT_MODE_CFB),
        "AES-128-OFB"  => array("alg" => MCRYPT_RIJNDAEL_128, "mode" => MCRYPT_MODE_OFB),
        "AES-128-ECB"  => array("alg" => MCRYPT_RIJNDAEL_128, "mode" => MCRYPT_MODE_ECB),
        "AES-192-CBC"  => array("alg" => MCRYPT_RIJNDAEL_192, "mode" => MCRYPT_MODE_CBC),
        "AES-192-CFB"  => array("alg" => MCRYPT_RIJNDAEL_192, "mode" => MCRYPT_MODE_CFB),
        "AES-192-OFB"  => array("alg" => MCRYPT_RIJNDAEL_192, "mode" => MCRYPT_MODE_OFB),
        "AES-192-ECB"  => array("alg" => MCRYPT_RIJNDAEL_192, "mode" => MCRYPT_MODE_ECB),
        "AES-256-CBC"  => array("alg" => MCRYPT_RIJNDAEL_256, "mode" => MCRYPT_MODE_CBC),
        "AES-256-CFB"  => array("alg" => MCRYPT_RIJNDAEL_256, "mode" => MCRYPT_MODE_CFB),
        "AES-256-OFB"  => array("alg" => MCRYPT_RIJNDAEL_256, "mode" => MCRYPT_MODE_OFB),
        "AES-256-ECB"  => array("alg" => MCRYPT_RIJNDAEL_256, "mode" => MCRYPT_MODE_ECB),
        "BF-CBC"       => array("alg" => MCRYPT_BLOWFISH, "mode" => MCRYPT_MODE_CBC),
        "BF-CFB"       => array("alg" => MCRYPT_BLOWFISH, "mode" => MCRYPT_MODE_CFB),
        "BF-OFB"       => array("alg" => MCRYPT_BLOWFISH, "mode" => MCRYPT_MODE_OFB),
        "BF-ECB"       => array("alg" => MCRYPT_BLOWFISH, "mode" => MCRYPT_MODE_ECB),
        "CAST-128-CBC" => array("alg" => MCRYPT_CAST_128, "mode" => MCRYPT_MODE_CBC),
        "CAST-128-CFB" => array("alg" => MCRYPT_CAST_128, "mode" => MCRYPT_MODE_CFB),
        "CAST-128-OFB" => array("alg" => MCRYPT_CAST_128, "mode" => MCRYPT_MODE_OFB),
        "CAST-128-ECB" => array("alg" => MCRYPT_CAST_128, "mode" => MCRYPT_MODE_ECB),
        "CAST-256-CBC" => array("alg" => MCRYPT_CAST_256, "mode" => MCRYPT_MODE_CBC),
        "CAST-256-CFB" => array("alg" => MCRYPT_CAST_256, "mode" => MCRYPT_MODE_CFB),
        "CAST-256-OFB" => array("alg" => MCRYPT_CAST_256, "mode" => MCRYPT_MODE_OFB),
        "CAST-256-ECB" => array("alg" => MCRYPT_CAST_256, "mode" => MCRYPT_MODE_ECB),
    );

    /**
     * Method to secure content (can use different security methods)
     *
     * @param Int         $task    Task
     * @param string      $content Content
     * @param bool|string $key     Key
     * @param bool|string $privKey PrivateKey
     *
     * @return array|bool|string
     */
    public static function secureContent($task, $content, $key = false, $privKey = false)
    {
        switch($task)
        {
            case self::SECURE_CIPHER:
                return self::encryptContent($key,$content);
                break;
            case self::SECURE_SIGN:
                return self::signContent($privKey, $content);
                break;
            case self::SECURE_CIPHER_SIGN:
                return self::encryptSignContent($key, $privKey, $content);
                break;
            default:
                return false;
        }
    }

    /**
     * Get content of a secured data object
     *
     * @param string      $content Content
     * @param bool|string $key     Key flag
     * @param bool|string $publicKey PublicKey flag
     *
     * @return bool|string
     */
    public static function getContent($content, $key = false, $publicKey = false)
    {
        if (is_array($content) || is_object($content)) {
            return false;
        }

        $data = base64_decode($content, true);
        if (!$data) {
            //Content is not a base64 encode object
            $data = $content;
        }

        $data = json_decode($data);
        if (!$data) {
            return false;
        }

        $mode = isset($data->smode) ? $data->smode : self::SECURE_CIPHER;
        if ($mode == self::SECURE_CIPHER && !isset($data->iv)) {
            return false;
        }

        if (!isset($data->content) && !isset($data->cipher)) {
            return false;
        }
        $obj = isset($data->content) ? $data->content : $data->cipher;

        switch($mode)
        {
            case self::SECURE_CIPHER:
                return self::decryptContent($key, $data);
                break;
            case self::SECURE_SIGN:
                if(self::verifySignature($publicKey, $data)) {
                    return $obj;
                }
                else {
                    return false;
                }
                break;
            case self::SECURE_CIPHER_SIGN:
                return self::decryptVerifyContent($key, $publicKey, $data);
                break;
            default:
                return false;
        }
    }

    /**
     * Encrypt content
     *
     * @param string $key     Key
     * @param string $content Content
     *
     * @return string
     */
    private static function encryptContent($key, $content)
    {
        $ivSize = mcrypt_get_iv_size(self::CIPHER_ALG, self::CIPHER_MODE);
        $iv = $ivSize > 0 ? mcrypt_create_iv($ivSize, MCRYPT_DEV_URANDOM) : '';

        // creates a cipher text compatible with AES (Rijndael block size = 128) to keep the text confidential
        // only suitable for encoded input that never ends with value 00h (because of default zero padding)
        try {
            $cipherText = mcrypt_encrypt(self::CIPHER_ALG, $key, $content, self::CIPHER_MODE, $iv);
        } catch(\Exception $e) {
            return false;
        }

        $hashedKey = hash('sha256', $key);

        $hmac = hash_hmac('sha256', base64_encode($cipherText) . base64_encode($iv), $hashedKey);

        // prepend the IV for it to be available for decryption
        $cipherTextArray = array(
            'content' => base64_encode($cipherText),
            'iv'      => base64_encode($iv),
            'hmac'    => $hmac,
            'alg'     => self::CIPHER_ALG_MODE,
            'smode'   => self::SECURE_CIPHER
        );

        $cipherTextArray = base64_encode(json_encode($cipherTextArray));

        return $cipherTextArray;
    }

    /**
     * Decrypt content
     *
     * @param string $key  Key
     * @param object $data Data
     *
     * @return string
     */
    private static function decryptContent($key, $data)
    {
        if (!is_object($data)) {
            return false;
        }
        $cipherAlg  = self::CIPHER_ALG;
        $cipherMode = self::CIPHER_MODE;

        if (isset($data->alg)) {
            if (!array_key_exists($data->alg, self::$cipherArray)) {
                return false;
            }
            $cipherAlg  = self::$cipherArray[$data->alg]['alg'];
            $cipherMode = self::$cipherArray[$data->alg]['mode'];
        }
        $cipher  = isset($data->content) ? $data->content : $data->cipher;
        $cipher  = str_replace(' ', '+',$cipher);

        $iv = str_replace(' ', '+',$data->iv);

        if(!isset($data->hmac))
            return false;

        $hashedKey = hash('sha256', $key);

        $newHmac = hash_hmac('sha256', $cipher . $iv, $hashedKey);

        if($newHmac !== $data->hmac)
            return false;

        try {
            $content = mcrypt_decrypt($cipherAlg, $key, base64_decode($cipher), $cipherMode, base64_decode($iv));
        } catch (\Exception $e) {
            return false;
        }

        return $content;
    }

    /**
     * Method to verify signature
     *
     * @param string $publicKey Public Key
     * @param object $content   Content
     *
     * @return bool
     */
    private static function verifySignature($publicKey, $content)
    {
        if (!is_object($content)) {
            return false;
        }
        $signAlg = isset($content->alg) ? $content->alg : self::SIGN_ALG;

        if (isset($content->content) && isset($content->sign)) {
            //int 1 if the signature is correct, 0 if it is incorrect, and -1 on error.
            $obj = is_object($content->content) ? json_encode($content->content) : $content->content;
            try {
                $result = openssl_verify($obj, base64_decode($content->sign), $publicKey, $signAlg);
                return $result == 1;
            } catch (\Exception $e) {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Method to decrypt and verify content signature
     *
     * @param string $key     Shared Key
     * @param string $pubKey  Public Key
     * @param object $content Content
     *
     * @return bool|string
     */
    private static function decryptVerifyContent($key, $pubKey, $content)
    {
        if (!is_object($content) || !isset($content->sign) || !isset($content->iv)) {
            return false;
        }
        if (!isset($content->content) && !isset($content->cipher)) {
            return false;
        }
        $cipherAlg  = self::CIPHER_ALG;
        $cipherMode = self::CIPHER_MODE;
        $signAlg    = self::SIGN_ALG;

        if (isset($content->alg)) {
            $algs = explode("|", $content->alg);
            if (count($algs) != 2 || !array_key_exists($algs[0], self::$cipherArray)) {
                return false;
            }
            $cipherAlg  = self::$cipherArray[$algs[0]]['alg'];
            $cipherMode = self::$cipherArray[$algs[0]]['mode'];
            $signAlg    = (int) $algs[1];
        }

        $cipher = isset($content->content) ? $content->content : $content->cipher;
        try {
            $data = trim(mcrypt_decrypt(
                $cipherAlg,
                $key,
                base64_decode($cipher, true),
                $cipherMode,
                base64_decode($content->iv, true)
            ));
        } catch (\Exception $e) {
            return false;
        }

        try {
            return openssl_verify($data, base64_decode($content->sign, true), $pubKey, $signAlg) ? $data : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get Optipricer Key
     *
     * @return string
     */
    public static function getOptipricerKey()
    {
        //ToDo: Method to get the private key from file
        return file_get_contents(__DIR__ . '/' . self::KEY_FILENAME);
    }

    /** 
     * Function to generate session identifier
     * 
     * @param string    $ssid       generated by PHP function session_id()
     * @param bool      $user_agent flag which defines if user agent should be in ssid or don't
     * @param bool      $ip_address flag which defines if ip address should be in ssid or don't
     *
     * P.S. Session should be initialized (session_start())
     *
     * @return session identifier
     */
    public static function generateSessionID($ssid, $user_agent = true, $ip_address = false)
    {
      $sessionID = $ssid;

      if($user_agent)
        $sessionID .= $_SERVER['HTTP_USER_AGENT'];

      if($ip_address)
        $sessionID .= $_SERVER['REMOTE_ADDR'];

      return sha1($sessionID);
    }

    /**
     * Function to determine if session identifier is valid
     * 
     * @param string    $session_id session identifier present on the message (from Optipricer)
     * @param string    $ssid       generated by PHP function session_id()
     * @param bool      $user_agent flag which defines if user agent should be in ssid or don't
     * @param bool      $ip_address flag which defines if ip address should be in ssid or don't
     *
     * P.S. Session should be initialized (session_start())
     *
     * @return bool (true or false)
     */
    public static function verifySessionID($session_id, $ssid, $user_agent = true, $ip_address = false)
    {
      $old_ssid = self::generateSessionID($ssid, $user_agent, $ip_address);

      return $session_id == $old_ssid;
    }

}
