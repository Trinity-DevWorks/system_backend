@extends('document-pdf.layout')

@section('body')
    @include('document-pdf.partials.lines')
    @include('document-pdf.partials.totals')
@endsection
