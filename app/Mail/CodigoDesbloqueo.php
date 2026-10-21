<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo con el código de 6 dígitos para desbloquear la cuenta. */
class CodigoDesbloqueo extends Mailable
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $nombre,
        public readonly int $minutos,
        public readonly ?string $ip,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu código de verificación: {$this->codigo}");
    }

    public function content(): Content
    {
        $e = fn (?string $v) => e((string) $v);

        $html = <<<HTML
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;color:#1f2937">
          <p>Hola {$e($this->nombre)}:</p>
          <p>Recibimos un ingreso a tu cuenta de Juarez Campos que necesita verificación. Escribe este código en la pantalla de ingreso:</p>
          <p style="font-size:32px;letter-spacing:8px;font-weight:bold;text-align:center;background:#f3f4f6;border-radius:8px;padding:16px">{$e($this->codigo)}</p>
          <p>Vence en {$this->minutos} minutos y solo sirve una vez.</p>
          <p style="color:#6b7280;font-size:13px">Solicitado desde la IP {$e($this->ip)}. Si no fuiste tú, no compartas este código y avisa al administrador: alguien conoce tu contraseña.</p>
        </div>
        HTML;

        return new Content(htmlString: $html);
    }
}
