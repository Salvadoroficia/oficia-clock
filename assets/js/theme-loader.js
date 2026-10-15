// ============================================
// THEME LOADER INSTANTÁNEO - Sistema Híbrido
// Se ejecuta ANTES del render para evitar FOUC
// ============================================
(function() {
    console.log('🎨 Theme Loader Híbrido iniciado');
    
    // Función para aplicar un tema
    function applyTheme(theme) {
        if (!theme) return;
        
        console.log('📦 Aplicando tema:', theme);
        
        // Aplicar todas las variables CSS
        for (const [key, value] of Object.entries(theme)) {
            if (key === '_theme_mode') continue;
            document.documentElement.style.setProperty(`--${key}`, value);
        }
        
        // Aplicar data-theme si existe
        if (theme['_theme_mode']) {
            document.documentElement.setAttribute('data-theme', theme['_theme_mode']);
            console.log(`✅ data-theme="${theme['_theme_mode']}" aplicado`);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        
        // Actualizar gradientes compuestos
        if (theme['bg-grad-start'] && theme['bg-grad-end']) {
            const bgGradient = `linear-gradient(135deg, ${theme['bg-grad-start']} 0%, ${theme['bg-grad-end']} 100%)`;
            document.documentElement.style.setProperty('--bg-gradient', bgGradient);
        }
        
        if (theme['bg-admin-start'] && theme['bg-admin-end']) {
            const adminGradient = `linear-gradient(135deg, ${theme['bg-admin-start']} 0%, ${theme['bg-admin-end']} 100%)`;
            document.documentElement.style.setProperty('--bg-admin-gradient', adminGradient);
        }
    }
    
    // Cargar temas en cascada (corporativo -> personal)
    async function loadThemes() {
        try {
            // 1. Verificar si ya se aplicó desde localStorage
            const localTheme = localStorage.getItem('customTheme');
            if (localTheme) {
                console.log('✅ Tema ya aplicado desde inline script');
                // Sincronizar con servidor en background
                syncWithServer();
                return;
            }
            
            // 2. Si no hay tema local, cargar tema corporativo
            console.log('🏢 Cargando tema corporativo...');
            const corpResponse = await fetch('api.php?action=get_corporate_theme');
            
            if (corpResponse.ok) {
                const corpTheme = await corpResponse.json();
                if (corpTheme) {
                    console.log('✅ Tema corporativo cargado');
                    applyTheme(corpTheme);
                }
            }
            
            // 3. Verificar si hay tema personal en servidor
            const userResponse = await fetch('api.php?action=get_user_theme');
            if (userResponse.ok) {
                const userTheme = await userResponse.json();
                if (userTheme) {
                    console.log('✅ Tema personal cargado desde servidor');
                    applyTheme(userTheme);
                    // Guardar en localStorage para próxima carga
                    localStorage.setItem('customTheme', JSON.stringify(userTheme));
                } else {
                    console.log('ℹ️ Usuario usa tema corporativo');
                }
            }
            
            console.log('🎨 Carga de temas completada');
            
        } catch (error) {
            console.error('❌ Error al cargar temas:', error);
            console.log('ℹ️ Usando tema por defecto del CSS');
        }
    }
    
    // Sincronizar tema local con servidor (background)
    async function syncWithServer() {
        try {
            const userResponse = await fetch('api.php?action=get_user_theme');
            if (userResponse.ok) {
                const serverTheme = await userResponse.json();
                const localTheme = localStorage.getItem('customTheme');
                
                // Si el servidor tiene un tema diferente, actualizar
                if (JSON.stringify(serverTheme) !== localTheme) {
                    console.log('🔄 Actualizando tema desde servidor');
                    if (serverTheme) {
                        localStorage.setItem('customTheme', JSON.stringify(serverTheme));
                        applyTheme(serverTheme);
                    } else {
                        // El servidor no tiene tema personal: NO hacer reload
                        // (podría destruir sesión admin o interrumpir la página)
                        console.log('ℹ️ Sin tema personal en servidor, manteniendo localStorage');
                    }
                }
            }
        } catch (error) {
            console.error('❌ Error al sincronizar:', error);
        }
    }
    
    // Ejecutar al cargar la página
    loadThemes();
    
    // Exponer función globalmente
    window.reloadTheme = loadThemes;
})();