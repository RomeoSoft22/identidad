-- juarezc_panel: ingreso con Google y aprobación de solicitudes desde el panel.
-- Ejecutar UNA vez en phpMyAdmin (base juarezc_panel → SQL). Equivale a: php artisan migrate --force
ALTER TABLE `usuarios`
  ADD COLUMN `google_sub` VARCHAR(64) NULL AFTER `correo`,
  ADD COLUMN `origen` VARCHAR(20) NOT NULL DEFAULT 'panel' AFTER `google_sub`,
  ADD COLUMN `aprobado_por` BIGINT UNSIGNED NULL AFTER `creado_por`,
  ADD COLUMN `aprobado_en` TIMESTAMP NULL AFTER `aprobado_por`,
  ADD UNIQUE KEY `usuarios_google_sub_unique` (`google_sub`),
  ADD CONSTRAINT `usuarios_aprobado_por_foreign` FOREIGN KEY (`aprobado_por`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL;

INSERT INTO `migraciones` (`migration`, `batch`)
SELECT '2026_10_23_120000_agregar_google_y_aprobacion_a_usuarios', COALESCE(MAX(`batch`), 0) + 1 FROM `migraciones`;
