@extends('layout')

@section('titulo', 'Sesión cerrada')

@section('contenido')
    <h1>Cerraste sesión</h1>
    <p>Tu sesión se cerró en todos los sistemas de Juarez Campos.</p>
    <a class="boton" href="{{ route('login') }}">Volver a iniciar sesión</a>
@endsection
