<?php
// Standalone simulation of stale session write-back and route registration.
// This reuses deterministic fakes; NOT a real Laravel HTTP/session integration test.
namespace Illuminate\Routing {
    class Route {
        public ?int $lockedFor = null;
        public ?int $waitFor = null;
        public function __construct(private string $name) {}
        public function getName() { return $this->name; }
        public function block(int $seconds, int $waitSeconds): self {
            $this->lockedFor = $seconds;
            $this->waitFor = $waitSeconds;
            return $this;
        }
    }
}
namespace {
    require __DIR__.'/codex_regressions.php';
    require __DIR__.'/../src/Support/ShopSessionBlocking.php';

    use Azuriom\Plugin\JangoPay\JangoPayMethod;
    use Azuriom\Plugin\JangoPay\Models\Attempt;
    use Azuriom\Plugin\JangoPay\Services\JangoPayApi;
    use Azuriom\Plugin\JangoPay\Support\CheckoutAccess;
    use Azuriom\Plugin\JangoPay\Support\ShopSessionBlocking;
    use Azuriom\Plugin\Shop\Models\Gateway;
    use Azuriom\Plugin\Shop\Cart\Cart;

    $GLOBALS['testSessionDriver']='file';
    $GLOBALS['api']=new JangoPayApi();
    $method=new JangoPayMethod(new Gateway);
    Attempt::$db=[];
    $firstSession=new SessionFake();
    $firstSession->put('shop.user',7);
    $firstSession->put('shop.cart',['items'=>[['id'=>1]], 'coupons'=>[], 'giftcards'=>[]]);
    $initial=$firstSession->data;

    $GLOBALS['session']=$firstSession;
    $first=$method->startPayment(new Cart,10.00,'USD');
    check($first->route==='jangopay.payment.show' && strlen($first->params['token'])===64,
        'Winner receives an authorized payment URL');
    $firstToken=$first->params['token'];
    check(! array_key_exists('shop.cart', $firstSession->data), 'Winner clears the cart snapshot');
    check(! array_key_exists('jangopay_access_'.array_values(Attempt::$db)[0]['checkout_key'], $firstSession->data),
        'New checkout does not store its access token solely in the session');

    // Exact user-reported failure model: after A has saved its session,
    // another stale request writes the INITIAL snapshot back LAST.
    $lateSnapshot=new SessionFake();
    $lateSnapshot->data=$initial;
    $GLOBALS['session']=$lateSnapshot;
    check(isset($lateSnapshot->data['shop.cart']) && ! isset($lateSnapshot->data['jangopay_checkout_nonce']),
        'Simulated stale request restores old cart and nonce');
    $recovered=$method->startPayment(new Cart,10.00,'USD');
    check($recovered->route==='jangopay.payment.show' && $recovered->params['token']===$firstToken,
        'Retry securely recovers the identical order capability after stale session overwrite');
    check(count(Attempt::$db)===1 && $GLOBALS['api']->createdInvoices===1,
        'Recovered retry does not create a second invoice');
    check(! array_key_exists('shop.cart', $lateSnapshot->data),
        'Recovered retry clears the resurrected stale shopping cart');

    // Two concurrent requests can each receive a valid redirect from the same snapshot,
    // whether or not the session lock is actually operating in the fake harness.
    Attempt::$db=[];
    $GLOBALS['api']->createdInvoices=0;
    $a=new SessionFake();$a->data=$initial;
    $b=new SessionFake();$b->data=$initial;
    $GLOBALS['session']=$a;$resA=$method->startPayment(new Cart,10.00,'USD');
    $GLOBALS['session']=$b;$resB=$method->startPayment(new Cart,10.00,'USD');
    check($resA->route==='jangopay.payment.show' && $resB->route==='jangopay.payment.show'
        && $resA->params['token']===$resB->params['token'],
        'Identical initial requests reuse the same authorized payment URL');
    check(count(Attempt::$db)===1 && $GLOBALS['api']->createdInvoices===1,
        'Duplicate initial checkout remains a single local payment and remote invoice');

    // Route name checked BEFORE StartSession's middleware saves the session.
    foreach (['shop.payments.payment','shop.payments.pay','shop.offers.pay','shop.offers.buy'] as $name) {
        $route=new \Illuminate\Routing\Route($name);ShopSessionBlocking::apply($route);
        check($route->lockedFor===120 && $route->waitFor===45, 'Shop entrypoint protected: '.$name);
    }
    $other=new \Illuminate\Routing\Route('shop.cart.index');ShopSessionBlocking::apply($other);
    check($other->lockedFor===null, 'Unrelated routes are not modified');

    // Migrating from 1.0.2: preserve access to an existing random capability
    // when the original session token is still available. Do not replace its hash.
    $legacySession=new SessionFake();
    $legacySession->data=$initial;
    $GLOBALS['session']=$legacySession;
    $fingerprint=\Azuriom\Plugin\JangoPay\Support\CheckoutFingerprint::make(
        (new Cart)->toArray(), '10.00', 'USD');
    $legacyKey=hash('sha256', $legacySession->getId().'|'.str_repeat('0',32).'|'
        .$fingerprint.'|1|7');
    $legacyToken=str_repeat('b',64);
    Attempt::$db=[1=>[
        'checkout_key'=>$legacyKey,
        'access_hash'=>hash('sha256',$legacyToken),
        'state'=>'awaiting_payment','invoice_id'=>'INV-LEGACY',
    ]];
    $legacySession->put('jangopay_access_'.$legacyKey,$legacyToken);
    $resLegacy=$method->startPayment(new Cart,10.00,'USD');
    check($resLegacy->route==='jangopay.payment.show'
        && $resLegacy->params['token']===$legacyToken,
        'Preexisting 1.0.2 random session capability remains valid after upgrade');
    $missingLegacy=new SessionFake(); $missingLegacy->data=$initial;
    $GLOBALS['session']=$missingLegacy;
    $resDenied=$method->startPayment(new Cart,10.00,'USD');
    check(!isset($resDenied->redirect),
        'Lost legacy capability is NOT reconstructed from a guess or new hash');

    $GLOBALS['testSessionDriver']='cookie';
    $blocked=$method->startPayment(new Cart,10.00,'USD');
    check(!isset($blocked->redirect), 'Cookie session driver is rejected before invoice creation');
    $GLOBALS['testSessionDriver']='array';
    $blocked=$method->startPayment(new Cart,10.00,'USD');
    check(!isset($blocked->redirect), 'Nonpersistent array session driver is rejected');
    unset($GLOBALS['testSessionDriver']);
    $GLOBALS['testCacheDriver']='array';
    $blocked=$method->startPayment(new Cart,10.00,'USD');
    check(!isset($blocked->redirect), 'Process-local array cache lock cannot enable collections');
    $unsafeRoute=new \Illuminate\Routing\Route('shop.payments.pay');
    ShopSessionBlocking::apply($unsafeRoute);
    check($unsafeRoute->lockedFor===null, 'Unsafe cache does not change unrelated Shop payment routes');
    unset($GLOBALS['testCacheDriver']);

    $key=str_repeat('a',64);
    check(strlen(CheckoutAccess::derive($key))===64, 'HMAC access capability has 256-bit hex output');
    $GLOBALS['testAppKey']='weak';
    try { CheckoutAccess::derive($key); throw new \RuntimeException('HMAC must reject weak server key'); }
    catch (\RuntimeException $e) {
        if ($e->getMessage()==='HMAC must reject weak server key') throw $e;
    }
    check(true, 'Weak server APP_KEY rejected');
    unset($GLOBALS['testAppKey']);

    echo "Session stale-write recovery and route-lock simulation tests passed (not HTTP integration).\n";
}
