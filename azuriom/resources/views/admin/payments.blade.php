@extends('admin.layouts.admin')
@section('title', 'JangoPay • Payment review')
@section('content')
<div class="card mb-4"><div class="card-body">
    <form action="{{ route('jangopay.admin.test') }}" method="post">@csrf
        <button class="btn btn-outline-primary" type="submit">Test API connection (read-only)</button>
    </form>
</div></div>
<div class="card"><div class="card-body table-responsive">
<table class="table table-sm">
<thead><tr><th>Attempt</th><th>Shop payment</th><th>Invoice</th><th>USD</th><th>State</th><th>Last issue</th><th>Action</th></tr></thead>
<tbody>
@foreach($attempts as $attempt)
<tr>
<td>{{ $attempt->id }}</td><td>{{ $attempt->payment_id }}</td>
<td class="text-break">{{ $attempt->invoice_id ?? 'Not confirmed' }}</td>
<td>{{ $attempt->amount_usd }}</td><td>{{ $attempt->state }}</td>
<td>{{ $attempt->failure_code ?? '—' }}</td>
<td><form method="post" action="{{ route('jangopay.admin.payments.reverify', $attempt) }}">@csrf
<button class="btn btn-outline-secondary btn-sm" type="submit">Reverify</button></form></td>
</tr>
@endforeach
</tbody></table>{{ $attempts->links() }}
</div></div>
@endsection
