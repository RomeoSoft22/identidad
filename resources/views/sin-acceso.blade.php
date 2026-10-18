@extends('layout')

@section('titulo', 'Sin acceso')

@section('contenido')
    <h1>No tienes acceso a {{ $sistema->nombre }}</h1>
    <p>Tu cuenta <strong>{{ $user->email }}</strong> no está matriculada en este sistema, o su matrícula no está vigente. Pide al administrador que te matricule.</p>
    <a class="boton" href="{{ route('logout') }}">Ingresar con otra cuenta</a>
@endsection
