<?php
/**
 * Crypto library.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Ensoftek, Inc
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2015 Ensoftek, Inc
 * @copyright Copyright (c) 2018-2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


/**
 * Standard function to encrypt
 *
 * @param  string  $value           This is the data to encrypt.
 * @param  string  $customPassword  Do not use this unless supporting legacy stuff(ie. the download encrypted file feature).
 * @param  string  $keySource       This is the source of the keys. Options are 'drive' and 'database'
 *
 */
function encryptStandard($value, $customPassword = null, $keySource = 'drive')
{
    # This is the current encrypt/decrypt version
    # (this will always be a three digit number that we will
    #  increment when update the encrypt/decrypt methodology
    #  which allows being able to maintain backward compatibility
    #  to decrypt values from prior versions)
    $encryptionVersion = "004";

    $encryptedValue = $encryptionVersion . aes256Encrypt($value, $customPassword, $keySource);

    return $encryptedValue;
}

/**
 * Standard function to decrypt
 *
 * @param  string  $value           This is the data to encrypt.
 * @param  string  $customPassword  Do not use this unless supporting legacy stuff(ie. the download encrypted file feature).
 * @param  string  $keySource       This is the source of the keys. Options are 'drive' and 'database'
 *
 */
function decryptStandard($value, $customPassword = null, $keySource = 'drive')
{
    if (empty($value)) {
        return "";
    }

    # Collect the encrypt/decrypt version and remove it from the value
    $encryptionVersion = intval(mb_substr($value, 0, 3, '8bit'));
    $trimmedValue = mb_substr($value, 3, null, '8bit');

    # Map the encrypt/decrypt version to the correct decryption function
    if ($encryptionVersion == 4) {
        return aes256DecryptFour($trimmedValue, $customPassword, $keySource);
    } else if (($encryptionVersion == 2) || ($encryptionVersion == 3)) {
        return aes256DecryptTwo($trimmedValue, $customPassword);
    } else if ($encryptionVersion == 1) {
        return aes256DecryptOne($trimmedValue, $customPassword);
    } else {
        error_log("OpenEMR Error : Decryption is not working because of unknown encrypt/decrypt version.");
        return false;
    }
}

/**
 * Check if a crypt block is valid to use for the standard method
 * (basically checks if first 3 values are numbers)
 */
function cryptCheckStandard($value)
{
    if (empty($value)) {
        return false;
    }

    if (preg_match('/^\d\d\d/', $value)) {
        return true;
    } else {
        return false;
    }
}

/**
 * Function to AES256 encrypt a given string
 *
 * @param  string  $sValue          Raw data that will be encrypted.
 * @param  string  $customPassword  If null, then use standard key. If provide a password, then will derive key from this.
 * @param  string  $keySource       This is the source of the keys. Options are 'drive' and 'database'
 * @return string                   returns the encrypted data.
 */
function aes256Encrypt($sValue, $customPassword = null, $keySource = 'drive')
{
    if (!extension_loaded('openssl')) {
        error_log("OpenEMR Error : Encryption is not working because missing openssl extension.");
    }

    if (empty($customPassword)) {
        // Collect the encryption keys. If they do not exist, then create them
        // The first key is for encryption. Then second key is for the HMAC hash
        $sSecretKey = aes256PrepKey("four", "a", $keySource);
        $sSecretKeyHmac = aes256PrepKey("four", "b", $keySource);
    } else {
        // customPassword mode, so turn the password into keys
        $sSalt = produceRandomBytes(32);
        $sPreKey = hash_pbkdf2('sha384', $customPassword, $sSalt, 100000, 32, true);
        $sSecretKey = hash_hkdf('sha384', $sPreKey, 32, 'aes-256-encryption', $sSalt);
        $sSecretKeyHmac = hash_hkdf('sha384', $sPreKey, 32, 'sha-384-authentication', $sSalt);
    }

    if (empty($sSecretKey) || empty($sSecretKeyHmac)) {
        error_log("OpenEMR Error : Encryption is not working because key(s) is blank.");
    }

    $iv = produceRandomBytes(openssl_cipher_iv_length('aes-256-cbc'));

    $processedValue = openssl_encrypt(
        $sValue,
        'aes-256-cbc',
        $sSecretKey,
        OPENSSL_RAW_DATA,
        $iv
    );

    $hmacHash = hash_hmac('sha384', $iv.$processedValue, $sSecretKeyHmac, true);

    if ($sValue != "" && ($processedValue == "" || $hmacHash == "")) {
        error_log("OpenEMR Error : Encryption is not working.");
    }

    if (empty($customPassword)) {
        // prepend the encrypted value with the $hmacHash and $iv
        $completedValue = $hmacHash . $iv . $processedValue;
    } else {
        // customPassword mode, so prepend the encrypted value with the salts, $hmacHash and $iv
        $completedValue = $sSalt . $hmacHash . $iv . $processedValue;
    }

    return base64_encode($completedValue);
}


/**
 * Function to AES256 decrypt a given string, version 4
 *
 * @param  string  $sValue          Encrypted data that will be decrypted.
 * @param  string  $customPassword  If null, then use standard key. If provide a password, then will derive key from this.
 * @param  string  $keySource       This is the source of the keys. Options are 'drive' and 'database'
 * @return string or false          returns the decrypted data or false if failed.
 */
function aes256DecryptFour($sValue, $customPassword = null, $keySource = 'drive')
{
    if (!extension_loaded('openssl')) {
        error_log("OpenEMR Error : Decryption is not working because missing openssl extension.");
        return false;
    }

    $raw = base64_decode($sValue, true);
    if ($raw === false) {
        error_log("OpenEMR Error : Encryption did not work because illegal characters were noted in base64_encoded data.");
        return false;
    }

    if (empty($customPassword)) {
        // Collect the encryption keys.
        // The first key is for encryption. Then second key is for the HMAC hash
        $sSecretKey = aes256PrepKey("four", "a", $keySource);
        $sSecretKeyHmac = aes256PrepKey("four", "b", $keySource);
    } else {
        // customPassword mode, so turn the password keys
        // The first key is for encryption. Then second key is for the HMAC hash
        // First need to collect the salt from $raw (and then remove it from $raw)
        $sSalt = mb_substr($raw, 0, 32, '8bit');
        $raw = mb_substr($raw, 32, null, '8bit');
        // Now turn the password into keys
        $sPreKey = hash_pbkdf2('sha384', $customPassword, $sSalt, 100000, 32, true);
        $sSecretKey = hash_hkdf('sha384', $sPreKey, 32, 'aes-256-encryption', $sSalt);
        $sSecretKeyHmac = hash_hkdf('sha384', $sPreKey, 32, 'sha-384-authentication', $sSalt);
    }

    if (empty($sSecretKey) || empty($sSecretKeyHmac)) {
        error_log("OpenEMR Error : Encryption is not working because key(s) is blank.");
        return false;
    }

    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $hmacHash = mb_substr($raw, 0, 48, '8bit');
    $iv = mb_substr($raw, 48, $ivLength, '8bit');
    $encrypted_data = mb_substr($raw, ($ivLength+48), null, '8bit');

    $calculatedHmacHash = hash_hmac('sha384', $iv.$encrypted_data, $sSecretKeyHmac, true);

    if (hash_equals($hmacHash, $calculatedHmacHash)) {
        return openssl_decrypt(
            $encrypted_data,
            'aes-256-cbc',
            $sSecretKey,
            OPENSSL_RAW_DATA,
            $iv
        );
    } else {
        error_log("OpenEMR Error : Decryption failed authentication.");
    }
}


/**
 * Function to AES256 decrypt a given string, version 2
 *
 * @param  string  $sValue          Encrypted data that will be decrypted.
 * @param  string  $customPassword  If null, then use standard key. If provide a password, then will derive key from this.
 * @return string or false          returns the decrypted data or false if failed.
 */
function aes256DecryptTwo($sValue, $customPassword = null)
{
    if (!extension_loaded('openssl')) {
        error_log("OpenEMR Error : Decryption is not working because missing openssl extension.");
        return false;
    }

    if (empty($customPassword)) {
        // Collect the encryption keys.
        // The first key is for encryption. Then second key is for the HMAC hash
        $sSecretKey = aes256PrepKey("two", "a");
        $sSecretKeyHmac = aes256PrepKey("two", "b");
    } else {
        // Turn the password into a hash(note use binary) to use as the keys
        $sSecretKey = hash("sha256", $customPassword, true);
        $sSecretKeyHmac = $sSecretKey;
    }

    if (empty($sSecretKey) || empty($sSecretKeyHmac)) {
        error_log("OpenEMR Error : Encryption is not working because key(s) is blank.");
        return false;
    }

    $raw = base64_decode($sValue, true);
    if ($raw === false) {
        error_log("OpenEMR Error : Encryption did not work because illegal characters were noted in base64_encoded data.");
        return false;
    }


    $ivLength = openssl_cipher_iv_length('aes-256-cbc');
    $hmacHash = mb_substr($raw, 0, 32, '8bit');
    $iv = mb_substr($raw, 32, $ivLength, '8bit');
    $encrypted_data = mb_substr($raw, ($ivLength+32), null, '8bit');

    $calculatedHmacHash = hash_hmac('sha256', $iv.$encrypted_data, $sSecretKeyHmac, true);

    if (hash_equals($hmacHash, $calculatedHmacHash)) {
        return openssl_decrypt(
            $encrypted_data,
            'aes-256-cbc',
            $sSecretKey,
            OPENSSL_RAW_DATA,
            $iv
        );
    } else {
        error_log("OpenEMR Error : Decryption failed authentication.");
    }
}


/**
 * Function to AES256 decrypt a given string, version 1
 *
 * @param  string  $sValue          Encrypted data that will be decrypted.
 * @param  string  $customPassword  If null, then use standard key. If provide a password, then will derive key from this.
 * @return string                   returns the decrypted data.
 */
function aes256DecryptOne($sValue, $customPassword = null)
{
    if (!extension_loaded('openssl')) {
        error_log("OpenEMR Error : Decryption is not working because missing openssl extension.");
    }

    if (empty($customPassword)) {
        // Collect the key. If it does not exist, then create it
        $sSecretKey = aes256PrepKey();
    } else {
        // Turn the password into a hash to use as the key
        $sSecretKey = hash("sha256", $customPassword);
    }

    if (empty($sSecretKey)) {
        error_log("OpenEMR Error : Encryption is not working.");
    }

    $raw = base64_decode($sValue);

    $ivLength = openssl_cipher_iv_length('aes-256-cbc');

    $iv = substr($raw, 0, $ivLength);
    $encrypted_data = substr($raw, $ivLength);

    return openssl_decrypt(
        $encrypted_data,
        'aes-256-cbc',
        $sSecretKey,
        OPENSSL_RAW_DATA,
        $iv
    );
}

// Function to decrypt a given string
// This specific function is only used for backward compatibility
function aes256Decrypt_mycrypt($sValue)
{
    $sSecretKey = pack('H*', "bcb04b7e103a0cd8b54763051cef08bc55abe029fdebae5e1d417e2ffb2a00a3");
    return rtrim(
        mcrypt_decrypt(
            MCRYPT_RIJNDAEL_256,
            $sSecretKey,
            base64_decode($sValue),
            MCRYPT_MODE_ECB,
            mcrypt_create_iv(
                mcrypt_get_iv_size(
                    MCRYPT_RIJNDAEL_256,
                    MCRYPT_MODE_ECB
                ),
                MCRYPT_RAND
            )
        ),
        "\0"
    );
}

// Function to collect (and create, if needed) the standard keys
// keySource can be in either 'drive' or 'database'
// The 'drive' keys are stored at sites/<site-dir>/documents/logs_and_misc/methods/one
// The 'database' keys are stored in the keys sql table
//  This mechanism will allow easy migration to new keys/ciphers in the future while
//  also maintaining backward compatibility of encrypted data.
function aes256PrepKey($version = "one", $sub = "", $keySource = 'drive')
{
    // Build the label
    $label = $version.$sub;

    // If the key does not exist, then create it
    if ($keySource == 'database') {
        $sqlValue = sqlQuery("SELECT `value` FROM `keys` WHERE `name` = ?", [$label]);
        if (empty($sqlValue['value'])) {
            // Create a new key and place in database
            // Produce a 256bit key (32 bytes equals 256 bits)
            $newKey = produceRandomBytes(32);
            sqlInsert("INSERT INTO `keys` (`name`, `value`) VALUES (?, ?)", [$label, base64_encode($newKey)]);
        }
    } else { //$keySource == 'drive'
        if (!file_exists($GLOBALS['OE_SITE_DIR'] . "/documents/logs_and_misc/methods/" . $label)) {
            // Create a key and place in drive
            // Produce a 256bit key (32 bytes equals 256 bits)
            $newKey = produceRandomBytes(32);
            file_put_contents($GLOBALS['OE_SITE_DIR'] . "/documents/logs_and_misc/methods/" . $label, base64_encode($newKey));
        }
    }

    // Collect key
    if ($keySource == 'database') {
        $sqlKey = sqlQuery("SELECT `value` FROM `keys` WHERE `name` = ?", [$label]);
        $key = base64_decode($sqlKey['value']);
    } else { //$keySource == 'drive'
        $key = base64_decode(rtrim(file_get_contents($GLOBALS['OE_SITE_DIR'] . "/documents/logs_and_misc/methods/" . $label)));
    }

    if (empty($key)) {
        error_log("OpenEMR Error : Key creation is not working.");
    }

    // Return the key
    return $key;
}

// Produce random bytes (uses random_bytes with error checking)
function produceRandomBytes($length)
{
    try {
        $randomBytes = random_bytes($length);
    } catch (Error $e) {
        error_log('OpenEMR Error : Encryption is not working because of random_bytes() Error: ' . $e->getMessage());
    } catch (Exception $e) {
        error_log('OpenEMR Error : Encryption is not working because of random_bytes() Exception: ' . $e->getMessage());
    }

    return $randomBytes;
}
