@extends('layouts.app')
@section('title', 'JangoPay')
@section('content')
<div class="container py-4" style="max-width:780px">
    <div class="card shadow-sm"><div class="card-body">
        <h1 class="h4">JangoPay</h1>
        <p class="text-muted">@lang('jangopay::messages.pay_with')</p>
        <div class="fs-4 mb-2">USD {{ $attempt->amount_usd }}</div>
        @if($attempt->state === 'delivered')
            <div class="alert alert-success">@lang('jangopay::messages.paid')</div>
        @elseif(in_array($attempt->state, ['delivery_attention', 'manual_review']))
            <div class="alert alert-warning">@lang('jangopay::messages.attention')</div>
        @elseif($attempt->state === 'awaiting_payment')
            <p>@lang('jangopay::messages.choose')</p>
            <form method="POST" action="{{ route('jangopay.payment.coin', ['attempt' => $attempt->id, 'token' => $token]) }}">
                @csrf
                <label for="asset" class="form-label">@lang('jangopay::messages.network')</label>
                <select name="asset" id="asset" class="form-select mb-3">
                    @foreach($assets as $asset)<option value="{{ $asset }}">{{ $asset }}</option>@endforeach
                </select>
                <button type="submit" class="btn btn-primary">@lang('jangopay::messages.confirm')</button>
            </form>
        @elseif($attempt->state === 'quoted')
            <div class="alert alert-info">@lang('jangopay::messages.pending')</div>
            <dl class="row">
                <dt class="col-sm-4">@lang('jangopay::messages.amount')</dt><dd class="col-sm-8 fs-5">{{ $attempt->quote_amount }} {{ $attempt->asset }}</dd>
                <dt class="col-sm-4">@lang('jangopay::messages.network')</dt><dd class="col-sm-8">{{ $attempt->chain }}</dd>
                <dt class="col-sm-4">@lang('jangopay::messages.address')</dt><dd class="col-sm-8 text-break"><code>{{ $attempt->receive_address }}</code></dd>
            </dl>
            <p class="text-warning">@lang('jangopay::messages.no_reuse')</p>
        @else
            <div class="alert alert-warning">@lang('jangopay::messages.processing')</div>
        @endif
        <p class="small text-muted">JangoPay invoice: {{ $attempt->invoice_id ?? 'Not confirmed' }}</p>
        <a class="btn btn-outline-secondary" href="{{ request()->fullUrl() }}">@lang('jangopay::messages.refresh')</a>
    </div></div>
</div>
@endsection
