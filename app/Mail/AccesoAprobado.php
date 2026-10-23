<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso al usuario: un administrador aprobó su solicitud de acceso. */
class AccesoAprobado extends Mailable
{
    /** @param list<string> $sistemas */
    public function __construct(
        public readonly string $nombre,
        public readonly array $sistemas,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu acceso a Juarez Campos fue aprobado');
    }

    public function content(): Content
    {
        $e = fn (?string $v) => e((string) $v);
        $lista = $this->sistemas === []
            ? '<p>Todavía no tienes sistemas asignados; el administrador te los dará en breve.</p>'
            : '<p>Ya puedes ingresar a:</p><ul>'.implode('', array_map(fn ($s) => '<li>'.$e($s).'</li>', $this->sistemas)).'</ul>';
        $login = rtrim((string) config('identidad.issuer'), '/').'/login';

        return new Content(htmlString: <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1f2937">
          <p>Hola {$e($this->nombre)}:</p>
          <p>Un administrador aprobó tu solicitud de acceso. Ingresa con el botón <strong>Continuar con Google</strong>.</p>
          {$lista}
          <p><a href="{$e($login)}">{$e($login)}</a></p>
        </div>
        HTML);
    }
}
