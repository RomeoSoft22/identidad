<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso a los administradores del panel: alguien pidió acceso entrando con Google. */
class SolicitudAcceso extends Mailable
{
    public function __construct(
        public readonly string $nombre,
        public readonly string $correo,
        public readonly int $usuarioId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nueva solicitud de acceso: {$this->correo}");
    }

    public function content(): Content
    {
        $e = fn (?string $v) => e((string) $v);
        $panel = rtrim((string) config('identidad.panel_url'), '/');
        $enlace = $panel !== '' ? "{$panel}/usuarios/{$this->usuarioId}" : null;
        $boton = $enlace
            ? "<p><a href=\"{$e($enlace)}\" style=\"display:inline-block;background:#037fff;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none\">Revisar en el panel</a></p>"
            : '<p>Revísala en el panel, en Usuarios → Pendientes.</p>';

        return new Content(htmlString: <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1f2937">
          <p><strong>{$e($this->nombre)}</strong> ({$e($this->correo)}) ingresó con su cuenta de Google y pidió acceso a los sistemas de Juarez Campos.</p>
          <p>No podrá entrar a ningún sistema hasta que apruebes la solicitud y le asignes los sistemas y roles.</p>
          {$boton}
        </div>
        HTML);
    }
}
