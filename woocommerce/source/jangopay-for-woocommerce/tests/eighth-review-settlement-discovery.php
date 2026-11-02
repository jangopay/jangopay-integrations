<?php
/**
 * 1.0.9 missing-webhook recovery with real plugin controller/API/ledger,
 * isolated WordPress/WooCommerce and merchant HTTP doubles. Not a live payment.
 */
require __DIR__.'/fixtures/review-bootstrap.php';
function wp_parse_url($value) {return parse_url($value);}
$cases=[
 'linked_large_account', 'linked_expired_then_settled', 'linked_duplicate_settled',
 'linked_invalid_then_valid', 'linked_unverifiable_only', 'linked_invalid_identity',
 'linked_pending_only', 'linked_stale_expired', 'fallback_expired_then_settled', 'fallback_incomplete',
 'fallback_single', 'fallback_missing_pagination', 'pending_no_search', 'linked_duplicate_id'
];
foreach($cases as $index=>$case) {
    $id=$index+20;
    $order=new TestOrder();$order->id=$id;$GLOBALS['orders'][$id]=$order;
    $invoiceId='I'.$id; $settled='T'.$id;
    $other='T-OTHER-'.$id; $expired='T-EXPIRED-'.$id;
    $GLOBALS['reviewTransport']=static function($path,$args)use($invoiceId) {
        if($path==='/api/v1/invoice/create')return ['status'=>200,'body'=>json_encode(['invoice'=>['invoiceId'=>$invoiceId]])];
        return null;
    };
    $created=(new JPWC_Gateway())->process_payment($id);
    verify('created hosted invoice '.$case,($created['redirect']??'')==='https://app.jangopay.org/pay/'.$invoiceId);
    $row=JPWC_Ledger::get($id);
    $GLOBALS['pages_'.$case]=0;
    $GLOBALS['reviewTransport']=static function($path,$args)use($case,$row,$invoiceId,$settled,$other,$expired){
        $body=static fn($o)=>['status'=>200,'body'=>json_encode($o)];
        if($path==='/api/v1/invoice/'.$invoiceId) {
            $details=['invoice'=>['invoiceId'=>$invoiceId,'orderId'=>$row->merchant_order_id,
                'currencyCode'=>'USD','amount'=>'3.00','status'=>$case==='pending_no_search'?'pending':'paid',
                'payedAmount'=>$case==='pending_no_search'?'0':'2.97000000','feePayByUser'=>false]];
            if($case==='linked_large_account')$details['transactions']=[['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']];
            elseif($case==='linked_expired_then_settled')$details['transactions']=[['txnId'=>$expired,'invoiceId'=>$invoiceId,'status'=>'expired'],['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']];
            elseif($case==='linked_duplicate_settled')$details['transactions']=[['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled'],['txnId'=>$other,'invoiceId'=>$invoiceId,'status'=>'settled']];
            elseif($case==='linked_invalid_then_valid')$details['transactions']=[['txnId'=>$other,'invoiceId'=>$invoiceId,'status'=>'settled'],['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']];
            elseif($case==='linked_unverifiable_only')$details['transactions']=[['txnId'=>$other,'invoiceId'=>$invoiceId,'status'=>'settled']];
            elseif($case==='linked_invalid_identity')$details['transactions']=[['txnId'=>$settled,'invoiceId'=>'I-WRONG','status'=>'settled']];
            elseif($case==='linked_pending_only')$details['transactions']=[['txnId'=>$expired,'invoiceId'=>$invoiceId,'status'=>'expired']];
            elseif($case==='linked_stale_expired')$details['transactions']=[['txnId'=>$expired,'invoiceId'=>$invoiceId,'status'=>'expired']];
            elseif($case==='linked_duplicate_id')$details['transactions']=[['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled'],['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']];
            return $body($details);
        }
        if($path==='/api/v1/invoice/transactions') {
            $GLOBALS['pages_'.$case]++;
            $page=$GLOBALS['pages_'.$case];
            if($case==='fallback_incomplete') {
                $records=[];
                for($i=0;$i<100;$i++)$records[]=['txnId'=>'T-OTHER-'.$page.'-'.$i,'invoiceId'=>'I-OTHER-'.$page.'-'.$i,'status'=>'settled'];
                if($page===1)$records[0]=['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled'];
                return $body(['transactions'=>$records,'totalPages'=>6]);
            }
            if($case==='fallback_expired_then_settled')return $body(['transactions'=>[
                ['txnId'=>$expired,'invoiceId'=>$invoiceId,'status'=>'expired'],
                ['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']],'totalPages'=>1]);
            if($case==='fallback_single')return $body(['transactions'=>[['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']],'totalPages'=>1]);
            if($case==='fallback_missing_pagination')return $body(['transactions'=>[['txnId'=>$settled,'invoiceId'=>$invoiceId,'status'=>'settled']]]);
            throw new RuntimeException('Unexpected transaction list for '.$case);
        }
        if(str_starts_with($path,'/api/v1/invoice/transaction/')) {
            $thisId=substr($path,strlen('/api/v1/invoice/transaction/'));
            $bad=$thisId===$other && $case!=='linked_duplicate_settled';
            return $body(['transaction'=>['txnId'=>$thisId,'invoiceId'=>$invoiceId,
                'status'=>$thisId===$expired && $case!=='linked_stale_expired'?'expired':'settled',
                'payCurrency'=>$bad?'DAI':'USDT','chain'=>'tron',
                'currencyCode'=>'USD','amount'=>'3.00000000',
                'feeAmount'=>'0.03000000','netAmount'=>'2.97000000','feePayByUser'=>false]]);
        }
        return null;
    };
    $state=null;$err=null;
    try {$state=JPWC_Controller::verifyRow($row);} catch(Throwable $e){$err=$e->getMessage();}
    $paid=$order->is_paid();$deliveries=$order->delivery;
    $latest=JPWC_Ledger::get($id);
    if(in_array($case,['linked_large_account','linked_expired_then_settled','linked_invalid_then_valid',
                        'fallback_expired_then_settled','fallback_single','linked_duplicate_id','linked_stale_expired'],true)) {
        verify($case.' completes once',$state==='completed'&&$err===null&&$paid&&$deliveries===1&&$latest->txn_id===($case==='linked_stale_expired'?$expired:$settled));
        verify($case.' has no repeat delivery',JPWC_Controller::verifyRow($latest)==='completed'&&$order->delivery===1);
    }elseif(in_array($case,['linked_duplicate_settled','linked_unverifiable_only','linked_invalid_identity'],true)) {
        verify($case.' quarantined',$state==='manual_review'&&!$paid&&$deliveries===0&&$latest->state==='manual_review');
    }elseif($case==='fallback_missing_pagination') {
        verify('missing pagination is not mistaken for complete scan',$err==='remote_pagination_unverified'&&!$paid&&$deliveries===0&&$latest->state==='awaiting_payment');
    }elseif($case==='fallback_incomplete') {
        verify('unbounded account scan fails closed',$err==='remote_search_incomplete'&&!$paid&&$deliveries===0&&$latest->state==='awaiting_payment');
        verify('bounded account scan attempted five pages',$GLOBALS['pages_'.$case]===5);
    } else {
        verify($case.' remains pending',$state==='pending'&&!$paid&&$deliveries===0&&$latest->state==='awaiting_payment');
    }
    if(str_starts_with($case,'linked_') || $case==='pending_no_search')
        verify($case.' does not scan account transactions',($GLOBALS['pages_'.$case]??0)===0);
}
echo "NOTE: PHP controller/merchant transport/ledger doubles, not native WP+MariaDB or live Hosted Checkout.\n";
