<?php
// Deterministic isolated state transition tests; NOT a substitute for real DB concurrency tests.
namespace Illuminate\Support\Facades {
    class Log { public static function warning($m,$v=[]): void {} }
}
namespace Azuriom\Plugin\Shop\Models {
    class Payment {
        public string $gateway_type='jangopay';
        public string $currency='USD';
        public float $price=10.00;
        public static function find($id): self { return new self; }
    }
    class Gateway {
        public array $data=['allowed_assets'=>'USDT:tron'];
        public static function find($id): self { return new self; }
    }
}
namespace Azuriom\Plugin\JangoPay\Models {
    class Attempt {
        public static array $db=[];
        public int $id=1;
        public int $payment_id=1;
        public int $gateway_id=1;
        public string $order_id='AZ-ORDER-1';
        public string $invoice_id='INV-1';
        public string $amount_usd='10.00';
        public ?string $asset='USDT';
        public ?string $chain='tron';
        public ?string $txn_id='TXN-1';
        public ?object $expires_at=null;
        public string $state;
        public function __construct() { $this->state=self::$db['state']??'quoted'; }
        public function update(array $values): void {
            foreach ($values as $key=>$value) { self::$db[$key]=$value; $this->$key=$value; }
        }
        public function fresh(): self { return new self; }
        public static function whereKey($key): AttemptQuery { return new AttemptQuery; }
    }
    class AttemptQuery {
        private array $allowed=[];
        public function whereIn(string $field,array $states): self { $this->allowed=$states;return $this; }
        public function update(array $values): int {
            if (!in_array(Attempt::$db['state'],$this->allowed,true)) return 0;
            Attempt::$db=array_merge(Attempt::$db,$values);return 1;
        }
    }
}
namespace Azuriom\Plugin\JangoPay\Services {
    class JangoPayApi {
        public $invoiceHook=null;
        public $txnHook=null;
        public int $invoiceCalls=0;
        public function invoice(string $id): array {
            $this->invoiceCalls++;
            if($this->invoiceHook) ($this->invoiceHook)();
            return ['invoiceId'=>'INV-1','orderId'=>'AZ-ORDER-1','amount'=>'10.00',
                'currencyCode'=>'USD','status'=>'settled','payedAmount'=>'10.00'];
        }
        public function invoiceTransaction(string $id): array {
            if($this->txnHook) ($this->txnHook)();
            return ['txnId'=>'TXN-1','invoiceId'=>'INV-1','status'=>'settled','payCurrency'=>'USDT','chain'=>'tron'];
        }
        public static function field(array $body,string $snake,?string $camel=null): mixed {
            return $body[$snake]??$body[$camel??$snake]??null;
        }
    }
}
namespace {
    use Azuriom\Plugin\JangoPay\Models\Attempt;
    use Azuriom\Plugin\JangoPay\Services\JangoPayApi;
    use Azuriom\Plugin\JangoPay\Services\Reconciler;
    require __DIR__.'/../src/Support/Money.php';
    require __DIR__.'/../src/Support/Assets.php';
    require __DIR__.'/../src/Services/Reconciler.php';
    function now() { return new \DateTimeImmutable(); }
    function testResult(bool $condition,string $message): void {
        if (!$condition) throw new \RuntimeException('FAIL: '.$message);
        echo 'PASS: '.$message."\n";
    }
    putenv('JANGOPAY_FINAL_INVOICE_STATUSES=settled');
    Attempt::$db=['state'=>'quoted'];
    $api=new JangoPayApi;
    testResult((new Reconciler($api))->verify(new Attempt)==='verified','Normal quoted to verified CAS');
    testResult(Attempt::$db['state']==='verified','Normal CAS persisted verified');
    $calls=$api->invoiceCalls;
    testResult((new Reconciler($api))->verify(new Attempt)==='verified','Repeat verified short circuits');
    testResult($api->invoiceCalls===$calls,'Repeat did not trigger remote invoice query');
    Attempt::$db=['state'=>'delivery_started'];
    testResult((new Reconciler($api))->verify(new Attempt)==='delivery_started','In-progress claim remains terminal');
    Attempt::$db=['state'=>'delivered'];
    testResult((new Reconciler($api))->verify(new Attempt)==='delivered','Delivered is not reset');
    Attempt::$db=['state'=>'quoted'];
    $api=new JangoPayApi;
    $api->txnHook=function(){ Attempt::$db['state']='delivery_started'; };
    testResult((new Reconciler($api))->verify(new Attempt)==='delivery_started','In-flight delivery claim wins against late positive verify');
    testResult(Attempt::$db['state']==='delivery_started','Positive verify cannot overwrite delivery_started');
    Attempt::$db=['state'=>'quoted'];
    $api=new JangoPayApi;
    $api->invoiceHook=function(){ Attempt::$db['state']='delivered'; };
    // A stale caller reaches the mismatch branch after another worker has delivered.
    $tmp=new Attempt; $tmp->order_id='MISMATCH';
    testResult((new Reconciler($api))->verify($tmp)==='delivered','Late negative verification cannot reset delivered to manual_review');
    testResult(Attempt::$db['state']==='delivered','Negative verification preserved delivered');
    echo '10 deterministic state assertions passed (no real DB threads).'."\n";
}
