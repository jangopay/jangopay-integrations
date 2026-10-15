<?php
// Isolated control-flow regression tests based on the independent Codex review.
// Stubs model Eloquent CAS/query semantics; NOT a real Laravel or database test.
namespace Illuminate\Support\Facades {
    class Log { public static function warning(...$args) {} public static function error(...$args) {} }
    class DB { public static function transaction(callable $action) { return $action(); } }
}
namespace Illuminate\Support {
    class Str {
        public static function random($n) { return substr(bin2hex(random_bytes($n)), 0, $n); }
        public static function lower($x) { return strtolower($x); }
    }
}
namespace Azuriom\Plugin\Shop\Cart {
    class Cart {
        public function isEmpty() { return false; }
        public function toArray() { return ['items'=>[['id'=>1,'quantity'=>1]],'coupons'=>[],'giftcards'=>[]]; }
        public function destroy() { $GLOBALS['session']->forget('shop.cart'); }
    }
}
namespace Azuriom\Plugin\Shop\Payment {
    class PaymentManager {
        public static int $nextId=0;
        public static function createPayment(...$args) {
            $p=\Azuriom\Plugin\Shop\Models\Payment::find(1);
            $p->id=++self::$nextId;
            return $p;
        }
    }
}
namespace Illuminate\Http { class Request { public function session() { return $GLOBALS['session']; } } }
namespace Illuminate\Console {
    class Command {
        public const SUCCESS=0;
        public function option($key) { return 20; }
        public function line($message) {}
        public function warn($message) {}
    }
}
namespace Azuriom\Http\Controllers { class Controller {} }
namespace Azuriom\Plugin\Shop\Models {
    class Payment {
        public static string $status='pending';
        public static int $deliveries=0;
        public static $findHook=null;
        public string $gateway_type='jangopay';
        public string $currency='USD';
        public float $price=10.00;
        public int $user_id=7;
        public string $localStatus;
        public int $id=1;
        public static function purgePendingPayments(): void {}
        public static function find($id): self {
            $p=new self; $p->localStatus=self::$status;
            if (self::$findHook) { $hook=self::$findHook; self::$findHook=null; $hook(); }
            return $p;
        }
        public function isPending(): bool { return $this->localStatus==='pending'; }
        public function isCompleted(): bool { return $this->localStatus==='completed'; }
        public function fill($values): self { return $this; }
        public function deliver(): void { self::$deliveries++; self::$status='completed'; }
        public function fresh(): self { return self::find(1); }
    }
    class Gateway {
        public int $id=1;
        public string $type='jangopay';
        public bool $is_enabled=true;
        public array $data=['allowed_assets'=>'USDT:tron'];
        public static array $visited=[];
        public static function find($id): self { return new self; }
        public function paymentMethod(): object {
            return new class {
                public function reconcile($attempt) { Gateway::$visited[]=$attempt->id; return 'pending'; }
            };
        }
    }
}
namespace Azuriom\Plugin\JangoPay\Models {
    #[\AllowDynamicProperties]
    class Attempt {
        public static array $db=[];
        public int $id=1;
        public int $payment_id=1;
        public int $gateway_id=1;
        public string $order_id='ORDER-1';
        public ?string $invoice_id=null;
        public ?string $txn_id='TXN-1';
        public ?string $asset='USDT';
        public ?string $chain='tron';
        public string $amount_usd='10.00';
        public ?string $allowed_assets=null;
        public $expires_at=null;
        public string $state='unknown_creation';
        public string $access_hash;
        public function __construct(int $id=1) {
            $this->id=$id;
            foreach (self::$db[$id] ?? [] as $key=>$value) $this->$key=$value;
            if (! isset($this->access_hash)) $this->access_hash=hash('sha256',str_repeat('a',64));
        }
        public function update(array $data): void {
            self::$db[$this->id]=array_merge(self::$db[$this->id] ?? [],$data);
            foreach ($data as $key=>$value) $this->$key=$value;
        }
        public function fresh(): self { return new self($this->id); }
        public static function create(array $data): self {
            foreach (self::$db as $row) {
                if (isset($row['checkout_key']) && $row['checkout_key']===$data['checkout_key']) {
                    throw new \RuntimeException('Unique checkout key violation');
                }
            }
            $id=empty(self::$db)?1:max(array_keys(self::$db))+1;
            self::$db[$id]=$data;
            return new self($id);
        }
        public static function whereKey($id): Query { return new Query($id); }
        public static function where($field,$value): Query { return (new Query)->where($field,$value); }
        public static function whereIn($field,$values): Query { return (new Query)->whereIn($field,$values); }
    }
    class Query {
        private array $equals=[];
        private array $ins=[];
        private array $nulls=[];
        private array $notNulls=[];
        private int $limit=PHP_INT_MAX;
        private array $sort=[];
        public function __construct(private ?int $id=null) {}
        public function where($key,$value): self { $this->equals[$key]=$value; return $this; }
        public function whereIn($key,$values): self { $this->ins[$key]=$values; return $this; }
        public function whereNull($key): self { $this->nulls[]=$key; return $this; }
        public function whereNotNull($key): self { $this->notNulls[]=$key; return $this; }
        public function orderByRaw($raw): self { $this->sort[]='last_checked_null'; return $this; }
        public function orderBy($key): self { $this->sort[]=$key; return $this; }
        public function limit($n): self { $this->limit=$n; return $this; }
        private function matches(int $id,array $row): bool {
            if ($this->id!==null && $id!==$this->id) return false;
            foreach ($this->equals as $k=>$v) if (($row[$k] ?? null)!==$v) return false;
            foreach ($this->ins as $k=>$values) if (!in_array($row[$k] ?? null,$values,true)) return false;
            foreach ($this->nulls as $k) if (($row[$k] ?? null)!==null) return false;
            foreach ($this->notNulls as $k) if (($row[$k] ?? null)===null) return false;
            return true;
        }
        public function get(): array {
            $items=[];
            foreach (Attempt::$db as $id=>$row) if ($this->matches($id,$row)) $items[$id]=$row;
            $sort=$this->sort;
            uksort($items,static function($a,$b) use ($sort) {
                foreach ($sort as $k) {
                    $aRow=Attempt::$db[$a];$bRow=Attempt::$db[$b];
                    $aVal=$k==='last_checked_null' ? ((isset($aRow['last_checked_at']))?1:0):($k==='id'?$a:($aRow[$k]??''));
                    $bVal=$k==='last_checked_null' ? ((isset($bRow['last_checked_at']))?1:0):($k==='id'?$b:($bRow[$k]??''));
                    $comp=$aVal<=>$bVal; if ($comp) return $comp;
                }
                return $a<=>$b;
            });
            return array_map(static fn($id)=>new Attempt((int)$id),array_slice(array_keys($items),0,$this->limit));
        }
        public function first(): ?Attempt { return $this->get()[0] ?? null; }
        public function update(array $data): int {
            $n=0;
            foreach (Attempt::$db as $id=>$row) if ($this->matches($id,$row)) {
                (new Attempt($id))->update($data); $n++;
            }
            return $n;
        }
    }
}
namespace Azuriom\Plugin\JangoPay\Services {
    class JangoPayApi {
        public $recoveryHook=null;
        public $transactionHook=null;
        public int $invoiceCalls=0;
        public int $createdInvoices=0;
        public int $transactionSearchCalls=0;
        public string $remoteInvoiceStatus='settled';
        public string $remoteTxnStatus='settled';
        public array $invoicePatch=[];
        public array $transactionPatch=[];
        public function isConfigured(): bool { return true; }
        public function isCollectionReady(): bool { return true; }
        public function createInvoice($data): array { return ['invoiceId'=>'NEW-'.++$this->createdInvoices]; }
        public function findInvoiceByOrder($order): array {
            if ($this->recoveryHook) { $h=$this->recoveryHook;$this->recoveryHook=null;$h(); }
            return ['invoiceId'=>'INV-1','orderId'=>$order,'amount'=>'10.00','currencyCode'=>'USD'];
        }
        public function invoice($id): array {
            $this->invoiceCalls++;
            return array_replace(['invoiceId'=>'INV-1','orderId'=>'ORDER-1','amount'=>'10.00',
                    'currencyCode'=>'USD','status'=>$this->remoteInvoiceStatus,'payedAmount'=>'10.00'], $this->invoicePatch);
        }
        public function invoiceTransaction($id): array {
            if ($this->transactionHook) { $h=$this->transactionHook;$this->transactionHook=null;$h(); }
            return array_replace(['txnId'=>'TXN-1','invoiceId'=>'INV-1','status'=>$this->remoteTxnStatus,'payCurrency'=>'USDT','chain'=>'tron', 'payAmount'=>'10.0','receiveAddress'=>'TTestAddress'], $this->transactionPatch);
        }
        public function findTransactionForInvoice($id): ?array { $this->transactionSearchCalls++; return ['txnId'=>'TXN-1','invoiceId'=>$id]; }
        public static function field(array $a,string $s,?string $c=null): mixed { return $a[$s]??$a[$c??$s]??null; }
    }
}
namespace {
    use Azuriom\Plugin\JangoPay\JangoPayMethod;
    use Azuriom\Plugin\JangoPay\Models\Attempt;
    use Azuriom\Plugin\JangoPay\Services\JangoPayApi;
    use Azuriom\Plugin\JangoPay\Services\Reconciler;
    use Azuriom\Plugin\Shop\Models\Gateway;
    use Azuriom\Plugin\Shop\Models\Payment;
    require __DIR__.'/fixtures/ShopPaymentMethod.php';
    require __DIR__.'/../src/Support/Money.php';
    require __DIR__.'/../src/Support/Assets.php';
    require __DIR__.'/../src/Support/CheckoutFingerprint.php';
    require __DIR__.'/../src/Support/CheckoutAccess.php';
    require __DIR__.'/../src/Services/Reconciler.php';
    require __DIR__.'/../src/JangoPayMethod.php';
    require __DIR__.'/../src/Controllers/CheckoutController.php';
    require __DIR__.'/../src/Commands/ReconcilePayments.php';
    function app($class) { return $class===JangoPayApi::class?$GLOBALS['api']:$GLOBALS['reconciler']; }
    class TestClock extends \DateTimeImmutable {
        public function addDay() { return $this->modify('+1 day'); }
        public function toIso8601String() { return $this->format('c'); }
        public function greaterThan($date) { return $this > $date; }
    }
    function now() { return new TestClock('2026-10-14T00:00:00Z'); }
    function currency() { return 'USD'; }
    function config($k) {
        if ($k==='app.key') return $GLOBALS['testAppKey'] ?? 'base64:'.base64_encode(str_repeat('T',32));
        if ($k==='session.driver') return $GLOBALS['testSessionDriver'] ?? 'file';
        if ($k==='cache.default') return $GLOBALS['testCacheDriver'] ?? 'file';
        if ($k==='session.block_store') return $GLOBALS['testBlockStore'] ?? null;
        return 'https://test.invalid';
    }
    function request() { return new class { public function session() { return $GLOBALS['session']; } }; }
    function redirect() { return new class { public function route(...$x) { return (object)['redirect'=>true, 'route'=>$x[0]??null, 'params'=>$x[1]??[]]; } }; }
    function to_route(...$x) { return new class { public function with(...$x) { return $this; } }; }
    function auth() { return new class { public function check() { return false; } public function id() { return null; } }; }
    function shop_user() { return (object)['id'=>7]; }
    function use_site_money() { return false; }
    function setting($name,$default=null) { return $name==='shop.guest_purchases'?true:$default; }
    function trans($key) { return $key; }
    function abort_unless($value,$code) { if (!$value) throw new \RuntimeException('HTTP '.$code,$code); }
    function abort_if($value,$code) { abort_unless(!$value,$code); }
    function response() { return new class { public function json($body,$status=200) { return new class($status) {
        public function __construct(private int $status) {}
        public function getStatusCode() { return $this->status; }
    }; } }; }
    function check(bool $ok,string $message): void {
        if (!$ok) throw new \RuntimeException('FAIL: '.$message);
        echo 'PASS: '.$message.PHP_EOL;
    }
    class SessionFake {
        public array $data=[];
        public function getId() { return 'same-browser-session'; }
        public function get($key) { return $this->data[$key] ?? null; }
        public function put($key,$val) { $this->data[$key]=$val; }
        public function forget($key) { unset($this->data[$key]); }
    }
    putenv('JANGOPAY_FINAL_INVOICE_STATUSES=settled');
    $GLOBALS['api']=new JangoPayApi;
    $GLOBALS['reconciler']=new Reconciler($GLOBALS['api']);
    $method=new JangoPayMethod(new Gateway);
    $GLOBALS['session']=new SessionFake;
    $GLOBALS['session']->put('shop.user',7);

    // P2 guest checkout: valid capability AND current Shop guest identity must match.
    Attempt::$db=[1=>['state'=>'quoted','invoice_id'=>'INV-1']];
    $auth=new \ReflectionMethod(\Azuriom\Plugin\JangoPay\Controllers\CheckoutController::class,'authorized');
    $auth->invoke(new \Azuriom\Plugin\JangoPay\Controllers\CheckoutController,new \Illuminate\Http\Request,new Attempt,str_repeat('a',64));
    check(true,'Guest payment access with matching Shop session and token succeeds');
    $GLOBALS['session']->put('shop.user',999);
    $denied=false;
    try { $auth->invoke(new \Azuriom\Plugin\JangoPay\Controllers\CheckoutController,new \Illuminate\Http\Request,new Attempt,str_repeat('a',64)); }
    catch (\RuntimeException $e) { $denied=$e->getCode()===403; }
    check($denied,'Guest cannot access someone else\'s order even with valid token');
    $GLOBALS['session']->put('shop.user',7);

    // P1: suspended recovery must not downgrade delivery_started or duplicate fulfillment.
    Attempt::$db=[1=>['state'=>'unknown_creation','invoice_id'=>null]];
    $GLOBALS['api']->recoveryHook=fn()=>\Fiber::suspend();
    $late=new \Fiber(fn()=>$method->recoverUnknownCreation(new Attempt)); $late->start();
    check($method->recoverUnknownCreation(new Attempt)==='awaiting_payment','First recovery succeeded');
    (new Attempt)->update(['state'=>'quoted','txn_id'=>'TXN-1','asset'=>'USDT','chain'=>'tron']);
    Payment::$status='pending';Payment::$deliveries=0;
    $GLOBALS['api']->transactionHook=function() { Payment::$findHook=fn()=>\Fiber::suspend(); };
    $first=new \Fiber(fn()=>$method->reconcile(new Attempt)); $first->start();
    check(Attempt::$db[1]['state']==='delivery_started','First delivery claim persisted');
    $late->resume();
    check(Attempt::$db[1]['state']==='delivery_started','Stale recovery cannot downgrade delivery claim');
    $method->reconcile(new Attempt); $first->resume();
    check(Payment::$deliveries===1,'Only one Shop delivery after recovery race');

    // P5: a confirmed paid merchant transaction can repair quote_unknown without POST retry.
    Attempt::$db=[1=>['state'=>'quote_unknown','invoice_id'=>'INV-1','txn_id'=>null,'asset'=>'USDT','chain'=>'tron']];
    Payment::$status='pending';Payment::$deliveries=0;
    $calls=$GLOBALS['api']->invoiceCalls;
    check($method->reconcile(new Attempt,'TXN-1')==='delivered','Quote-unknown transaction hint can be verified and delivered once');
    check($GLOBALS['api']->invoiceCalls>$calls && Payment::$deliveries===1,'Quote recovery consults merchant API before delivery');
    Attempt::$db=[1=>['state'=>'quote_unknown','invoice_id'=>'INV-1','txn_id'=>null,'asset'=>'USDT','chain'=>'tron']];
    Payment::$status='pending'; Payment::$deliveries=0;
    check($method->reconcile(new Attempt)==='delivered','Quote-unknown fallback read-only transaction search recovers settled payment');
    check($GLOBALS['api']->transactionSearchCalls>0,'Read-only transaction lookup was used');

    // P5: pending merchant transaction can safely restore actionable instructions.
    Attempt::$db=[1=>['state'=>'quote_unknown','invoice_id'=>'INV-1','txn_id'=>null,
        'asset'=>'USDT','chain'=>'tron','allowed_assets'=>'USDT:tron']];
    $GLOBALS['api']->remoteInvoiceStatus='pending';
    $GLOBALS['api']->remoteTxnStatus='pending';
    check($GLOBALS['reconciler']->verify(new Attempt)==='quoted',
        'Read-only pending transaction recovers quote without reconfirming coin');
    check(Attempt::$db[1]['receive_address']==='TTestAddress'
        && Attempt::$db[1]['quote_amount']==='10.0',
        'Recovered payer address/amount originate from merchant query');
    $GLOBALS['api']->remoteInvoiceStatus='settled';
    $GLOBALS['api']->remoteTxnStatus='settled';

    // P4: old still-pending records cannot starve the newest one indefinitely.
    Attempt::$db=[]; Gateway::$visited=[];
    for ($i=1;$i<=21;$i++) Attempt::$db[$i]=['state'=>'quoted','invoice_id'=>'INV-'.$i];
    $cmd=new \Azuriom\Plugin\JangoPay\Commands\ReconcilePayments;
    $cmd->handle();$cmd->handle();
    check(in_array(21,Gateway::$visited,true),'Reconciliation rotates to the 21st pending order');

    // P2: two initial session snapshots derive the same key and create at most one invoice.
    Attempt::$db=[]; $GLOBALS['api']->createdInvoices=0;
    $sessionA=new SessionFake; $sessionB=new SessionFake;
    $GLOBALS['session']=$sessionA;
    $method->startPayment(new \Azuriom\Plugin\Shop\Cart\Cart,10.00,'USD');
    $GLOBALS['session']=$sessionB;
    $method->startPayment(new \Azuriom\Plugin\Shop\Cart\Cart,10.00,'USD');
    check(count(Attempt::$db)===1 && $GLOBALS['api']->createdInvoices===1,
        'Concurrent initial session snapshots cannot create two invoices');
    $feeCases = [
        ['Merchant fee net payment accepted', '9.90000000', [], 'verified'],
        ['Fractional-cent fee remains exact', '9.99500000', ['feeAmount'=>'0.00500000', 'netAmount'=>'9.99500000'], 'verified'],
        ['Underpaid gross transaction rejected', '8.90000000', ['amount'=>'9.00000000','netAmount'=>'8.90000000'], 'manual_review'],
        ['Inconsistent fee arithmetic rejected', '9.90000000', ['feeAmount'=>'0.20000000'], 'manual_review'],
        ['Wrong ledger currency rejected', '9.90000000', ['currencyCode'=>'EUR'], 'manual_review'],
        ['Invoice paid amount must equal transaction net', '9.80000000', [], 'manual_review'],
        ['Payer-paid fee cannot explain reduced merchant receipt', '9.90000000', ['feePayByUser'=>true], 'manual_review'],
        ['Negative fee rejected', '9.90000000', ['feeAmount'=>'-0.10000000'], 'manual_review'],
        ['Missing fee metadata rejected', '9.90000000', ['feeAmount'=>null], 'manual_review'],
    ];
    foreach ($feeCases as [$label, $paid, $patch, $expected]) {
        Attempt::$db=[1=>['state'=>'quoted','invoice_id'=>'INV-1','txn_id'=>'TXN-1']];
        Payment::$status='pending'; Payment::$deliveries=0;
        $GLOBALS['api']->invoicePatch=['payedAmount'=>$paid, 'feePayByUser'=>false];
        $GLOBALS['api']->transactionPatch=array_replace(['currencyCode'=>'USD','amount'=>'10.00000000','feeAmount'=>'0.10000000','netAmount'=>'9.90000000','feePayByUser'=>false], $patch);
        check($GLOBALS['reconciler']->verify(new Attempt)===$expected, $label);
        check(Payment::$deliveries===0, 'Fee verification does not itself deliver');
    }
    echo "Independent-review regression assertions passed with deterministic fakes (not an integration test).\n";
}
