<?php
if(!defined('ABSPATH')){exit;}
final class JPWC_Webhook {
    private static function b64($x,$limit) {
        if(!is_string($x)||$x===''||strlen($x)>$limit*2)throw new RuntimeException('bad_envelope');
        $d=base64_decode($x,true);if($d===false||strlen($d)>$limit)throw new RuntimeException('bad_envelope');return $d;
    }
    public static function decrypt($headers,$input) {
        if(strlen($input)>32768)throw new RuntimeException('envelope_too_large');
        $event=(string)($headers['x-jangopay-event']??'');
        $alg=(string)($headers['x-jangopay-encryption-alg']??'');
        $keyalg=(string)($headers['x-jangopay-key-encryption']??'');
        if($event!=='payment.completed'||$alg!=='AES-256-GCM'||$keyalg!=='RSA-OAEP-SHA256')throw new RuntimeException('header_mismatch');
        $outer=json_decode($input,true);
        if(!is_array($outer))throw new RuntimeException('bad_json');
        foreach(['event_type'=>'payment.completed','algorithm'=>'AES-256-GCM','key_algorithm'=>'RSA-OAEP-SHA256'] as $k=>$expected){
            // Already identified by the authenticated outer headers; optional duplicate must agree.
            if(array_key_exists($k,$outer)&&$outer[$k]!==$expected)throw new RuntimeException('envelope_conflict');
        }
        if(!defined('JANGOPAY_WC_WEBHOOK_FRAMING')||JANGOPAY_WC_WEBHOOK_FRAMING!=='appended16')throw new RuntimeException('webhook_framing_not_verified');
        $nonce=self::b64($outer['nonce']??null,32);$cipher=self::b64($outer['ciphertext']??null,32768);
        $keyCipher=self::b64($outer['encrypted_key']??null,2048);
        if(strlen($nonce)!==12||strlen($cipher)<=16)throw new RuntimeException('invalid_envelope_size');
        $keyPath=JPWC_Controller::keyPath();
        if(!is_file($keyPath)||!is_readable($keyPath))throw new RuntimeException('key_unavailable');
        require_once dirname(__DIR__).'/vendor/autoload.php';
        // A vetted, private namespace avoids both external commands and other plugins' library versions.
        try {
            $rsa=\JangoPayWooVendor\phpseclib4\Crypt\PublicKeyLoader::loadPrivateKey(file_get_contents($keyPath));
            if(!$rsa instanceof \JangoPayWooVendor\phpseclib4\Crypt\RSA\PrivateKey)throw new RuntimeException('rsa_key_invalid');
            $key=$rsa->withPadding(\JangoPayWooVendor\phpseclib4\Crypt\RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')->withMGFHash('sha256')->decrypt($keyCipher);
        } catch(Throwable $e) { throw new RuntimeException('rsa_failed'); }
        if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('rsa_failed');
        $plain=openssl_decrypt(substr($cipher,0,-16),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,substr($cipher,-16));
        if(!is_string($plain))throw new RuntimeException('gcm_failed');
        $data=json_decode($plain,true);
        if(!is_array($data))throw new RuntimeException('payload_invalid');
        if(array_key_exists('event_type',$data)&&$data['event_type']!=='payment.completed')throw new RuntimeException('payload_event_conflict');
        return $data;
    }
}
