<?php
/* Synthetic crypto contract test; not a real merchant webhook vector. */
define('ABSPATH','/nonexistent-wordpress-install/');
define('JANGOPAY_WC_WEBHOOK_FRAMING','appended16');
$dir=sys_get_temp_dir().'/jpwc-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key,$private);$details=openssl_pkey_get_details($key);
file_put_contents($dir.'/priv.pem',$private);file_put_contents($dir.'/pub.pem',$details['key']);
class JPWC_Controller {public static function keyPath(){return $GLOBALS['tempkeypath'];}}
$GLOBALS['tempkeypath']=$dir.'/priv.pem';
require dirname(__DIR__).'/includes/class-jpwc-webhook.php';
$count=0;function ok($label,$value){global $count;if(!$value)throw new RuntimeException('FAIL '.$label);$count++;echo "PASS $label\n";}
function failcall(callable $call){try{$call();return false;}catch(RuntimeException $e){return true;}}
$aes=random_bytes(32);$nonce=random_bytes(12);$plaintext=json_encode(['invoice_id'=>'INV-123','txn_id'=>'TXN-456']);
$encrypted=openssl_encrypt($plaintext,'aes-256-gcm',$aes,OPENSSL_RAW_DATA,$nonce,$tag);
file_put_contents($dir.'/aes.bin',$aes);
$cmd=['openssl','pkeyutl','-encrypt','-pubin','-inkey',$dir.'/pub.pem','-in',$dir.'/aes.bin','-out',$dir.'/rsa.bin','-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:sha256','-pkeyopt','rsa_mgf1_md:sha256'];
$p=proc_open($cmd,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException('OpenSSL CLI encrypt unavailable '.$err);
$envelope=['algorithm'=>'AES-256-GCM','key_algorithm'=>'RSA-OAEP-SHA256','encrypted_key'=>base64_encode(file_get_contents($dir.'/rsa.bin')),'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($encrypted.$tag)];
$headers=['x-jangopay-event'=>'payment.completed','x-jangopay-encryption-alg'=>'AES-256-GCM','x-jangopay-key-encryption'=>'RSA-OAEP-SHA256'];
$result=JPWC_Webhook::decrypt($headers,json_encode($envelope));
ok('webhook without duplicated event_type',(string)$result['invoice_id']==='INV-123');
$bad=$envelope;$bad['event_type']='payment.failed';ok('event_type conflict rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($bad))));
$bad=$envelope;$bad['ciphertext']=base64_encode($encrypted.str_repeat('A',16));ok('GCM tag tamper rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($bad))));
$bad=$headers;$bad['x-jangopay-event']='payment.failed';ok('header mismatch rejected',failcall(fn()=>JPWC_Webhook::decrypt($bad,json_encode($envelope))));
$bad=$envelope;$bad['ciphertext']='***';ok('invalid base64 rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($bad))));
ok('oversized notification rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,str_repeat('A',32769))));
file_put_contents($dir.'/vector.json',json_encode(['headers'=>$headers,'envelope'=>$envelope]));
$p=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),'-d','disable_functions=proc_open,proc_close,exec,shell_exec,system,passthru,popen',__DIR__.'/fixtures/webhook-no-process.php',$dir],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
fclose($pipes[0]);$workerOut=stream_get_contents($pipes[1]);fclose($pipes[1]);$workerErr=stream_get_contents($pipes[2]);fclose($pipes[2]);
ok('PHP-only runtime works without process functions',proc_close($p)===0&&str_contains($workerOut,'PASS runtime decrypts')&&$workerErr==='');
$bad=$envelope;$bad['nonce']=base64_encode(random_bytes(11));ok('invalid nonce length rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($bad))));
$bad=$envelope;$bad['encrypted_key']=base64_encode(random_bytes(256));ok('invalid wrapped key rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($bad))));
$bad=$headers;$bad['x-jangopay-key-encryption']='RSA-OAEP';ok('OAEP downgrade header rejected',failcall(fn()=>JPWC_Webhook::decrypt($bad,json_encode($envelope))));
openssl_public_encrypt($aes,$sha1,$details['key'],OPENSSL_PKCS1_OAEP_PADDING);
$bad=$envelope;$bad['encrypted_key']=base64_encode($sha1);ok('SHA1 OAEP ciphertext rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($bad))));
$other=openssl_pkey_new(['private_key_bits'=>2048]);openssl_pkey_export($other,$wrongPrivate);file_put_contents($dir.'/wrong.pem',$wrongPrivate);
$GLOBALS['tempkeypath']=$dir.'/wrong.pem';ok('wrong private key rejected',failcall(fn()=>JPWC_Webhook::decrypt($headers,json_encode($envelope))));$GLOBALS['tempkeypath']=$dir.'/priv.pem';
foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);echo "TOTAL $count synthetic crypto tests passed\n";
