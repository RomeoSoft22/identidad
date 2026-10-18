@extends('layout')

@section('titulo', 'Iniciar sesión')

@section('contenido')
    @if ($sesionActiva)
        <h1>Ya iniciaste sesión</h1>
        <p>Estás conectado como <strong>{{ $sesionActiva->email }}</strong>. Vuelve al sistema desde el que llegaste para continuar.</p>
        <a class="boton" href="{{ route('logout') }}">Cerrar sesión</a>
    @else
        <h1>Iniciar sesión</h1>
        <p>Usa tu cuenta única de Juarez Campos para entrar a cualquiera de sus sistemas.</p>

        <form method="post" action="{{ route('login.store') }}" novalidate>
            @csrf
            <label for="email">Correo</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            @if ($errors->any())
                <div class="error" role="alert">{{ $errors->first() }}</div>
            @endif

            <button type="submit">Ingresar</button>
        </form>
    @endif
    <div class="pie">identidad.juarezcampos.net</div>
@endsection
