<?php

namespace App\Support;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Illuminate\Http\Request;

/**
 * Datos de seguridad de quien hace la petición: IP, IP reenviada por proxies y el dispositivo
 * (navegador, sistema operativo y tipo de equipo) deducido del user-agent.
 */
class ContextoCliente
{
    /** @var array<string, array> */
    private static array $cache = [];

    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $ipReenviada,
        public readonly ?string $userAgent,
        public readonly ?string $navegador,
        public readonly ?string $navegadorVersion,
        public readonly ?string $sistemaOperativo,
        public readonly ?string $soVersion,
        public readonly ?string $tipo,
        public readonly ?string $metodoRuta,
    ) {}

    public static function actual(): self
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return $request ? self::desde($request) : new self(null, null, null, null, null, null, null, 'consola', 'artisan');
    }

    public static function desde(Request $request): self
    {
        $ua = substr((string) $request->userAgent(), 0, 1000) ?: null;
        $d = $ua ? self::analizar($ua, $request->server->all()) : [];

        return new self(
            ip: $request->ip(),
            ipReenviada: substr((string) $request->header('X-Forwarded-For'), 0, 255) ?: null,
            userAgent: $ua,
            navegador: $d['navegador'] ?? null,
            navegadorVersion: $d['navegador_version'] ?? null,
            sistemaOperativo: $d['sistema_operativo'] ?? null,
            soVersion: $d['so_version'] ?? null,
            tipo: $d['tipo'] ?? null,
            metodoRuta: substr($request->method().' /'.ltrim($request->path(), '/'), 0, 120),
        );
    }

    /** Identifica la combinación usuario + navegador/equipo sin guardar datos extra del cliente. */
    public function huella(int $usuarioId): string
    {
        return hash('sha256', $usuarioId.'|'.($this->userAgent ?? ''));
    }

    private static function analizar(string $ua, array $servidor): array
    {
        if (isset(self::$cache[$ua])) {
            return self::$cache[$ua];
        }

        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_MINOR);
        $dd = new DeviceDetector($ua, ClientHints::factory($servidor));
        $dd->discardBotInformation();
        $dd->parse();

        $tipo = match (true) {
            $dd->isBot() => 'bot',
            $dd->isTablet() => 'tablet',
            $dd->isMobile() => 'movil',
            $dd->isDesktop() => 'escritorio',
            default => 'otro',
        };
        $vacio = fn ($v) => ($v === '' || $v === 'UNK' || $v === null) ? null : substr((string) $v, 0, 60);

        if (count(self::$cache) > 50) {
            self::$cache = [];
        }

        return self::$cache[$ua] = [
            'navegador' => $vacio($dd->getClient('name')),
            'navegador_version' => $vacio($dd->getClient('version')),
            'sistema_operativo' => $vacio($dd->getOs('name')),
            'so_version' => $vacio($dd->getOs('version')),
            'tipo' => $tipo,
        ];
    }
}
