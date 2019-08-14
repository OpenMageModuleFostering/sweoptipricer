<?php

/**
 * Widget to share the product in Facebook and get a personalized discount
 *
 * @package   SWE_Optipricer
 * @author    Ubiprism Lda. / be.ubi <contact@beubi.com>
 * @copyright 2015 be.ubi
 * @license   GNU Lesser General Public License (LGPL)
 * @version   v.0.1.3
 */
class SWE_Optipricer_Helper_Securedata extends Mage_Core_Helper_Abstract
{
    const KEY_FILENAME = 'swe_private_key.pem';

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
        if (is_array($content)) {
            return false;
        }

        $data = base64_decode($content);
        if (!$data) {
            //Content is not a base64 encode object
            $data = $content;
        }

        $data = json_decode($data);
        $mode = isset($data->smode) ? $data->smode : self::SECURE_CIPHER;
        if ($mode == self::SECURE_CIPHER && !isset($data->iv)) {
            return false;
        }
        $obj = isset($data->content) ? $data->content : $data->cipher;

        switch($mode)
        {
            case self::SECURE_CIPHER:
                return self::decryptContent($key, $data);
                break;
            case self::SECURE_SIGN:
                if(self::verifySignature($publicKey, $data))
                    return $obj;
                else
                    return false;
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
        $iv = '';
        if($ivSize > 0)
            $iv = mcrypt_create_iv($ivSize, MCRYPT_DEV_URANDOM);

        // creates a cipher text compatible with AES (Rijndael block size = 128) to keep the text confidential
        // only suitable for encoded input that never ends with value 00h (because of default zero padding)
        $cipherText = mcrypt_encrypt(self::CIPHER_ALG, $key, $content, self::CIPHER_MODE, $iv);

        // prepend the IV for it to be available for decryption
        $cipherTextArray = array(
            'content' => base64_encode($cipherText),
            'iv'      => base64_encode($iv),
            'alg'     => self::CIPHER_ALG_MODE,
            'smode'   => self::SECURE_CIPHER
        );

        $cipherTextArray = json_encode($cipherTextArray);

        // encode the resulting cipher text so it can be represented by a string
        // could be commented...
        $cipherTextArray = base64_encode($cipherTextArray);

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
        $cipherAlg  = self::CIPHER_ALG;
        $cipherMode = self::CIPHER_MODE;

        if(isset($data->alg))
        {
            $cipherAlg  = self::$cipherArray[$data->alg]['alg'];
            $cipherMode = self::$cipherArray[$data->alg]['mode'];
        }

        $cipher  = isset($data->content) ? $data->content : $data->cipher;
        $content = mcrypt_decrypt($cipherAlg, $key, base64_decode($cipher), $cipherMode, base64_decode($data->iv));

        return $content;
    }


    /**
     * Method to sign content
     *
     * @param string $privateKey Private Key
     * @param string $content    Content
     *
     * @return array|string
     */
    private static function signContent($privateKey, $content)
    {
        openssl_sign($content, $signature, $privateKey, self::SIGN_ALG);
        $obj = array(
            'content' => $content,
            'alg'     => self::SIGN_ALG,
            'smode'   => self::SECURE_SIGN,
            'sign'    => base64_encode($signature)
        );

        $obj = json_encode($obj);
        //$obj = base64_encode($obj);

        return $obj;
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
        $signAlg = isset($content->alg) ? $content->alg : self::SIGN_ALG;

        if (isset($content->content) && isset($content->sign)) {
            //int 1 if the signature is correct, 0 if it is incorrect, and -1 on error.
            $result = openssl_verify($content->content, base64_decode($content->sign), $publicKey, $signAlg);
            return $result != 1;
        } else {
            return false;
        }
    }

    /**
     * Method to encrypt and sign content
     *
     * @param string $key     Shared Key
     * @param string $privKey Private Key
     * @param string $content Content
     *
     * @return array|string
     */
    private static function encryptSignContent($key, $privKey, $content)
    {
        openssl_sign($content, $signature, $privKey, self::SIGN_ALG);

        $ivSize = mcrypt_get_iv_size(self::CIPHER_ALG, self::CIPHER_MODE);

        $iv = '';
        if($ivSize > 0)
            $iv = mcrypt_create_iv($ivSize, MCRYPT_DEV_URANDOM);

        // creates a cipher text compatible with AES (Rijndael block size = 128) to keep the text confidential
        // only suitable for encoded input that never ends with value 00h (because of default zero padding)
        $ciphertext = mcrypt_encrypt(self::CIPHER_ALG, $key, $content, self::CIPHER_MODE, $iv);

        $obj = array(
            'content' => base64_encode($ciphertext),
            'iv'      => base64_encode($iv),
            'alg'     => self::CIPHER_ALG_MODE . '|' . self::SIGN_ALG,
            'smode'   => self::SECURE_CIPHER_SIGN,
            'sign'    => base64_encode($signature)
        );

        $obj = json_encode($obj);

        //is it really necessary?
        //$obj = base64_encode($obj);

        return $obj;
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
        $cipherAlg  = self::CIPHER_ALG;
        $cipherMode = self::CIPHER_MODE;
        $signAlg    = self::SIGN_ALG;

        if(isset($content->alg))
        {
            $algs       = explode("|", $content->alg);
            $cipherAlg  = self::$cipherArray[$algs[0]]['alg'];
            $cipherMode = self::$cipherArray[$algs[0]]['mode'];
            $signAlg    = $algs[1];
        }

        $cipher = isset($content->content) ? $content->content : $content->cipher;
        $data = mcrypt_decrypt(
            $cipherAlg,
            $key,
            base64_decode($cipher),
            $cipherMode,
            base64_decode($content->iv)
        );

        return openssl_verify($data, base64_decode($content->sign), $pubKey, $signAlg) ? $data : false;
    }

    /**
     * Get Optipricer Key
     *
     * @return string
     */
    private static function getOptipricerKey()
    {
        //ToDo: Method to get the private key from file
        return file_get_contents(self::KEY_FILENAME);
    }
}
