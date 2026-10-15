-- ============================================================
-- Oficia Clock · REPARACIÓN de claves primarias perdidas
--
-- En la BD de producción estas tablas no tienen clave primaria ni
-- AUTO_INCREMENT, así que se guardan filas con id = 0 repetido:
--   breaks, user_settings, fichajes_resumen_diario, sync_log, geolocation_settings
-- Consecuencias: al cerrar un descanso se pueden cerrar los de otros
-- empleados (todos tienen id 0), los ajustes del empleado y el resumen
-- diario se duplican en vez de actualizarse.
--
-- Este script: renumera los id, quita duplicados (en ajustes y resumen
-- diario se queda la fila más reciente) y crea las claves.
-- Se puede ejecutar varias veces: si una tabla ya está bien, no la toca.
-- HAZ UNA COPIA DE SEGURIDAD DE LA BD ANTES DE EJECUTARLO.
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS oc_reparar_claves $$
CREATE PROCEDURE oc_reparar_claves()
BEGIN
    -- breaks
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breaks' AND CONSTRAINT_TYPE = 'PRIMARY KEY') THEN
        SET @n := 0;
        UPDATE breaks SET id = (@n := @n + 1) ORDER BY (id = 0), id, inicio;
        ALTER TABLE breaks ADD PRIMARY KEY (id), MODIFY id INT(11) NOT NULL AUTO_INCREMENT;
        ALTER TABLE breaks ADD KEY idx_usuario_inicio (usuario_id, inicio);
    END IF;

    -- sync_log
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_log' AND CONSTRAINT_TYPE = 'PRIMARY KEY') THEN
        SET @n := 0;
        UPDATE sync_log SET id = (@n := @n + 1) ORDER BY (id = 0), id, fecha;
        ALTER TABLE sync_log ADD PRIMARY KEY (id), MODIFY id INT(11) NOT NULL AUTO_INCREMENT;
    END IF;

    -- user_settings: un valor por empleado y ajuste
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_settings' AND CONSTRAINT_TYPE = 'PRIMARY KEY') THEN
        SET @n := 0;
        UPDATE user_settings SET id = (@n := @n + 1) ORDER BY (id = 0), id;
        DELETE a FROM user_settings a JOIN user_settings b
            ON a.usuario_id = b.usuario_id AND a.setting_key = b.setting_key AND a.id < b.id;
        ALTER TABLE user_settings ADD PRIMARY KEY (id), MODIFY id INT(11) NOT NULL AUTO_INCREMENT;
        IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_settings' AND NON_UNIQUE = 0 AND COLUMN_NAME = 'setting_key') THEN
            ALTER TABLE user_settings ADD UNIQUE KEY uq_usuario_clave (usuario_id, setting_key);
        END IF;
    END IF;

    -- fichajes_resumen_diario: una fila por empleado y día (es una caché que se recalcula)
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fichajes_resumen_diario' AND CONSTRAINT_TYPE = 'PRIMARY KEY') THEN
        SET @n := 0;
        UPDATE fichajes_resumen_diario SET id = (@n := @n + 1) ORDER BY (id = 0), id;
        DELETE a FROM fichajes_resumen_diario a JOIN fichajes_resumen_diario b
            ON a.usuario_id = b.usuario_id AND a.fecha = b.fecha AND a.id < b.id;
        ALTER TABLE fichajes_resumen_diario ADD PRIMARY KEY (id), MODIFY id INT(11) NOT NULL AUTO_INCREMENT;
        IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fichajes_resumen_diario' AND NON_UNIQUE = 0 AND COLUMN_NAME = 'fecha') THEN
            ALTER TABLE fichajes_resumen_diario ADD UNIQUE KEY uq_usuario_fecha (usuario_id, fecha);
        END IF;
    END IF;

    -- geolocation_settings: una sola fila con id = 1
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'geolocation_settings' AND CONSTRAINT_TYPE = 'PRIMARY KEY') THEN
        DELETE FROM geolocation_settings WHERE id <> 1;
        ALTER TABLE geolocation_settings ADD PRIMARY KEY (id);
    END IF;
END $$

DELIMITER ;

CALL oc_reparar_claves();
DROP PROCEDURE IF EXISTS oc_reparar_claves;
